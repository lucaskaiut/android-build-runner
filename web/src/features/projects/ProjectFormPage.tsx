import { useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router'
import { ArrowLeft } from 'lucide-react'
import { Button, Card, ErrorState, Field, Input, LoadingBlock, Textarea } from '@/components/ui'
import { useToast } from '@/components/toast'
import { api, apiErrorMessage, ApiError, type ProjectInput } from '@/lib/api'

const schema = z.object({
  name: z.string().min(1, 'Informe o nome.').max(120),
  slug: z
    .string()
    .min(1, 'Informe o identificador.')
    .max(80)
    .regex(/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/, 'Use apenas minúsculas, números, "-" e "_".'),
  repo_url: z
    .string()
    .min(1, 'Informe a URL do repositório.')
    .refine(
      (value) => /^(ssh:\/\/|https?:\/\/|[A-Za-z0-9._-]+@[A-Za-z0-9._-]+:)/.test(value),
      'Use uma URL SSH (git@host:org/repo.git) ou HTTPS.',
    ),
  default_branch: z.string().min(1, 'Informe a branch padrão.').max(255),
  working_directory: z
    .string()
    .min(1, 'Informe o diretório de execução.')
    .refine(
      (value) => !value.includes('..') && !value.startsWith('/') && !value.includes('\\'),
      'Use um caminho relativo sem "..".',
    ),
  allowed_branches: z.string().optional(),
  is_active: z.boolean(),
})

type FormValues = z.infer<typeof schema>

function parseLines(value?: string): string[] {
  return (value ?? '')
    .split('\n')
    .map((line) => line.trim())
    .filter(Boolean)
}

export function ProjectFormPage() {
  const params = useParams()
  const projectId = params.id ? Number(params.id) : null
  const isEdit = projectId !== null
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const toast = useToast()

  const projectQuery = useQuery({
    queryKey: ['project', projectId],
    queryFn: () => api.project(projectId!),
    enabled: isEdit,
  })

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      name: '',
      slug: '',
      repo_url: '',
      default_branch: 'main',
      working_directory: '.',
      allowed_branches: '',
      is_active: true,
    },
  })

  useEffect(() => {
    if (projectQuery.data) {
      const { project } = projectQuery.data
      reset({
        name: project.name,
        slug: project.slug,
        repo_url: project.repo_url,
        default_branch: project.default_branch,
        working_directory: project.working_directory,
        allowed_branches: (project.allowed_branches ?? []).join('\n'),
        is_active: project.is_active,
      })
    }
  }, [projectQuery.data, reset])

  const mutation = useMutation({
    mutationFn: async (values: FormValues) => {
      const payload: ProjectInput = {
        name: values.name,
        slug: values.slug,
        repo_url: values.repo_url.trim(),
        default_branch: values.default_branch.trim(),
        working_directory: values.working_directory.trim(),
        allowed_branches: parseLines(values.allowed_branches),
        is_active: values.is_active,
      }

      return isEdit ? api.updateProject(projectId, payload) : api.createProject(payload)
    },
    onSuccess: (result) => {
      toast.success(isEdit ? 'Projeto atualizado' : 'Projeto criado')
      void queryClient.invalidateQueries({ queryKey: ['projects'] })
      void queryClient.invalidateQueries({ queryKey: ['project', result.project.id] })
      navigate(`/projects/${result.project.id}`)
    },
  })

  const onSubmit = handleSubmit(async (values) => {
    try {
      await mutation.mutateAsync(values)
    } catch {
      // Erro exibido abaixo.
    }
  })

  if (isEdit && projectQuery.isLoading) return <LoadingBlock />
  if (isEdit && projectQuery.isError) {
    return <ErrorState message={apiErrorMessage(projectQuery.error)} onRetry={() => projectQuery.refetch()} />
  }

  const fieldError = (name: keyof FormValues) => {
    const message = errors[name]?.message
    if (typeof message === 'string') return message
    if (mutation.isError && mutation.error instanceof ApiError) {
      return mutation.error.errors[name]?.[0]
    }
    return undefined
  }

  return (
    <div className="mx-auto flex max-w-2xl flex-col gap-4">
      <div>
        <Button variant="ghost" size="sm" onClick={() => navigate(-1)} className="-ml-2 mb-1">
          <ArrowLeft className="size-3.5" /> Voltar
        </Button>
        <h1 className="text-lg font-semibold">{isEdit ? 'Editar projeto' : 'Novo projeto'}</h1>
        <p className="text-xs text-[var(--app-fg-subtle)]">
          O runner fará checkout do repositório via SSH ou HTTPS usando as credenciais da máquina Windows.
        </p>
      </div>

      <Card>
        <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Nome" error={fieldError('name')} required>
              <Input {...register('name')} placeholder="App Mobile" />
            </Field>
            <Field label="Identificador (slug)" error={fieldError('slug')} required>
              <Input {...register('slug')} placeholder="app-mobile" />
            </Field>
          </div>

          <Field
            label="URL do repositório Git"
            error={fieldError('repo_url')}
            hint="Exemplos: git@github.com:org/repo.git ou https://github.com/org/repo.git"
            required
          >
            <Input {...register('repo_url')} placeholder="git@github.com:org/repo.git" />
          </Field>

          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Branch padrão" error={fieldError('default_branch')} required>
              <Input {...register('default_branch')} placeholder="main" />
            </Field>
            <Field
              label="Diretório de execução"
              error={fieldError('working_directory')}
              hint="Relativo à raiz do repositório. Use . para a raiz."
              required
            >
              <Input {...register('working_directory')} placeholder="apps/mobile" />
            </Field>
          </div>

          <Field
            label="Branches permitidas (opcional)"
            error={fieldError('allowed_branches')}
            hint="Uma por linha. Aceita padrões simples, ex.: release/*. Vazio = qualquer branch."
          >
            <Textarea rows={3} {...register('allowed_branches')} placeholder={'main\nrelease/*'} />
          </Field>

          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" {...register('is_active')} className="size-4 accent-[var(--app-primary)]" />
            Projeto ativo
          </label>

          {mutation.isError && !(mutation.error instanceof ApiError && Object.keys(mutation.error.errors).length > 0) ? (
            <p className="rounded-md bg-[var(--app-danger-soft)] px-3 py-2 text-xs text-[var(--app-danger)]">
              {apiErrorMessage(mutation.error)}
            </p>
          ) : null}

          <div className="flex justify-end gap-2 border-t border-[var(--app-border)] pt-4">
            <Button type="button" variant="secondary" onClick={() => navigate(-1)}>
              Cancelar
            </Button>
            <Button type="submit" loading={mutation.isPending}>
              {isEdit ? 'Salvar alterações' : 'Criar projeto'}
            </Button>
          </div>
        </form>
      </Card>
    </div>
  )
}
