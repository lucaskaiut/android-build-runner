import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router'
import { Plus, Search } from 'lucide-react'
import { BuildStatusBadge } from '@/components/StatusBadge'
import { Badge, ButtonLink, Card, EmptyState, ErrorState, Input, LoadingBlock, Pagination, Select, TableShell, Td, Th } from '@/components/ui'
import { api, apiErrorMessage } from '@/lib/api'
import { formatRelative } from '@/lib/format'

export function ProjectsPage() {
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [activeFilter, setActiveFilter] = useState('')

  const { data, isLoading, isError, error, refetch, isFetching } = useQuery({
    queryKey: ['projects', { page, search, activeFilter }],
    queryFn: () =>
      api.projects({
        page,
        search: search || undefined,
        is_active: activeFilter === '' ? undefined : activeFilter === 'true' ? 1 : 0,
      }),
  })

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">Projetos</h1>
          <p className="text-xs text-[var(--app-fg-subtle)]">Repositórios Git disponíveis para build.</p>
        </div>
        <ButtonLink to="/projects/new" size="sm">
          <Plus className="size-3.5" /> Novo projeto
        </ButtonLink>
      </div>

      <div className="flex flex-wrap items-end gap-3">
        <div className="relative min-w-56 flex-1">
          <Search className="absolute top-2.5 left-2.5 size-4 text-[var(--app-fg-subtle)]" />
          <Input
            className="pl-8"
            placeholder="Buscar por nome, identificador ou repositório"
            value={search}
            onChange={(event) => {
              setSearch(event.target.value)
              setPage(1)
            }}
          />
        </div>
        <Select
          className="w-40"
          value={activeFilter}
          onChange={(event) => {
            setActiveFilter(event.target.value)
            setPage(1)
          }}
        >
          <option value="">Todos os estados</option>
          <option value="true">Somente ativos</option>
          <option value="false">Somente inativos</option>
        </Select>
      </div>

      <Card bodyClassName="p-0">
        {isLoading ? (
          <LoadingBlock />
        ) : isError ? (
          <ErrorState message={apiErrorMessage(error)} onRetry={() => refetch()} />
        ) : data && data.data.length === 0 ? (
          <EmptyState
            title="Nenhum projeto encontrado"
            description="Cadastre um repositório para começar a executar builds."
            action={<ButtonLink to="/projects/new" size="sm">Cadastrar projeto</ButtonLink>}
          />
        ) : (
          <>
            <TableShell>
              <thead>
                <tr>
                  <Th>Projeto</Th>
                  <Th>Repositório</Th>
                  <Th>Branch padrão</Th>
                  <Th className="text-center">Perfis</Th>
                  <Th>Última build</Th>
                  <Th>Estado</Th>
                </tr>
              </thead>
              <tbody>
                {(data?.data ?? []).map((project) => (
                  <tr key={project.id} className="hover:bg-[var(--app-surface-2)]">
                    <Td>
                      <Link to={`/projects/${project.id}`} className="text-sm font-medium hover:underline">
                        {project.name}
                      </Link>
                      <p className="font-mono text-[11px] text-[var(--app-fg-subtle)]">{project.slug}</p>
                    </Td>
                    <Td>
                      <span className="font-mono text-xs break-all">{project.repo_url}</span>
                    </Td>
                    <Td className="font-mono text-xs">{project.default_branch}</Td>
                    <Td className="text-center text-sm">{project.profiles_count ?? 0}</Td>
                    <Td>
                      {project.last_build ? (
                        <div className="flex items-center gap-2">
                          <BuildStatusBadge status={project.last_build.status} label={project.last_build.status_label} />
                          <Link
                            to={`/builds/${project.last_build.id}`}
                            className="font-mono text-[11px] text-[var(--app-accent)] hover:underline"
                          >
                            #{project.last_build.id} · {formatRelative(project.last_build.created_at)}
                          </Link>
                        </div>
                      ) : (
                        <span className="text-xs text-[var(--app-fg-subtle)]">Nenhuma</span>
                      )}
                    </Td>
                    <Td>
                      {project.is_active ? <Badge tone="success">Ativo</Badge> : <Badge>Inativo</Badge>}
                    </Td>
                  </tr>
                ))}
              </tbody>
            </TableShell>
            <div className="border-t border-[var(--app-border)] px-3 py-2">
              <Pagination
                page={page}
                lastPage={data?.last_page ?? 1}
                total={data?.total ?? 0}
                onChange={setPage}
              />
            </div>
            {isFetching ? <p className="px-3 pb-2 text-[11px] text-[var(--app-fg-subtle)]">Atualizando…</p> : null}
          </>
        )}
      </Card>
    </div>
  )
}
