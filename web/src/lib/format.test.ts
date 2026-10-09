import { describe, expect, it } from 'vitest'
import { formatBytes, formatDuration, shortSha } from './format'

describe('formatDuration', () => {
  it('formats seconds, minutes and hours', () => {
    expect(formatDuration(null)).toBe('—')
    expect(formatDuration(4_500)).toBe('5s')
    expect(formatDuration(65_000)).toBe('1m 5s')
    expect(formatDuration(120_000)).toBe('2m')
    expect(formatDuration(3_600_000)).toBe('1h')
    expect(formatDuration(5_400_000)).toBe('1h 30m')
  })
})

describe('formatBytes', () => {
  it('formats bytes with reasonable units', () => {
    expect(formatBytes(500)).toBe('500 B')
    expect(formatBytes(2048)).toBe('2.0 KB')
    expect(formatBytes(15 * 1024 * 1024)).toBe('15 MB')
    expect(formatBytes(null)).toBe('—')
  })
})

describe('shortSha', () => {
  it('truncates hashes and handles empty values', () => {
    expect(shortSha('7fd1a60b01f91b314f59955a4e4d4e80d8edf11d')).toBe('7fd1a60b01f9')
    expect(shortSha(null)).toBe('—')
  })
})
