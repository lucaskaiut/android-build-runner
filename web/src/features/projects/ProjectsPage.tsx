import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router'
import { Plus, Search } from 'lucide-react'
import { BuildStatusBadge } from '@/components/StatusBadge'
import {
  ButtonLink,
  Card,
  EmptyState,
  ErrorState,
  Input,
  LoadingBlock,
  PageHeader,
  Pagination,
  Select,
  StatusText,
  TableShell,
  Td,
  Th,
} from '@/components/ui'
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
      <PageHeader
        title="Projetos"
        description="Repositórios Git disponíveis para executar builds."
        actions={
          <ButtonLink to="/projects/new">
            <Plus className="size-4" aria-hidden /> Novo projeto
          </ButtonLink>
        }
      />

      <div className="flex flex-wrap items-center gap-2">
        <div className="relative min-w-64 max-w-md flex-1">
          <Search
            className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-fg-subtle"
            aria-hidden
          />
          <Input
            className="pl-8"
            placeholder="Buscar por nome, identificador ou repositório"
            aria-label="Buscar projetos"
            value={search}
            onChange={(event) => {
              setSearch(event.target.value)
              setPage(1)
            }}
          />
        </div>
        <Select
          className="w-40"
          aria-label="Filtrar por estado"
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
            action={
              <ButtonLink to="/projects/new" size="sm">
                Cadastrar projeto
              </ButtonLink>
            }
          />
        ) : (
          <>
            <TableShell>
              <thead>
                <tr>
                  <Th>Projeto</Th>
                  <Th>Repositório</Th>
                  <Th>Branch padrão</Th>
                  <Th className="text-right">Perfis</Th>
                  <Th>Última build</Th>
                  <Th>Estado</Th>
                </tr>
              </thead>
              <tbody>
                {(data?.data ?? []).map((project) => (
                  <tr key={project.id} className="transition-colors hover:bg-fill-hover/60">
                    <Td>
                      <Link
                        to={`/projects/${project.id}`}
                        className="focus-ring rounded-sm text-body font-medium text-fg hover:underline"
                      >
                        {project.name}
                      </Link>
                      <p className="font-mono text-micro text-fg-subtle">{project.slug}</p>
                    </Td>
                    <Td>
                      <span
                        className="block max-w-[280px] truncate font-mono text-meta text-fg-muted"
                        title={project.repo_url}
                      >
                        {project.repo_url}
                      </span>
                    </Td>
                    <Td className="font-mono text-meta text-fg-muted">{project.default_branch}</Td>
                    <Td className="text-right text-body tabular-nums text-fg-muted">
                      {project.profiles_count ?? 0}
                    </Td>
                    <Td>
                      {project.last_build ? (
                        <div className="flex items-center gap-2">
                          <BuildStatusBadge
                            status={project.last_build.status}
                            label={project.last_build.status_label}
                          />
                          <Link
                            to={`/builds/${project.last_build.id}`}
                            className="focus-ring rounded-sm font-mono text-micro text-fg-subtle hover:text-accent hover:underline"
                          >
                            #{project.last_build.id} · {formatRelative(project.last_build.created_at)}
                          </Link>
                        </div>
                      ) : (
                        <span className="text-meta text-fg-subtle">Nenhuma</span>
                      )}
                    </Td>
                    <Td>
                      {project.is_active ? (
                        <StatusText tone="success">Ativo</StatusText>
                      ) : (
                        <StatusText>Inativo</StatusText>
                      )}
                    </Td>
                  </tr>
                ))}
              </tbody>
            </TableShell>
            <div className="border-t border-line px-3 py-2">
              <Pagination
                page={page}
                lastPage={data?.last_page ?? 1}
                total={data?.total ?? 0}
                onChange={setPage}
              />
            </div>
            {isFetching ? <p className="px-3 pb-2 text-micro text-fg-subtle">Atualizando…</p> : null}
          </>
        )}
      </Card>
    </div>
  )
}
