import { useEffect, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Copy, Pause, Play } from 'lucide-react'
import { Button } from '@/components/ui'
import { api } from '@/lib/api'
import { cn } from '@/lib/cn'
import { openLogStream, type ConnectionState, type StatusUpdate } from '@/lib/logs'
import type { BuildLogEntry } from '@/lib/types'

const MAX_LINES = 8000

export function LogViewer({
  buildId,
  live,
  onStatus,
  onEnd,
}: {
  buildId: number
  live: boolean
  onStatus?: (status: StatusUpdate) => void
  onEnd?: (status: string | null) => void
}) {
  const [logs, setLogs] = useState<BuildLogEntry[]>([])
  const [connection, setConnection] = useState<ConnectionState>('connecting')
  const [autoScroll, setAutoScroll] = useState(true)
  const [usingFallback, setUsingFallback] = useState(false)
  const endRef = useRef<HTMLDivElement>(null)
  const lastIdRef = useRef(0)

  const upsertLogs = (incoming: BuildLogEntry[]) => {
    if (incoming.length === 0) return

    setLogs((current) => {
      const byId = new Map<number, BuildLogEntry>()
      for (const log of current) byId.set(log.id, log)
      for (const log of incoming) byId.set(log.id, log)

      const merged = Array.from(byId.values()).sort((a, b) => a.id - b.id)
      const capped = merged.length > MAX_LINES ? merged.slice(merged.length - MAX_LINES) : merged

      const last = capped[capped.length - 1]
      if (last) lastIdRef.current = Math.max(lastIdRef.current, last.id)

      return capped
    })
  }

  // Initial history; also used as polling fallback when SSE keeps failing.
  const history = useQuery({
    queryKey: ['build-logs', buildId],
    queryFn: async () => {
      const page = await api.buildLogs(buildId, 0, 1000)
      upsertLogs(page.logs)

      return page
    },
    refetchInterval: usingFallback && live ? 2000 : false,
  })

  // SSE stream with automatic reconnection from the last received id.
  useEffect(() => {
    if (!live) {
      setConnection('closed')
      return
    }

    let reconnectFailures = 0
    const handle = openLogStream(buildId, lastIdRef.current, {
      onLogs: (incoming) => upsertLogs(incoming),
      onStatus: (status) => onStatus?.(status),
      onEnd: (status) => {
        setConnection('closed')
        onEnd?.(status)
      },
      onConnection: (state) => {
        if (state === 'reconnecting') {
          reconnectFailures++
          if (reconnectFailures >= 3) setUsingFallback(true)
        } else if (state === 'connected') {
          reconnectFailures = 0
        }
        setConnection(state)
      },
    })

    return () => handle.close()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [buildId, live])

  useEffect(() => {
    if (autoScroll) {
      endRef.current?.scrollIntoView({ behavior: 'auto' })
    }
  }, [logs, autoScroll])

  const copyLogs = async () => {
    const text = logs.map((log) => `[${log.created_at}] [${log.stream}] ${log.message}`).join('\n')
    try {
      await navigator.clipboard.writeText(text)
    } catch {
      // Clipboard may be unavailable on insecure origins.
    }
  }

  const connectionLabel =
    connection === 'connected'
      ? 'Conectado'
      : connection === 'connecting'
        ? 'Conectando…'
        : connection === 'reconnecting'
          ? 'Reconectando…'
          : live
            ? 'Desconectado'
            : 'Finalizado'

  const connectionTone =
    connection === 'connected'
      ? 'bg-success'
      : connection === 'closed'
        ? 'bg-fg-subtle'
        : 'bg-warning'

  return (
    <div className="flex flex-col gap-2.5">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-2 text-meta text-fg-muted">
          <span
            className={cn(
              'size-1.5 shrink-0 rounded-full',
              connectionTone,
              (connection === 'connecting' || connection === 'reconnecting') && 'animate-pulse',
            )}
            aria-hidden
          />
          <span>{connectionLabel}</span>
          {usingFallback ? <span className="text-fg-subtle">· modo polling</span> : null}
          <span className="text-fg-subtle">· {logs.length} linha(s)</span>
        </div>
        <div className="flex items-center gap-1.5">
          <Button variant="secondary" size="sm" onClick={() => setAutoScroll((value) => !value)}>
            {autoScroll ? (
              <Pause className="size-3.5" aria-hidden />
            ) : (
              <Play className="size-3.5" aria-hidden />
            )}
            {autoScroll ? 'Pausar rolagem' : 'Retomar rolagem'}
          </Button>
          <Button variant="secondary" size="sm" onClick={copyLogs} disabled={logs.length === 0}>
            <Copy className="size-3.5" aria-hidden /> Copiar logs
          </Button>
        </div>
      </div>

      <div
        role="log"
        aria-label="Saída da build"
        onScroll={(event) => {
          const element = event.currentTarget
          const atBottom = element.scrollHeight - element.scrollTop - element.clientHeight < 40
          if (!atBottom && autoScroll) setAutoScroll(false)
          if (atBottom && !autoScroll) setAutoScroll(true)
        }}
        className="h-[min(58vh,560px)] min-h-[320px] overflow-y-auto rounded-surface bg-terminal px-3 py-2.5"
      >
        {logs.length === 0 ? (
          <p className="py-8 text-center text-meta text-terminal-muted">
            {history.isLoading ? 'Carregando logs…' : 'Nenhum log recebido ainda.'}
          </p>
        ) : (
          logs.map((log) => (
            <div
              key={log.id}
              className="flex gap-3 rounded-sm px-1 py-px font-mono text-meta leading-[1.65] hover:bg-white/[0.03]"
            >
              <span className="shrink-0 tabular-nums text-terminal-muted select-none">
                {new Date(log.created_at).toLocaleTimeString('pt-BR')}
              </span>
              <span
                className={cn(
                  'min-w-0 [overflow-wrap:anywhere] whitespace-pre-wrap',
                  log.stream === 'stderr'
                    ? 'text-terminal-stderr'
                    : log.stream === 'system'
                      ? 'text-terminal-system'
                      : 'text-terminal-fg',
                )}
              >
                {log.stream === 'stderr' ? '! ' : log.stream === 'system' ? '» ' : ''}
                {log.message}
              </span>
            </div>
          ))
        )}
        <div ref={endRef} />
      </div>
    </div>
  )
}
