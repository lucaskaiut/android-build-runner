import { cn } from '@/lib/cn'
import { Badge, type SemanticTone } from '@/components/ui'
import type { BuildStatus, RunnerStatus, SubmitStatus, WebhookStatus } from '@/lib/types'

/**
 * Estados compartilham a mesma linguagem em todo o painel:
 * pastilha com ponto + texto. A cor nunca é o único indicador.
 */
function StatusPill({
  tone,
  label,
  pulse = false,
}: {
  tone: SemanticTone
  label: string
  pulse?: boolean
}) {
  return (
    <Badge tone={tone}>
      <span
        className={cn('size-1.5 shrink-0 rounded-full bg-current', pulse && 'animate-pulse')}
        aria-hidden
      />
      {label}
    </Badge>
  )
}

const buildStatusTone: Record<BuildStatus, SemanticTone> = {
  queued: 'neutral',
  preparing: 'info',
  running: 'accent',
  success: 'success',
  failed: 'danger',
  cancelled: 'warning',
}

export function BuildStatusBadge({ status, label }: { status: BuildStatus; label?: string }) {
  return (
    <StatusPill
      tone={buildStatusTone[status] ?? 'neutral'}
      label={label ?? status}
      pulse={status === 'running'}
    />
  )
}

const submitStatusTone: Record<SubmitStatus, SemanticTone> = {
  pending: 'info',
  processing: 'accent',
  success: 'success',
  failed: 'danger',
  skipped: 'warning',
}

export function SubmitStatusBadge({ status, label }: { status: SubmitStatus; label?: string }) {
  return (
    <StatusPill
      tone={submitStatusTone[status] ?? 'neutral'}
      label={label ?? status}
      pulse={status === 'pending' || status === 'processing'}
    />
  )
}

const runnerStatusTone: Record<RunnerStatus, SemanticTone> = {
  online: 'success',
  offline: 'neutral',
  disabled: 'danger',
}

export function RunnerStatusBadge({ status, label }: { status: RunnerStatus; label?: string }) {
  return <StatusPill tone={runnerStatusTone[status] ?? 'neutral'} label={label ?? status} />
}

const webhookStatusTone: Record<WebhookStatus, SemanticTone> = {
  accepted: 'success',
  duplicate: 'info',
  rejected: 'danger',
  ignored: 'warning',
  error: 'danger',
}

export function WebhookStatusBadge({ status, label }: { status: WebhookStatus; label?: string }) {
  return <StatusPill tone={webhookStatusTone[status] ?? 'neutral'} label={label ?? status} />
}
