import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router'
import { Pencil, Plus, Power, Trash2 } from 'lucide-react'
import {
  Button,
  ButtonLink,
  Card,
  ConfirmDialog,
  EmptyState,
  ErrorState,
  IconButton,
  LoadingBlock,
  MetaItem,
  MetaList,
  PageHeader,
  Pagination,
  StatusText,
  TableShell,
  Tabs,
  Td,
  Th,
} from '@/components/ui'
import { useToast } from '@/components/toast'
import { api, apiErrorMessage } from '@/lib/api'
import { formatDateTime, formatDuration } from '@/lib/format'
import { BuildsTable } from '@/features/builds/BuildsTable'
import { ProfileFormModal } from './ProfileFormModal'
import { SecretsPanel } from './SecretsPanel'
import { WebhookPanel } from './WebhookPanel'
import type { BuildProfile } from '@/lib/types'

const tabs = [
  { id: 'overview', label: 'Visão geral' },
  { id: 'profiles', label: 'Perfis' },
  { id: 'automation', label: 'Automação' },
  { id: 'secrets', label: 'Segredos' },
  { id: 'history', label: 'Histórico' },
] as const

type TabId = (typeof tabs)[number]['id']

export function ProjectDetailPage() {
  const params = useParams()
  const projectId = Number(params.id)
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const toast = useToast()
  const [searchParams, setSearchParams] = useSearchParams()

  const activeTab = (tabs.find((tab) => tab.id === searchParams.get('tab'))?.id ?? 'overview') as TabId

  const setActiveTab = (id: string) => {
    const next = new URLSearchParams(searchParams)
    if (id === 'overview') next.delete('tab')
    else next.set('tab', id)
    setSearchParams(next, { replace: true })
  }

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
    enabled: activeTab === 'history',
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
  const activeProfiles = profiles.filter((profile) => profile.is_active).length

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        breadcrumb={
          <Link to="/projects" className="focus-ring rounded-sm hover:text-fg">
            Projetos
          </Link>
        }
        title={project.name}
        badge={
          project.is_active ? <StatusText tone="success">Ativo</StatusText> : <StatusText>Inativo</StatusText>
        }
        description={
          <span className="font-mono">
            {project.slug} · {project.repo_url}
          </span>
        }
        actions={
          <>
            <ButtonLink to={`/projects/${project.id}/edit`} variant="secondary">
              <Pencil className="size-3.5" aria-hidden /> Editar
            </ButtonLink>
            {project.is_active ? (
              <ButtonLink to={`/builds/new?project=${project.id}`}>
                <Plus className="size-4" aria-hidden /> Nova build
              </ButtonLink>
            ) : null}
            {project.is_active ? (
              <Button
                variant="secondary"
                onClick={() => setConfirmState({ type: 'deactivate-project' })}
              >
                <Power className="size-3.5" aria-hidden /> Desativar
              </Button>
            ) : (
              <Button variant="secondary" onClick={() => setActive.mutate(true)} loading={setActive.isPending}>
                <Power className="size-3.5" aria-hidden /> Ativar
              </Button>
            )}
            <IconButton
              label="Remover projeto"
              tone="danger"
              onClick={() => setConfirmState({ type: 'delete-project' })}
            >
              <Trash2 className="size-4" aria-hidden />
            </IconButton>
          </>
        }
      />

      <Tabs
        items={tabs.map((tab) => ({
          ...tab,
          count:
            tab.id === 'profiles'
              ? profiles.length
              : tab.id === 'history'
                ? buildsQuery.data?.total
                : undefined,
        }))}
        active={activeTab}
        onChange={setActiveTab}
      />

      <div
        role="tabpanel"
        id={`panel-${activeTab}`}
        aria-labelledby={`tab-${activeTab}`}
        tabIndex={0}
        className="flex flex-col gap-4 focus:outline-none"
      >
        {activeTab === 'overview' ? (
          <Card title="Repositório" description="Origem do código, execução e vínculos do projeto.">
            <MetaList className="sm:grid-cols-2 lg:grid-cols-3">
              <MetaItem label="URL do repositório" mono className="sm:col-span-2">
                <span className="break-all">{project.repo_url}</span>
              </MetaItem>
              <MetaItem label="Branch padrão" mono>
                {project.default_branch}
              </MetaItem>
              <MetaItem label="Diretório de execução" mono>
                {project.working_directory}
              </MetaItem>
              <MetaItem label="Branches permitidas" mono>
                {project.allowed_branches.length > 0 ? project.allowed_branches.join(', ') : 'Qualquer branch'}
              </MetaItem>
              <MetaItem label="Perfis ativos">
                {activeProfiles} de {profiles.length}
              </MetaItem>
              <MetaItem label="Automação">
                {project.webhook_enabled ? (
                  <StatusText tone="success">Ativa</StatusText>
                ) : (
                  <StatusText>Desativada</StatusText>
                )}
              </MetaItem>
              <MetaItem label="Criado em">{formatDateTime(project.created_at)}</MetaItem>
              <MetaItem label="Atualizado em">{formatDateTime(project.updated_at)}</MetaItem>
            </MetaList>
          </Card>
        ) : null}

        {activeTab === 'profiles' ? (
          <Card
            title="Perfis de build"
            description="Cada perfil define o script executado no runner."
            actions={
              <Button size="sm" onClick={() => setProfileModal({ open: true, profile: null })}>
                <Plus className="size-3.5" aria-hidden /> Novo perfil
              </Button>
            }
            bodyClassName="p-0"
          >
            {profiles.length === 0 ? (
              <EmptyState
                title="Nenhum perfil configurado"
                description="Crie um perfil com o script de build para executar builds deste projeto."
              />
            ) : (
              <TableShell>
                <thead>
                  <tr>
                    <Th>Perfil</Th>
                    <Th>Timeout</Th>
                    <Th>Artefatos esperados</Th>
                    <Th>Estado</Th>
                    <Th className="w-28 text-right">Ações</Th>
                  </tr>
                </thead>
                <tbody>
                  {profiles.map((profile) => (
                    <tr key={profile.id} className="transition-colors hover:bg-fill-hover/60">
                      <Td>
                        <p className="text-body font-medium text-fg">{profile.name}</p>
                        <p className="font-mono text-micro text-fg-subtle">{profile.slug}</p>
                      </Td>
                      <Td className="text-meta tabular-nums text-fg-muted">
                        {formatDuration(profile.timeout_seconds * 1000)}
                      </Td>
                      <Td className="font-mono text-micro break-all text-fg-muted">
                        {profile.artifact_patterns.length > 0 ? profile.artifact_patterns.join(', ') : '—'}
                      </Td>
                      <Td>
                        {profile.is_active ? (
                          <StatusText tone="success">Ativo</StatusText>
                        ) : (
                          <StatusText>Inativo</StatusText>
                        )}
                      </Td>
                      <Td className="text-right">
                        <div className="flex justify-end gap-0.5">
                          <IconButton
                            label="Editar perfil"
                            size="sm"
                            onClick={() => setProfileModal({ open: true, profile })}
                          >
                            <Pencil className="size-3.5" aria-hidden />
                          </IconButton>
                          <IconButton
                            label={profile.is_active ? 'Desativar perfil' : 'Ativar perfil'}
                            size="sm"
                            onClick={() => setProfileActive.mutate({ profile, active: !profile.is_active })}
                          >
                            <Power className="size-3.5" aria-hidden />
                          </IconButton>
                          <IconButton
                            label="Remover perfil"
                            tone="danger"
                            size="sm"
                            onClick={() => setConfirmState({ type: 'delete-profile', profile })}
                          >
                            <Trash2 className="size-3.5" aria-hidden />
                          </IconButton>
                        </div>
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </TableShell>
            )}
          </Card>
        ) : null}

        {activeTab === 'automation' ? (
          <WebhookPanel project={project} profiles={profiles} branchTriggers={branchTriggers} />
        ) : null}

        {activeTab === 'secrets' ? <SecretsPanel projectId={project.id} /> : null}

        {activeTab === 'history' ? (
          <Card
            title="Histórico de builds"
            description="Execuções deste projeto, da mais recente para a mais antiga."
            actions={
              <Link
                to={`/builds?project_id=${project.id}`}
                className="focus-ring rounded-sm text-meta font-medium text-accent hover:underline"
              >
                Ver com filtros
              </Link>
            }
            bodyClassName="p-0"
          >
            {buildsQuery.isLoading ? (
              <LoadingBlock />
            ) : (buildsQuery.data?.data.length ?? 0) === 0 ? (
              <EmptyState
                title="Nenhuma build executada"
                description="Use o botão Nova build para executar a primeira."
              />
            ) : (
              <>
                <BuildsTable builds={buildsQuery.data?.data ?? []} showProject={false} />
                <div className="border-t border-line px-3 py-2">
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
        ) : null}
      </div>

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
