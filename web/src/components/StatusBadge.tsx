import { Badge } from '@/components/ui'
import type { BuildStatus, RunnerStatus, WebhookStatus } from '@/lib/types'

const buildStatusTone: Record<BuildStatus, 'neutral' | 'success' | 'danger' | 'warning' | 'info' | 'accent'> = {
  queued: 'neutral',
  preparing: 'info',
  running: 'accent',
  success: 'success',
  failed: 'danger',
  cancelled: 'warning',
}

export function BuildStatusBadge({ status, label }: { status: BuildStatus; label?: string }) {
  return <Badge tone={buildStatusTone[status] ?? 'neutral'}>{label ?? status}</Badge>
}

const runnerStatusTone: Record<RunnerStatus, 'success' | 'neutral' | 'danger'> = {
  online: 'success',
  offline: 'neutral',
  disabled: 'danger',
}

export function RunnerStatusBadge({ status, label }: { status: RunnerStatus; label?: string }) {
  return <Badge tone={runnerStatusTone[status] ?? 'neutral'}>{label ?? status}</Badge>
}

const webhookStatusTone: Record<WebhookStatus, 'success' | 'warning' | 'danger' | 'neutral' | 'info'> = {
  accepted: 'success',
  duplicate: 'info',
  rejected: 'danger',
  ignored: 'warning',
  error: 'danger',
}

export function WebhookStatusBadge({ status, label }: { status: WebhookStatus; label?: string }) {
  return <Badge tone={webhookStatusTone[status] ?? 'neutral'}>{label ?? status}</Badge>
}
