import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router'
import { Ban, CircleCheck, CircleX, Clock, Play, Plus, Wrench } from 'lucide-react'
import { BuildsTable } from '@/features/builds/BuildsTable'
import { RunnerStatusBadge } from '@/components/StatusBadge'
import {
  ButtonLink,
  Card,
  EmptyState,
  ErrorState,
  PageHeader,
  Skeleton,
  TableSkeleton,
} from '@/components/ui'
import { api } from '@/lib/api'
import { cn } from '@/lib/cn'
import { formatRelative } from '@/lib/format'

function DashboardSkeleton() {
  return (
    <div className="flex flex-col gap-5" role="status" aria-label="Carregando dashboard">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <Skeleton className="h-5 w-40" />
          <Skeleton className="mt-2.5 h-3 w-64" />
        </div>
        <div className="flex gap-2">
          <Skeleton className="h-8 w-28" />
          <Skeleton className="h-8 w-32" />
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        {Array.from({ length: 6 }).map((_, index) => (
          <div key={index} className="rounded-surface bg-surface p-3.5 shadow-card">
            <Skeleton className="h-3 w-20" />
            <Skeleton className="mt-3 h-5 w-10" />
          </div>
        ))}
      </div>

      <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_336px]">
        <div className="overflow-hidden rounded-surface bg-surface shadow-card">
          <div className="border-b border-line px-4 py-3">
            <Skeleton className="h-4 w-40" />
          </div>
          <TableSkeleton rows={6} columns={8} />
        </div>
        <div className="rounded-surface bg-surface shadow-card">
          <div className="border-b border-line px-4 py-3">
            <Skeleton className="h-4 w-24" />
          </div>
          <div className="flex flex-col gap-3 p-4">
            <Skeleton className="h-4 w-full" />
            <Skeleton className="h-4 w-3/4" />
          </div>
        </div>
      </div>

      <span className="sr-only">Carregando…</span>
    </div>
  )
}

export function DashboardPage() {
  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['dashboard'],
    queryFn: api.dashboard,
    refetchInterval: (query) => {
      const payload = query.state.data
      if (!payload) return 20000
      const active = ['queued', 'preparing', 'running'].some(
        (status) => (payload.stats.by_status[status] ?? 0) > 0,
      )
      return active ? 4000 : 20000
    },
  })

  if (isLoading) return <DashboardSkeleton />
  if (isError || !data) return <ErrorState error={error} onRetry={() => refetch()} />

  const byStatus = data.stats.by_status
  const metrics = [
    {
      label: 'Executando',
      value: byStatus.running ?? 0,
      icon: Play,
      tone: 'accent' as const,
      to: '/builds?status=running',
    },
    { label: 'Na fila', value: byStatus.queued ?? 0, icon: Clock, to: '/builds?status=queued' },
    {
      label: 'Preparando',
      value: byStatus.preparing ?? 0,
      icon: Wrench,
      to: '/builds?status=preparing',
    },
    {
      label: 'Falhas',
      value: byStatus.failed ?? 0,
      icon: CircleX,
      tone: 'danger' as const,
      to: '/builds?status=failed',
    },
    {
      label: 'Concluídas',
      value: byStatus.success ?? 0,
      icon: CircleCheck,
      to: '/builds?status=success',
    },
    {
      label: 'Canceladas',
      value: byStatus.cancelled ?? 0,
      icon: Ban,
      to: '/builds?status=cancelled',
    },
  ]

  return (
    <div className="flex flex-col gap-5">
      <PageHeader
        title="Dashboard"
        description={`${data.stats.projects_count} projeto(s) · ${data.stats.active_projects_count} ativo(s) · ${data.stats.runners_online}/${data.stats.runners_total} runner(s) online`}
        actions={
          <>
            <ButtonLink to="/projects/new" variant="secondary">
              <Plus className="size-4" aria-hidden /> Novo projeto
            </ButtonLink>
            <ButtonLink to="/builds/new">
              <Plus className="size-4" aria-hidden /> Nova build
            </ButtonLink>
          </>
        }
      />

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        {metrics.map((metric) => {
          const highlight =
            metric.tone && metric.value > 0
              ? metric.tone === 'accent'
                ? 'text-accent'
                : 'text-danger'
              : null

          return (
            <Link
              key={metric.label}
              to={metric.to}
              title={`Ver builds — ${metric.label.toLowerCase()}`}
              className="focus-ring rounded-surface bg-surface p-3.5 shadow-card transition-colors hover:bg-fill-hover"
            >
              <div className={cn('flex items-center gap-1.5', highlight ?? 'text-fg-subtle')}>
                <metric.icon className="size-3.5 shrink-0" aria-hidden />
                <p className="truncate text-meta">{metric.label}</p>
              </div>
              <p className={cn('mt-2 text-xl font-semibold tabular-nums', highlight ?? 'text-fg')}>
                {metric.value}
              </p>
            </Link>
          )
        })}
      </div>

      <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_336px]">
        <Card
          title="Execuções recentes"
          bodyClassName="p-0"
          className="min-w-0"
          actions={
            <Link
              to="/builds"
              className="focus-ring rounded-sm text-meta font-medium text-accent hover:underline"
            >
              Ver todas
            </Link>
          }
        >
          {data.recent_builds.length === 0 ? (
            <EmptyState
              title="Nenhuma build executada ainda"
              description="Cadastre um projeto e um perfil para executar a primeira build."
              action={
                <ButtonLink to="/projects/new" size="sm">
                  Cadastrar projeto
                </ButtonLink>
              }
            />
          ) : (
            <BuildsTable builds={data.recent_builds} />
          )}
        </Card>

        <Card title="Runners" bodyClassName="p-0" className="min-w-0">
          {data.runners.length === 0 ? (
            <EmptyState
              title="Nenhum runner registrado"
              description="Gere um token com runner:create na API e configure o runner Windows."
            />
          ) : (
            <ul className="divide-y divide-line">
              {data.runners.map((runner) => (
                <li key={runner.id} className="flex items-start justify-between gap-3 px-4 py-3">
                  <div className="min-w-0">
                    <div className="flex items-center gap-2">
                      <RunnerStatusBadge status={runner.status} label={runner.status_label} />
                      <p className="truncate text-body font-medium text-fg">{runner.name}</p>
                    </div>
                    <p className="mt-1 text-meta text-fg-subtle">
                      v{runner.software_version ?? '?'} · protocolo {runner.protocol_version}
                    </p>
                  </div>
                  <div className="shrink-0 text-right text-meta">
                    <p className="text-fg-muted">{formatRelative(runner.last_heartbeat_at)}</p>
                    {runner.current_build ? (
                      <Link
                        to={`/builds/${runner.current_build.id}`}
                        className="focus-ring rounded-sm font-medium text-accent hover:underline"
                      >
                        Build #{runner.current_build.id}
                      </Link>
                    ) : (
                      <p className="text-fg-subtle">Ocioso</p>
                    )}
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>
    </div>
  )
}
