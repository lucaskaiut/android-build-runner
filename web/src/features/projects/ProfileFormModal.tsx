import { useEffect, useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Plus, Trash2 } from 'lucide-react'
import { Button, Field, Input, Modal, Select, Textarea } from '@/components/ui'
import { useToast } from '@/components/toast'
import { api, apiErrorMessage, type ProfileInput } from '@/lib/api'
import type { BuildProfile, ProfileParameterDefinition } from '@/lib/types'

const schema = z.object({
  name: z.string().min(1, 'Informe o nome.').max(120),
  slug: z
    .string()
    .min(1, 'Informe o identificador.')
    .max(80)
    .regex(/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/, 'Use apenas minúsculas, números, "-" e "_".'),
  build_script: z.string().min(1, 'Informe o script de build.').max(100000),
  working_directory: z
    .string()
    .refine(
      (value) => !value || (!value.includes('..') && !value.startsWith('/') && !value.includes('\\')),
      'Use um caminho relativo sem "..".',
    ),
  timeout_seconds: z.number().int().min(30, 'Mínimo de 30 segundos.').max(21600, 'Máximo de 6 horas.'),
  artifact_patterns: z.string(),
  env: z.string(),
  is_active: z.boolean(),
})

type FormValues = z.infer<typeof schema>

interface ParameterRow extends ProfileParameterDefinition {
  uid: number
}

let parameterUid = 1

function parseLines(value: string): string[] {
  return value
    .split('\n')
    .map((line) => line.trim())
    .filter(Boolean)
}

function parseEnv(value: string): Record<string, string> {
  const result: Record<string, string> = {}
  for (const line of parseLines(value)) {
    const separator = line.indexOf('=')
    if (separator <= 0) throw new Error(`Variável inválida: "${line}". Use KEY=valor.`)
    const key = line.slice(0, separator).trim()
    if (!/^[A-Z][A-Z0-9_]*$/.test(key)) {
      throw new Error(`A chave "${key}" deve usar MAIÚSCULAS, números e "_".`)
    }
    result[key] = line.slice(separator + 1)
  }
  return result
}

function serializeEnv(env: Record<string, string> | null): string {
  return Object.entries(env ?? {})
    .map(([key, value]) => `${key}=${value}`)
    .join('\n')
}

