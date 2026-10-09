import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router'
import { BuildsTable } from './BuildsTable'
import { ButtonLink, Card, EmptyState, ErrorState, Field, Input, LoadingBlock, Pagination, Select } from '@/components/ui'
import { api, apiErrorMessage } from '@/lib/api'

const statusOptions = [
  { value: '', label: 'Todos os estados' },
  { value: 'queued', label: 'Na fila' },
  { value: 'preparing', label: 'Preparando' },
  { value: 'running', label: 'Executando' },
  { value: 'success', label: 'Concluídas' },
  { value: 'failed', label: 'Falhas' },
  { value: 'cancelled', label: 'Canceladas' },
]

const sourceOptions = [
  { value: '', label: 'Todas as origens' },
  { value: 'manual', label: 'Manual' },
  { value: 'webhook', label: 'Webhook' },
  { value: 'api', label: 'API' },
]

export function BuildsPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const [page, setPage] = useState(1)

  const projectId = searchParams.get('project_id') ?? ''
  const status = searchParams.get('status') ?? ''
  const source = searchParams.get('source') ?? ''
  const branch = searchParams.get('branch') ?? ''
  const sha = searchParams.get('sha') ?? ''

  const setFilter = (key: string, value: string) => {
    const next = new URLSearchParams(searchParams)
    if (value) next.set(key, value)
    else next.delete(key)
    setSearchParams(next, { replace: true })
    setPage(1)
  }

  const projectsQuery = useQuery({
    queryKey: ['projects', { perPage: 100 }],
    queryFn: () => api.projects({ per_page: 100 }),
    staleTime: 60_000,
  })

  const buildsQuery = useQuery({
    queryKey: ['builds', { page, projectId, status, source, branch, sha }],
    queryFn: () =>
      api.builds({
        page,
        project_id: projectId || undefined,
        status: status || undefined,
        source: source || undefined,
        branch: branch || undefined,
        sha: sha || undefined,
      }),
  })

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">Builds</h1>
          <p className="text-xs text-[var(--app-fg-subtle)]">Histórico de execuções com filtros.</p>
        </div>
        <ButtonLink to="/builds/new" size="sm">
          Nova build
        </ButtonLink>
      </div>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <Field label="Projeto">
          <Select value={projectId} onChange={(event) => setFilter('project_id', event.target.value)}>
            <option value="">Todos</option>
            {(projectsQuery.data?.data ?? []).map((project) => (
              <option key={project.id} value={project.id}>
                {project.name}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Estado">
          <Select value={status} onChange={(event) => setFilter('status', event.target.value)}>
            {statusOptions.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Origem">
          <Select value={source} onChange={(event) => setFilter('source', event.target.value)}>
            {sourceOptions.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Branch">
          <Input value={branch} onChange={(event) => setFilter('branch', event.target.value)} placeholder="main" />
        </Field>
        <Field label="Commit (SHA)">
          <Input value={sha} onChange={(event) => setFilter('sha', event.target.value)} placeholder="abc123" />
        </Field>
      </div>

      <Card bodyClassName="p-0">
        {buildsQuery.isLoading ? (
          <LoadingBlock />
        ) : buildsQuery.isError ? (
          <ErrorState message={apiErrorMessage(buildsQuery.error)} onRetry={() => buildsQuery.refetch()} />
        ) : (buildsQuery.data?.data.length ?? 0) === 0 ? (
          <EmptyState
            title="Nenhuma build encontrada"
            description="Ajuste os filtros ou crie uma nova build."
            action={<ButtonLink to="/builds/new" size="sm">Nova build</ButtonLink>}
          />
        ) : (
          <>
            <BuildsTable builds={buildsQuery.data?.data ?? []} />
            <div className="border-t border-[var(--app-border)] px-3 py-2">
              <Pagination
                page={page}
                lastPage={buildsQuery.data?.last_page ?? 1}
                total={buildsQuery.data?.total ?? 0}
                onChange={setPage}
              />
            </div>
          </>
        )}
      </Card>

      <p className="text-[11px] text-[var(--app-fg-subtle)]">
        Dica: <Link to="/builds?status=failed" className="text-[var(--app-accent)] hover:underline">ver somente falhas</Link>.
      </p>
    </div>
  )
}
