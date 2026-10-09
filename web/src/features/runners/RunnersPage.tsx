import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router'
import { RunnerStatusBadge } from '@/components/StatusBadge'
import { Card, EmptyState, ErrorState, LoadingBlock, TableShell, Td, Th } from '@/components/ui'
import { api, apiErrorMessage } from '@/lib/api'
import { formatRelative } from '@/lib/format'

export function RunnersPage() {
  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['runners'],
    queryFn: api.runners,
    refetchInterval: 15000,
  })

  return (
    <div className="flex flex-col gap-4">
      <div>
        <h1 className="text-lg font-semibold">Runners</h1>
        <p className="text-xs text-[var(--app-fg-subtle)]">
          Executores Windows que consultam a API e executam as builds. Tokens são individuais e revogáveis.
        </p>
      </div>

      <Card bodyClassName="p-0">
        {isLoading ? (
          <LoadingBlock />
        ) : isError ? (
          <ErrorState message={apiErrorMessage(error)} onRetry={() => refetch()} />
        ) : (data?.runners.length ?? 0) === 0 ? (
          <EmptyState
            title="Nenhum runner registrado"
            description="Gere um token com o comando runner:create na API e configure o runner Windows."
          />
        ) : (
          <TableShell>
            <thead>
              <tr>
                <Th>Runner</Th>
                <Th>Estado</Th>
                <Th>Último heartbeat</Th>
                <Th>Versão</Th>
                <Th>Sistema</Th>
                <Th>Build atual</Th>
              </tr>
            </thead>
            <tbody>
              {(data?.runners ?? []).map((runner) => (
                <tr key={runner.id}>
                  <Td className="text-sm font-medium">{runner.name}</Td>
                  <Td>
                    <RunnerStatusBadge status={runner.status} label={runner.status_label} />
                  </Td>
                  <Td className="text-xs">{formatRelative(runner.last_heartbeat_at)}</Td>
                  <Td className="text-xs">
                    {runner.software_version ?? '—'} (protocolo {runner.protocol_version})
                  </Td>
                  <Td className="max-w-64 truncate text-xs text-[var(--app-fg-muted)]" title={runner.os_info ?? ''}>
                    {runner.os_info ?? '—'}
                  </Td>
                  <Td className="text-xs">
                    {runner.current_build ? (
                      <Link to={`/builds/${runner.current_build.id}`} className="text-[var(--app-accent)] hover:underline">
                        #{runner.current_build.id}
                      </Link>
                    ) : (
                      <span className="text-[var(--app-fg-subtle)]">Ocioso</span>
                    )}
                  </Td>
                </tr>
              ))}
            </tbody>
          </TableShell>
        )}
      </Card>
    </div>
  )
}
