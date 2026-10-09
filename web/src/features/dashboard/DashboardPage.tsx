import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router'
import { Plus } from 'lucide-react'
import { BuildsTable } from '@/features/builds/BuildsTable'
import { RunnerStatusBadge } from '@/components/StatusBadge'
import { ButtonLink, Card, EmptyState, ErrorState, LoadingBlock } from '@/components/ui'
import { api, apiErrorMessage } from '@/lib/api'
import { formatRelative } from '@/lib/format'

const statOrder = [
  { key: 'total_builds', label: 'Total de builds' },
  { key: 'queued', label: 'Na fila' },
  { key: 'preparing', label: 'Preparando' },
  { key: 'running', label: 'Executando' },
  { key: 'success', label: 'Concluídas' },
  { key: 'failed', label: 'Falhas' },
  { key: 'cancelled', label: 'Canceladas' },
] as const

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

  const stats: Record<string, number> = {
    total_builds: data.stats.total_builds,
    ...data.stats.by_status,
  }

  return (
    <div className="flex flex-col gap-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">Dashboard</h1>
          <p className="text-xs text-[var(--app-fg-subtle)]">
            {data.stats.projects_count} projeto(s) — {data.stats.active_projects_count} ativo(s) —{' '}
            {data.stats.runners_online}/{data.stats.runners_total} runner(s) online
          </p>
        </div>
        <div className="flex gap-2">
          <ButtonLink to="/projects/new" variant="secondary" size="sm">
            <Plus className="size-3.5" /> Novo projeto
          </ButtonLink>
          <ButtonLink to="/builds/new" size="sm">
            <Plus className="size-3.5" /> Nova build
          </ButtonLink>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
        {statOrder.map((stat) => (
          <div
            key={stat.key}
            className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-4 py-3"
          >
            <p className="text-[11px] tracking-wide text-[var(--app-fg-subtle)] uppercase">{stat.label}</p>
            <p className="mt-1 text-2xl font-semibold tabular-nums">{stats[stat.key] ?? 0}</p>
          </div>
        ))}
      </div>

      <Card
        title="Últimas execuções"
        actions={
          <Link to="/builds" className="text-xs font-medium text-[var(--app-accent)] hover:underline">
            Ver todas
          </Link>
        }
        bodyClassName="p-0"
      >
        {data.recent_builds.length === 0 ? (
          <EmptyState
            title="Nenhuma build executada ainda"
            description="Cadastre um projeto e um perfil de build para começar."
            action={<ButtonLink to="/projects/new" size="sm">Cadastrar projeto</ButtonLink>}
          />
        ) : (
          <BuildsTable builds={data.recent_builds} />
        )}
      </Card>

      <Card title="Runners" bodyClassName="p-0">
        {data.runners.length === 0 ? (
          <EmptyState
            title="Nenhum runner registrado"
            description="Registre um runner na API com o comando runner:create e configure-o no Windows."
          />
        ) : (
          <ul className="divide-y divide-[var(--app-border)]">
            {data.runners.map((runner) => (
              <li key={runner.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                <div className="flex items-center gap-3">
                  <RunnerStatusBadge status={runner.status} label={runner.status_label} />
                  <div>
                    <p className="text-sm font-medium">{runner.name}</p>
                    <p className="text-[11px] text-[var(--app-fg-subtle)]">
                      v{runner.software_version ?? '?'} — protocolo {runner.protocol_version}
                    </p>
                  </div>
                </div>
                <div className="text-right text-xs text-[var(--app-fg-muted)]">
                  <p>Último heartbeat: {formatRelative(runner.last_heartbeat_at)}</p>
                  {runner.current_build ? (
                    <Link to={`/builds/${runner.current_build.id}`} className="text-[var(--app-accent)] hover:underline">
                      Build atual #{runner.current_build.id}
                    </Link>
                  ) : (
                    <p className="text-[var(--app-fg-subtle)]">Ocioso</p>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  )
}
