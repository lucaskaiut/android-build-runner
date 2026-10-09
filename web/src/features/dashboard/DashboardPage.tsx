import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router'
import { Plus } from 'lucide-react'
import { BuildsTable } from '@/features/builds/BuildsTable'
import { RunnerStatusBadge } from '@/components/StatusBadge'
import { ButtonLink, Card, EmptyState, ErrorState, LoadingBlock, PageHeader } from '@/components/ui'
import { api, apiErrorMessage } from '@/lib/api'
import { cn } from '@/lib/cn'
import { formatRelative } from '@/lib/format'

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

  if (isLoading) return <LoadingBlock />
  if (isError || !data) return <ErrorState message={apiErrorMessage(error)} onRetry={() => refetch()} />

  const byStatus = data.stats.by_status
  const metrics = [
    { label: 'Executando', value: byStatus.running ?? 0, tone: 'accent' as const },
    { label: 'Na fila', value: byStatus.queued ?? 0 },
    { label: 'Preparando', value: byStatus.preparing ?? 0 },
    { label: 'Falhas', value: byStatus.failed ?? 0, tone: 'danger' as const },
    { label: 'Concluídas', value: byStatus.success ?? 0 },
    { label: 'Canceladas', value: byStatus.cancelled ?? 0 },
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

      <div className="grid grid-cols-2 gap-px overflow-hidden rounded-surface bg-line shadow-card sm:grid-cols-3 lg:grid-cols-6">
        {metrics.map((metric) => (
          <div key={metric.label} className="bg-surface px-4 py-3">
            <p className="text-meta text-fg-subtle">{metric.label}</p>
            <p
              className={cn(
                'mt-0.5 text-xl font-semibold tabular-nums text-fg',
                metric.tone === 'accent' && metric.value > 0 && 'text-accent',
                metric.tone === 'danger' && metric.value > 0 && 'text-danger',
              )}
            >
              {metric.value}
            </p>
          </div>
        ))}
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
