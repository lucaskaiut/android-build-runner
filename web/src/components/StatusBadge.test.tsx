import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { BuildStatusBadge, RunnerStatusBadge } from './StatusBadge'

describe('StatusBadge', () => {
  it('renders the build status label', () => {
    render(<BuildStatusBadge status="running" label="Executando" />)
    expect(screen.getByText('Executando')).toBeInTheDocument()
  })

  it('falls back to the raw status', () => {
    render(<BuildStatusBadge status="failed" />)
    expect(screen.getByText('failed')).toBeInTheDocument()
  })

  it('renders runner statuses', () => {
    render(<RunnerStatusBadge status="online" label="Online" />)
    expect(screen.getByText('Online')).toBeInTheDocument()
  })
})
