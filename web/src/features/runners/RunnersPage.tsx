import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router'
import { RunnerStatusBadge } from '@/components/StatusBadge'
import { Card, EmptyState, ErrorState, PageHeader, TableShell, TableSkeleton, Td, Th } from '@/components/ui'
import { api } from '@/lib/api'
import { formatDateTime, formatRelative } from '@/lib/format'

export function RunnersPage() {
  const { data, isLoading, isError, error, refetch, isFetching } = useQuery({
    queryKey: ['runners'],
    queryFn: api.runners,
    refetchInterval: 15000,
  })

  const runners = data?.runners ?? []
  const online = runners.filter((runner) => runner.status === 'online').length

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title="Runners"
        description={
          runners.length > 0
            ? `${online} de ${runners.length} online · executores Windows que reivindicam builds da fila`
            : 'Executores Windows que reivindicam builds da fila. Tokens são individuais e revogáveis.'
        }
      />

      <Card bodyClassName="p-0">
        {isLoading ? (
          <TableSkeleton rows={3} columns={6} />
        ) : isError ? (
          <ErrorState error={error} onRetry={() => refetch()} />
        ) : runners.length === 0 ? (
          <EmptyState
            title="Nenhum runner registrado"
            description="Gere um token com o comando runner:create na API e configure o runner Windows."
          />
        ) : (
          <>
            <TableShell>
              <thead>
                <tr>
                  <Th>Runner</Th>
                  <Th>Estado</Th>
                  <Th>Último heartbeat</Th>
                  <Th>Versão</Th>
                  <Th>Sistema</Th>
                  <Th className="text-right">Build atual</Th>
                </tr>
              </thead>
              <tbody>
                {runners.map((runner) => (
                  <tr key={runner.id} className="transition-colors hover:bg-fill-hover/60">
                    <Td className="text-body font-medium text-fg">{runner.name}</Td>
                    <Td>
                      <RunnerStatusBadge status={runner.status} label={runner.status_label} />
                    </Td>
                    <Td className="text-meta text-fg-muted" title={formatDateTime(runner.last_heartbeat_at)}>
                      {formatRelative(runner.last_heartbeat_at)}
                    </Td>
                    <Td className="text-meta tabular-nums text-fg-muted">
                      {runner.software_version ?? '—'}{' '}
                      <span className="text-fg-subtle">· protocolo {runner.protocol_version}</span>
                    </Td>
                    <Td className="max-w-64 truncate text-meta text-fg-muted" title={runner.os_info ?? ''}>
                      {runner.os_info ?? '—'}
                    </Td>
                    <Td className="text-right text-meta">
                      {runner.current_build ? (
                        <Link
                          to={`/builds/${runner.current_build.id}`}
                          className="focus-ring rounded-sm font-medium text-accent hover:underline"
                        >
                          #{runner.current_build.id}
                        </Link>
                      ) : (
                        <span className="text-fg-subtle">Ocioso</span>
                      )}
                    </Td>
                  </tr>
                ))}
              </tbody>
            </TableShell>
            {isFetching ? <p className="px-3 pb-2 text-micro text-fg-subtle">Atualizando…</p> : null}
          </>
        )}
      </Card>
    </div>
  )
}
