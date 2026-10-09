import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router'
import { ArrowLeft, Pencil, Plus, Power, Trash2 } from 'lucide-react'
import { Badge, Button, ButtonLink, Card, ConfirmDialog, EmptyState, ErrorState, LoadingBlock, Pagination, TableShell, Td, Th } from '@/components/ui'
import { useToast } from '@/components/toast'
import { api, apiErrorMessage } from '@/lib/api'
import { formatDateTime } from '@/lib/format'
import { BuildsTable } from '@/features/builds/BuildsTable'
import { ProfileFormModal } from './ProfileFormModal'
import { WebhookPanel } from './WebhookPanel'
import type { BuildProfile } from '@/lib/types'

export function ProjectDetailPage() {
  const params = useParams()
  const projectId = Number(params.id)
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const toast = useToast()

  const [profileModal, setProfileModal] = useState<{ open: boolean; profile: BuildProfile | null }>({
    open: false,
    profile: null,
  })
  const [confirmState, setConfirmState] = useState<
    | { type: 'deactivate-project' }
    | { type: 'delete-project' }
    | { type: 'delete-profile'; profile: BuildProfile }
    | null
  >(null)
  const [buildsPage, setBuildsPage] = useState(1)

  const projectQuery = useQuery({
    queryKey: ['project', projectId],
    queryFn: () => api.project(projectId),
  })

  const buildsQuery = useQuery({
    queryKey: ['builds', { project: projectId, page: buildsPage }],
    queryFn: () => api.builds({ project_id: projectId, page: buildsPage }),
  })

  const invalidateProject = () => {
    void queryClient.invalidateQueries({ queryKey: ['project', projectId] })
    void queryClient.invalidateQueries({ queryKey: ['projects'] })
  }

  const setActive = useMutation({
    mutationFn: (active: boolean) =>
      active ? api.activateProject(projectId) : api.deactivateProject(projectId),
    onSuccess: (_data, active) => {
      invalidateProject()
      setConfirmState(null)
      toast.success(active ? 'Projeto ativado' : 'Projeto desativado')
    },
    onError: (error) => toast.error('Falha ao atualizar o projeto', apiErrorMessage(error)),
  })

  const deleteProject = useMutation({
    mutationFn: () => api.deleteProject(projectId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['projects'] })
      toast.success('Projeto removido')
      navigate('/projects')
    },
    onError: (error) => toast.error('Falha ao remover o projeto', apiErrorMessage(error)),
  })

  const setProfileActive = useMutation({
    mutationFn: ({ profile, active }: { profile: BuildProfile; active: boolean }) =>
      active ? api.activateProfile(profile.id) : api.deactivateProfile(profile.id),
    onSuccess: (_data, variables) => {
      invalidateProject()
      toast.success(variables.active ? 'Perfil ativado' : 'Perfil desativado')
    },
    onError: (error) => toast.error('Falha ao atualizar o perfil', apiErrorMessage(error)),
  })

  const deleteProfile = useMutation({
    mutationFn: (profile: BuildProfile) => api.deleteProfile(profile.id),
    onSuccess: () => {
      invalidateProject()
      setConfirmState(null)
      toast.success('Perfil removido')
    },
    onError: (error) => toast.error('Falha ao remover o perfil', apiErrorMessage(error)),
  })

  if (projectQuery.isLoading) return <LoadingBlock />
  if (projectQuery.isError || !projectQuery.data) {
    return <ErrorState message={apiErrorMessage(projectQuery.error)} onRetry={() => projectQuery.refetch()} />
  }

  const { project, profiles, branch_triggers: branchTriggers } = projectQuery.data

  return (
    <div className="flex flex-col gap-5">
      <div>
        <Button variant="ghost" size="sm" onClick={() => navigate('/projects')} className="-ml-2 mb-1">
          <ArrowLeft className="size-3.5" /> Projetos
        </Button>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-2">
            <h1 className="text-lg font-semibold">{project.name}</h1>
            {project.is_active ? <Badge tone="success">Ativo</Badge> : <Badge>Inativo</Badge>}
            <span className="font-mono text-xs text-[var(--app-fg-subtle)]">{project.slug}</span>
          </div>
          <div className="flex flex-wrap gap-2">
            <ButtonLink to={`/projects/${project.id}/edit`} variant="secondary" size="sm">
              <Pencil className="size-3.5" /> Editar
            </ButtonLink>
            {project.is_active ? (
              <ButtonLink to={`/builds/new?project=${project.id}`} size="sm">
                <Plus className="size-3.5" /> Nova build
              </ButtonLink>
            ) : null}
            {project.is_active ? (
              <Button
                variant="secondary"
                size="sm"
                onClick={() => setConfirmState({ type: 'deactivate-project' })}
              >
                <Power className="size-3.5" /> Desativar
              </Button>
            ) : (
              <Button variant="secondary" size="sm" onClick={() => setActive.mutate(true)} loading={setActive.isPending}>
                <Power className="size-3.5" /> Ativar
              </Button>
            )}
            <Button variant="ghost" size="sm" onClick={() => setConfirmState({ type: 'delete-project' })}>
              <Trash2 className="size-3.5" />
            </Button>
          </div>
        </div>
      </div>

      <Card title="Informações">
        <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
          <div>
            <dt className="text-[11px] tracking-wide text-[var(--app-fg-subtle)] uppercase">Repositório</dt>
            <dd className="font-mono text-xs break-all">{project.repo_url}</dd>
          </div>
          <div>
            <dt className="text-[11px] tracking-wide text-[var(--app-fg-subtle)] uppercase">Branch padrão</dt>
            <dd className="font-mono text-xs">{project.default_branch}</dd>
          </div>
          <div>
            <dt className="text-[11px] tracking-wide text-[var(--app-fg-subtle)] uppercase">Diretório de execução</dt>
            <dd className="font-mono text-xs">{project.working_directory}</dd>
          </div>
          <div>
            <dt className="text-[11px] tracking-wide text-[var(--app-fg-subtle)] uppercase">Branches permitidas</dt>
            <dd className="font-mono text-xs">
              {project.allowed_branches.length > 0 ? project.allowed_branches.join(', ') : 'Qualquer branch'}
            </dd>
          </div>
          <div>
            <dt className="text-[11px] tracking-wide text-[var(--app-fg-subtle)] uppercase">Criado em</dt>
            <dd className="text-xs">{formatDateTime(project.created_at)}</dd>
          </div>
        </dl>
      </Card>

      <Card
        title="Perfis de build"
        description="Cada perfil define o script executado no runner."
        actions={
          <Button size="sm" onClick={() => setProfileModal({ open: true, profile: null })}>
            <Plus className="size-3.5" /> Novo perfil
          </Button>
        }
        bodyClassName="p-0"
      >
        {profiles.length === 0 ? (
          <EmptyState
            title="Nenhum perfil configurado"
            description="Crie um perfil com o script de build para poder executar builds deste projeto."
          />
        ) : (
          <TableShell>
            <thead>
              <tr>
                <Th>Perfil</Th>
                <Th>Timeout</Th>
                <Th>Artefatos esperados</Th>
                <Th>Estado</Th>
                <Th className="w-32 text-right">Ações</Th>
              </tr>
            </thead>
            <tbody>
              {profiles.map((profile) => (
                <tr key={profile.id}>
                  <Td>
                    <p className="text-sm font-medium">{profile.name}</p>
                    <p className="font-mono text-[11px] text-[var(--app-fg-subtle)]">{profile.slug}</p>
                  </Td>
                  <Td className="text-xs">{Math.round(profile.timeout_seconds / 60)} min</Td>
                  <Td className="font-mono text-[11px] break-all">
                    {profile.artifact_patterns.length > 0 ? profile.artifact_patterns.join(', ') : '—'}
                  </Td>
                  <Td>{profile.is_active ? <Badge tone="success">Ativo</Badge> : <Badge>Inativo</Badge>}</Td>
                  <Td className="text-right">
                    <div className="flex justify-end gap-1">
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setProfileModal({ open: true, profile })}
                        aria-label="Editar perfil"
                      >
                        <Pencil className="size-3.5" />
                      </Button>
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setProfileActive.mutate({ profile, active: !profile.is_active })}
                        aria-label={profile.is_active ? 'Desativar perfil' : 'Ativar perfil'}
                      >
                        <Power className="size-3.5" />
                      </Button>
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setConfirmState({ type: 'delete-profile', profile })}
                        aria-label="Remover perfil"
                      >
                        <Trash2 className="size-3.5" />
                      </Button>
                    </div>
                  </Td>
                </tr>
              ))}
            </tbody>
          </TableShell>
        )}
      </Card>

      <WebhookPanel project={project} profiles={profiles} branchTriggers={branchTriggers} />

      <Card
        title="Histórico de builds"
        actions={
          <Link to={`/builds?project_id=${project.id}`} className="text-xs font-medium text-[var(--app-accent)] hover:underline">
            Ver com filtros
          </Link>
        }
        bodyClassName="p-0"
      >
        {buildsQuery.isLoading ? (
          <LoadingBlock />
        ) : (buildsQuery.data?.data.length ?? 0) === 0 ? (
          <EmptyState title="Nenhuma build executada" description="Use o botão Nova build para executar a primeira." />
        ) : (
          <>
            <BuildsTable builds={buildsQuery.data?.data ?? []} showProject={false} />
            <div className="border-t border-[var(--app-border)] px-3 py-2">
              <Pagination
                page={buildsPage}
                lastPage={buildsQuery.data?.last_page ?? 1}
                total={buildsQuery.data?.total ?? 0}
                onChange={setBuildsPage}
              />
            </div>
          </>
        )}
      </Card>

      <ProfileFormModal
        open={profileModal.open}
        projectId={projectId}
        profile={profileModal.profile}
        onClose={() => setProfileModal({ open: false, profile: null })}
      />

      <ConfirmDialog
        open={confirmState?.type === 'deactivate-project'}
        title="Desativar projeto"
        message="Novas builds manuais e webhooks serão bloqueados. O histórico continua disponível."
        confirmLabel="Desativar"
        loading={setActive.isPending}
        destructive
        onClose={() => setConfirmState(null)}
        onConfirm={() => setActive.mutate(false)}
      />

      <ConfirmDialog
        open={confirmState?.type === 'delete-project'}
        title="Remover projeto"
        message="A remoção só é permitida quando o projeto não possui histórico de builds."
        confirmLabel="Remover"
        loading={deleteProject.isPending}
        destructive
        onClose={() => setConfirmState(null)}
        onConfirm={() => deleteProject.mutate()}
      />

      <ConfirmDialog
        open={confirmState?.type === 'delete-profile'}
        title="Remover perfil"
        message={`Remover o perfil "${confirmState?.type === 'delete-profile' ? confirmState.profile.name : ''}"? Perfis com builds no histórico não podem ser removidos.`}
        confirmLabel="Remover"
        loading={deleteProfile.isPending}
        destructive
        onClose={() => setConfirmState(null)}
        onConfirm={() => {
          if (confirmState?.type === 'delete-profile') deleteProfile.mutate(confirmState.profile)
        }}
      />
    </div>
  )
}