export function ProfileFormModal({
  open,
  projectId,
  profile,
  onClose,
}: {
  open: boolean
  projectId: number
  profile: BuildProfile | null
  onClose: () => void
}) {
  const isEdit = profile !== null
  const queryClient = useQueryClient()
  const toast = useToast()
  const [parameters, setParameters] = useState<ParameterRow[]>([])
  const [envError, setEnvError] = useState<string | null>(null)

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
      build_script: '',
      working_directory: '',
      timeout_seconds: 3600,
      artifact_patterns: '',
      env: '',
      is_active: true,
    },
  })

  useEffect(() => {
    if (!open) return

    if (profile) {
      reset({
        name: profile.name,
        slug: profile.slug,
        build_script: profile.build_script,
        working_directory: profile.working_directory ?? '',
        timeout_seconds: profile.timeout_seconds,
        artifact_patterns: (profile.artifact_patterns ?? []).join('\n'),
        env: serializeEnv(profile.env),
        is_active: profile.is_active,
      })
      setParameters((profile.parameters ?? []).map((item) => ({ ...item, uid: parameterUid++ })))
    } else {
      reset({
        name: '',
        slug: '',
        build_script: '',
        working_directory: '',
        timeout_seconds: 3600,
        artifact_patterns: '',
        env: '',
        is_active: true,
      })
      setParameters([])
    }
    setEnvError(null)
  }, [open, profile, reset])

  const mutation = useMutation({
    mutationFn: async (values: FormValues) => {
      let env: Record<string, string>
      try {
        env = parseEnv(values.env)
        setEnvError(null)
      } catch (error) {
        setEnvError(error instanceof Error ? error.message : 'Variáveis de ambiente inválidas.')
        throw error
      }

      const payload: ProfileInput = {
        name: values.name,
        slug: values.slug,
        build_script: values.build_script,
        working_directory: values.working_directory.trim() || null,
        timeout_seconds: values.timeout_seconds,
        artifact_patterns: parseLines(values.artifact_patterns),
        env,
        parameters: parameters
          .filter((parameter) => parameter.key.trim() !== '')
          .map((parameter) => ({
            key: parameter.key.trim(),
            label: parameter.label?.trim() || undefined,
            required: Boolean(parameter.required),
            default: parameter.default?.trim() || null,
            options: parameter.options?.filter((option) => option.trim() !== ''),
          })),
        is_active: values.is_active,
      }

      return isEdit ? api.updateProfile(profile.id, payload) : api.createProfile(projectId, payload)
    },
    onSuccess: () => {
      toast.success(isEdit ? 'Perfil atualizado' : 'Perfil criado')
      void queryClient.invalidateQueries({ queryKey: ['project', projectId] })
      onClose()
    },
  })

  const onSubmit = handleSubmit(async (values) => {
    try {
      await mutation.mutateAsync(values)
    } catch {
      // Erro exibido abaixo.
    }
  })

  const updateParameter = (uid: number, patch: Partial<ParameterRow>) => {
    setParameters((current) => current.map((item) => (item.uid === uid ? { ...item, ...patch } : item)))
  }

  return (
    <Modal
      open={open}
      title={isEdit ? `Editar perfil — ${profile?.name}` : 'Novo perfil de build'}
      onClose={onClose}
      wide
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={mutation.isPending}>
            Cancelar
          </Button>
          <Button onClick={onSubmit} loading={mutation.isPending}>
            {isEdit ? 'Salvar perfil' : 'Criar perfil'}
          </Button>
        </>
      }
    >
      <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Nome" error={errors.name?.message} required>
            <Input {...register('name')} placeholder="Preview" />
          </Field>
          <Field label="Identificador (slug)" error={errors.slug?.message} required>
            <Input {...register('slug')} placeholder="preview" />
          </Field>
        </div>

        <Field
          label="Script de build (PowerShell)"
          error={errors.build_script?.message}
          hint="Executado no Windows dentro do workspace da build. Falhe com exit code != 0. Use $env:APP_DIR, $env:BUILD_COMMIT, etc."
          required
        >
          <Textarea rows={10} {...register('build_script')} spellCheck={false} />
        </Field>

        <div className="grid gap-4 sm:grid-cols-3">
          <Field
            label="Diretório de execução"
            error={errors.working_directory?.message}
            hint="Vazio = herda do projeto"
          >
            <Input {...register('working_directory')} placeholder="apps/mobile" />
          </Field>
          <Field label="Timeout (segundos)" error={errors.timeout_seconds?.message} required>
            <Input type="number" min={30} max={21600} {...register('timeout_seconds', { valueAsNumber: true })} />
          </Field>
          <Field label="Perfil ativo">
            <Select {...register('is_active', { setValueAs: (value) => value === 'true' || value === true })}>
              <option value="true">Sim</option>
              <option value="false">Não</option>
            </Select>
          </Field>
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            label="Padrões de artefatos"
            hint="Um por linha, relativo ao diretório de execução. Ex.: android/app/build/outputs/apk/release/*.apk"
            error={errors.artifact_patterns?.message}
          >
            <Textarea rows={3} {...register('artifact_patterns')} placeholder="out/*.apk" />
          </Field>
          <Field
            label="Variáveis de ambiente (não sensíveis)"
            hint="Uma por linha no formato KEY=valor."
            error={envError ?? undefined}
          >
            <Textarea rows={3} {...register('env')} placeholder="APP_VARIANT=production" />
          </Field>
        </div>

        <div className="flex flex-col gap-2">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-[13px] font-medium">Parâmetros permitidos</p>
              <p className="text-xs text-[var(--app-fg-subtle)]">
                Valores que o operador pode informar ao criar uma build. Chegam como $env:BUILD_PARAM_&lt;CHAVE&gt;.
              </p>
            </div>
            <Button
              type="button"
              variant="secondary"
              size="sm"
              onClick={() => setParameters((current) => [...current, { key: '', uid: parameterUid++ }])}
            >
              <Plus className="size-3.5" /> Adicionar
            </Button>
          </div>

          {parameters.map((parameter) => (
            <div key={parameter.uid} className="grid grid-cols-12 items-end gap-2 rounded-md border border-[var(--app-border)] p-2">
              <div className="col-span-3">
                <Field label="Chave">
                  <Input
                    value={parameter.key}
                    onChange={(event) => updateParameter(parameter.uid, { key: event.target.value })}
                    placeholder="variant"
                  />
                </Field>
              </div>
              <div className="col-span-3">
                <Field label="Rótulo">
                  <Input
                    value={parameter.label ?? ''}
                    onChange={(event) => updateParameter(parameter.uid, { label: event.target.value })}
                    placeholder="Variante"
                  />
                </Field>
              </div>
              <div className="col-span-2">
                <Field label="Obrigatório">
                  <Select
                    value={parameter.required ? 'true' : 'false'}
                    onChange={(event) => updateParameter(parameter.uid, { required: event.target.value === 'true' })}
                  >
                    <option value="false">Não</option>
                    <option value="true">Sim</option>
                  </Select>
                </Field>
              </div>
              <div className="col-span-3">
                <Field label="Opções / padrão">
                  <Input
                    value={(parameter.options ?? []).join(', ') || parameter.default || ''}
                    onChange={(event) => {
                      const raw = event.target.value
                      if (raw.includes(',')) {
                        updateParameter(parameter.uid, {
                          options: raw.split(',').map((item) => item.trim()).filter(Boolean),
                          default: null,
                        })
                      } else {
                        updateParameter(parameter.uid, { default: raw, options: [] })
                      }
                    }}
                    placeholder="preview, production"
                  />
                </Field>
              </div>
              <div className="col-span-1">
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  onClick={() => setParameters((current) => current.filter((item) => item.uid !== parameter.uid))}
                  aria-label="Remover parâmetro"
                >
                  <Trash2 className="size-3.5" />
                </Button>
              </div>
            </div>
          ))}
        </div>

        {mutation.isError ? (
          <p className="rounded-md bg-[var(--app-danger-soft)] px-3 py-2 text-xs text-[var(--app-danger)]">
            {apiErrorMessage(mutation.error)}
          </p>
        ) : null}
      </form>
    </Modal>
  )
}
