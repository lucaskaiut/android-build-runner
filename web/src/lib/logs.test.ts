import { describe, expect, it } from 'vitest'
import { parseSseBuffer } from './logs'

describe('parseSseBuffer', () => {
  it('parses complete frames and keeps the remainder', () => {
    const buffer =
      'event: log\ndata: {"id":1,"message":"a"}\n\n' +
      'event: status\ndata: {"status":"running"}\n\n' +
      'event: log\ndata: {"id":2'

    const { frames, rest } = parseSseBuffer(buffer)

    expect(frames).toHaveLength(2)
    expect(frames[0]).toEqual({ event: 'log', data: '{"id":1,"message":"a"}' })
    expect(frames[1]).toEqual({ event: 'status', data: '{"status":"running"}' })
    expect(rest).toBe('event: log\ndata: {"id":2')
  })

  it('ignores comments and joins multiple data lines', () => {
    const buffer = ': ping 123\n\nevent: log\ndata: linha 1\ndata: linha 2\n\n'
    const { frames } = parseSseBuffer(buffer)

    expect(frames).toHaveLength(1)
    expect(frames[0].event).toBe('log')
    expect(frames[0].data).toBe('linha 1\nlinha 2')
  })

  it('returns nothing for an incomplete frame', () => {
    const { frames, rest } = parseSseBuffer('event: log\ndata: {"id":1}')

    expect(frames).toHaveLength(0)
    expect(rest).toBe('event: log\ndata: {"id":1}')
  })
})
