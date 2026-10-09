import type { BuildLogEntry } from './types'

export interface SseFrame {
  event: string
  data: string
}

/**
 * Parses an SSE buffer into complete frames. Everything after the last
 * separator is returned as `rest` to be combined with the next chunk.
 */
export function parseSseBuffer(buffer: string): { frames: SseFrame[]; rest: string } {
  const frames: SseFrame[] = []
  const parts = buffer.split('\n\n')
  const rest = parts.pop() ?? ''

  for (const part of parts) {
    if (!part.trim()) continue
    let event = 'message'
    const dataLines: string[] = []

    for (const rawLine of part.split('\n')) {
      const line = rawLine.replace(/\r$/, '')
      if (line.startsWith(':')) continue
      if (line.startsWith('event:')) event = line.slice(6).trim()
      else if (line.startsWith('data:')) dataLines.push(line.slice(5).trimStart())
    }

    // Frames without a data line (comments/pings) carry no payload.
    if (dataLines.length === 0) continue

    frames.push({ event, data: dataLines.join('\n') })
  }

  return { frames, rest }
}

export type ConnectionState = 'connecting' | 'connected' | 'reconnecting' | 'closed'

export interface StatusUpdate {
  status: string
  status_label?: string
  exit_code?: number | null
  error_message?: string | null
  finished_at?: string | null
}

export interface LogStreamHandlers {
  onLogs: (logs: BuildLogEntry[]) => void
  onStatus: (status: StatusUpdate) => void
  onEnd: (status: string | null) => void
  onConnection: (state: ConnectionState) => void
}

export interface LogStreamHandle {
  close: () => void
}

/**
 * Opens an authenticated SSE stream using fetch (EventSource cannot send
 * headers). Reconnects automatically from the last received log id.
 */
export function openLogStream(
  buildId: number,
  afterId: number,
  handlers: LogStreamHandlers,
): LogStreamHandle {
  let closed = false
  let lastId = afterId
  let controller: AbortController | null = null
  let retryDelay = 1000

  const connect = async () => {
    if (closed) return

    controller = new AbortController()
    handlers.onConnection(retryDelay > 1000 ? 'reconnecting' : 'connecting')

    try {
      const response = await fetch(
        `/api/v1/builds/${buildId}/logs/stream?after_id=${lastId}`,
        {
          headers: { Accept: 'text/event-stream' },
          credentials: 'same-origin',
          cache: 'no-store',
          signal: controller.signal,
        },
      )

      if (!response.ok || !response.body) {
        throw new Error(`stream status ${response.status}`)
      }

      handlers.onConnection('connected')
      retryDelay = 1000

      const reader = response.body.getReader()
      const decoder = new TextDecoder()
      let buffer = ''

      for (;;) {
        const { done, value } = await reader.read()
        if (done) break

        buffer += decoder.decode(value, { stream: true })
        const { frames, rest } = parseSseBuffer(buffer)
        buffer = rest

        for (const frame of frames) {
          if (frame.event === 'log') {
            try {
              const log = JSON.parse(frame.data) as BuildLogEntry
              lastId = Math.max(lastId, log.id)
              handlers.onLogs([log])
            } catch {
              // Ignore malformed frames.
            }
          } else if (frame.event === 'status') {
            try {
              handlers.onStatus(JSON.parse(frame.data) as StatusUpdate)
            } catch {
              // Ignore malformed frames.
            }
          } else if (frame.event === 'end') {
            let status: string | null = null
            try {
              status = (JSON.parse(frame.data) as { status: string | null }).status
            } catch {
              // Ignore malformed frames.
            }
            closed = true
            handlers.onEnd(status)
            handlers.onConnection('closed')
            return
          }
        }
      }

      if (!closed) {
        scheduleReconnect()
      }
    } catch (error) {
      if (closed || (error instanceof DOMException && error.name === 'AbortError')) {
        handlers.onConnection('closed')
        return
      }
      scheduleReconnect()
    }
  }

  const scheduleReconnect = () => {
    if (closed) return
    handlers.onConnection('reconnecting')
    const delay = retryDelay
    retryDelay = Math.min(retryDelay * 2, 15000)
    window.setTimeout(() => {
      void connect()
    }, delay)
  }

  void connect()

  return {
    close: () => {
      closed = true
      controller?.abort()
      handlers.onConnection('closed')
    },
  }
}
