import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Link, useNavigate, useSearchParams } from 'react-router'
import { AlertTriangle, Rocket } from 'lucide-react'
import {
  Button,
  Card,
  Field,
  Input,
  LoadingBlock,
  PageHeader,
  Select,
} from '@/components/ui'
import { useToast } from '@/components/toast'
import { api, apiErrorMessage, ApiError } from '@/lib/api'

type RefType = 'branch' | 'commit'

export function BuildNewPage() {
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()
  const toast = useToast()

  const [projectId, setProjectId] = useState(searchParams.get('project') ?? '')
  const [profileId, setProfileId] = useState('')
  const [refType, setRefType] = useState<RefType>('branch')
  const [branch, setBranch] = useState('')
  const [commitSha, setCommitSha] = useState('')
  const [parameters, setParameters] = useState<Record<string, string>>({})

  const projectsQuery = useQuery({
    queryKey: ['projects', { perPage: 100 }],
    queryFn: () => api.projects({ per_page: 100 }),
    staleTime: 60_000,
  })

  const projectQuery = useQuery({
    queryKey: ['project', Number(projectId)],
    queryFn: () => api.project(Number(projectId)),
    enabled: projectId !== '',
  })

  const activeProfiles = useMemo(
    () => (projectQuery.data?.profiles ?? []).filter((profile) => profile.is_active),
    [projectQuery.data],
  )

  const selectedProfile = activeProfiles.find((profile) => String(profile.id) === profileId) ?? null

  useEffect(() => {
    if (projectId === '' && projectsQuery.data && projectsQuery.data.data.length > 0) {
      setProjectId(String(projectsQuery.data.data[0].id))
    }
  }, [projectId, projectsQuery.data])

  useEffect(() => {
    setProfileId('')
    setParameters({})
  }, [projectId])

  useEffect(() => {
    setParameters({})
    if (selectedProfile) {
      const defaults: Record<string, string> = {}
      for (const parameter of selectedProfile.parameters ?? []) {
        if (parameter.default) defaults[parameter.key] = parameter.default
      }
      setParameters(defaults)
    }
  }, [selectedProfile])

  useEffect(() => {
    if (projectQuery.data) {
      setBranch(projectQuery.data.project.default_branch)
    }
  }, [projectQuery.data])

  const mutation = useMutation({
    mutationFn: () =>
      api.createBuild(Number(projectId), {
        build_profile_id: Number(profileId),
        branch: refType === 'branch' ? branch.trim() : undefined,
        commit_sha: refType === 'commit' ? commitSha.trim() : undefined,
        parameters: Object.keys(parameters).length > 0 ? parameters : undefined,
      }),
    onSuccess: (data) => {
      toast.success(`Build #${data.build.id} na fila`)
      navigate(`/builds/${data.build.id}`)
    },
  })

  const canSubmit =
    projectId !== '' &&
    profileId !== '' &&
    (refType === 'branch' ? branch.trim() !== '' : /^[0-9a-fA-F]{40}$/.test(commitSha.trim()))

  if (projectsQuery.isLoading) return <LoadingBlock />

  if ((projectsQuery.data?.data.length ?? 0) === 0) {
    return (
      <div className="mx-auto max-w-2xl">
        <PageHeader
          breadcrumb={
            <Link to="/builds" className="focus-ring rounded-sm hover:text-fg">
              Builds
            </Link>
          }
          title="Nova build"
        />
        <div className="mt-4 rounded-surface bg-surface p-6 text-center shadow-card">
          <p className="text-body font-medium text-fg">Nenhum projeto cadastrado</p>
          <p className="mx-auto mt-1 max-w-md text-meta text-fg-subtle">
            Cadastre um projeto e um perfil de build antes de executar builds.
          </p>
          <Button className="mt-4" onClick={() => navigate('/projects/new')}>
            Cadastrar projeto
          </Button>
        </div>
      </div>
    )
  }

  const fieldError = (name: string) => {
    if (mutation.isError && mutation.error instanceof ApiError) {
      return mutation.error.errors[name]?.[0]
    }
    return undefined
  }

  return (
    <div className="mx-auto flex max-w-2xl flex-col gap-5">
      <PageHeader
        breadcrumb={
          <Link to="/builds" className="focus-ring rounded-sm hover:text-fg">
            Builds
          </Link>
        }
        title="Nova build"
        description="A build entra na fila e é executada pelo runner Windows assim que reivindicada."
      />

      <Card>
        <div className="flex flex-col gap-5">
          <section className="flex flex-col gap-3">
            <h3 className="text-body font-semibold text-fg">Projeto e perfil</h3>
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label="Projeto" required>
                <Select value={projectId} onChange={(event) => setProjectId(event.target.value)}>
                  <option value="">Selecione…</option>
                  {(projectsQuery.data?.data ?? []).map((project) => (
                    <option key={project.id} value={project.id} disabled={!project.is_active}>
                      {project.name}
                      {project.is_active ? '' : ' (inativo)'}
                    </option>
                  ))}
                </Select>
              </Field>
              <Field label="Perfil de build" error={fieldError('build_profile_id')} required>
                <Select
                  value={profileId}
                  onChange={(event) => setProfileId(event.target.value)}
                  disabled={projectQuery.isLoading || activeProfiles.length === 0}
                >
                  <option value="">Selecione…</option>
                  {activeProfiles.map((profile) => (
                    <option key={profile.id} value={profile.id}>
                      {profile.name}
                    </option>
                  ))}
                </Select>
              </Field>
            </div>

            {projectId && activeProfiles.length === 0 && !projectQuery.isLoading ? (
              <p className="flex items-start gap-2 rounded-control bg-warning-soft px-3 py-2 text-meta text-warning">
                <AlertTriangle className="mt-0.5 size-3.5 shrink-0" aria-hidden />
                Este projeto não possui perfis ativos. Configure um perfil antes de executar builds.
              </p>
            ) : null}
          </section>

          <section className="flex flex-col gap-3 border-t border-line pt-4">
            <h3 className="text-body font-semibold text-fg">Referência do código</h3>
            <fieldset className="flex flex-col gap-3">
              <legend className="sr-only">Tipo de referência</legend>
              <div className="flex flex-wrap gap-x-5 gap-y-2 text-body">
                <label className="flex items-center gap-2">
                  <input
                    type="radio"
                    name="ref-type"
                    className="size-3.5 accent-accent"
                    checked={refType === 'branch'}
                    onChange={() => setRefType('branch')}
                  />
                  Branch
                </label>
                <label className="flex items-center gap-2">
                  <input
                    type="radio"
                    name="ref-type"
                    className="size-3.5 accent-accent"
                    checked={refType === 'commit'}
                    onChange={() => setRefType('commit')}
                  />
                  Commit específico (SHA)
                </label>
              </div>

              {refType === 'branch' ? (
                <Field
                  label="Branch"
                  error={fieldError('branch')}
                  hint="O runner resolve o SHA exato durante o checkout."
                  required
                >
                  <Input value={branch} onChange={(event) => setBranch(event.target.value)} placeholder="main" />
                </Field>
              ) : (
                <Field
                  label="Commit (SHA de 40 caracteres)"
                  error={fieldError('commit_sha')}
                  hint="O checkout será exatamente este commit."
                  required
                >
                  <Input
                    value={commitSha}
                    onChange={(event) => setCommitSha(event.target.value)}
                    placeholder="7fd1a60b01f91b314f59955a4e4d4e80d8edf11d"
                  />
                </Field>
              )}
            </fieldset>
          </section>

          {selectedProfile && (selectedProfile.parameters ?? []).length > 0 ? (
            <section className="flex flex-col gap-3 border-t border-line pt-4">
              <h3 className="text-body font-semibold text-fg">Parâmetros do perfil</h3>
              <div className="grid gap-3 sm:grid-cols-2">
                {(selectedProfile.parameters ?? []).map((parameter) => (
                  <Field
                    key={parameter.key}
                    label={parameter.label ?? parameter.key}
                    error={fieldError(`parameters.${parameter.key}`)}
                    required={parameter.required}
                    hint={`Disponível como $env:BUILD_PARAM_${parameter.key.toUpperCase()}`}
                  >
                    {parameter.options && parameter.options.length > 0 ? (
                      <Select
                        value={parameters[parameter.key] ?? ''}
                        onChange={(event) =>
                          setParameters((current) => ({ ...current, [parameter.key]: event.target.value }))
                        }
                      >
                        <option value="">Selecione…</option>
                        {parameter.options.map((option) => (
                          <option key={option} value={option}>
                            {option}
                          </option>
                        ))}
                      </Select>
                    ) : (
                      <Input
                        value={parameters[parameter.key] ?? ''}
                        onChange={(event) =>
                          setParameters((current) => ({ ...current, [parameter.key]: event.target.value }))
                        }
                      />
                    )}
                  </Field>
                ))}
              </div>
            </section>
          ) : null}

          {mutation.isError ? (
            <p className="flex items-start gap-2 rounded-control bg-danger-soft px-3 py-2 text-body text-danger">
              <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden />
              {apiErrorMessage(mutation.error)}
            </p>
          ) : null}

          <div className="flex flex-wrap items-center justify-between gap-3 border-t border-line pt-4">
            <p className="text-meta text-fg-subtle">A build é registrada com estado "Na fila".</p>
            <div className="flex gap-2">
              <Button variant="secondary" onClick={() => navigate(-1)}>
                Cancelar
              </Button>
              <Button disabled={!canSubmit} loading={mutation.isPending} onClick={() => mutation.mutate()}>
                <Rocket className="size-4" aria-hidden /> Executar build
              </Button>
            </div>
          </div>
        </div>
      </Card>
    </div>
  )
}
