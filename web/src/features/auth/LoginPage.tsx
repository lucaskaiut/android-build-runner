import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useNavigate } from 'react-router'
import { HardDriveDownload } from 'lucide-react'
import { Button, Field, Input } from '@/components/ui'
import { apiErrorMessage } from '@/lib/api'
import { useLogin } from './useAuth'

const schema = z.object({
  email: z.string().min(1, 'Informe o e-mail.').email('Informe um e-mail válido.'),
  password: z.string().min(1, 'Informe a senha.'),
})

type FormValues = z.infer<typeof schema>

export function LoginPage() {
  const navigate = useNavigate()
  const login = useLogin()

  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { email: '', password: '' },
  })

  const onSubmit = handleSubmit(async (values) => {
    try {
      await login.mutateAsync(values)
      navigate('/', { replace: true })
    } catch {
      // Erro exibido abaixo pelo estado da mutação.
    }
  })

  return (
    <div className="flex min-h-screen items-center justify-center bg-[var(--app-bg)] px-4">
      <div className="w-full max-w-sm">
        <div className="mb-6 flex flex-col items-center gap-2 text-center">
          <div className="flex size-10 items-center justify-center rounded-lg bg-[var(--app-primary)] text-white">
            <HardDriveDownload className="size-5" />
          </div>
          <h1 className="text-lg font-semibold">Local Build Platform</h1>
          <p className="text-xs text-[var(--app-fg-muted)]">Entre para gerenciar projetos e builds.</p>
        </div>

        <form
          onSubmit={onSubmit}
          className="flex flex-col gap-4 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] p-5 shadow-sm"
          noValidate
        >
          <Field label="E-mail" error={errors.email?.message} required>
            <Input type="email" autoComplete="username" autoFocus {...register('email')} />
          </Field>

          <Field label="Senha" error={errors.password?.message} required>
            <Input type="password" autoComplete="current-password" {...register('password')} />
          </Field>

          {login.isError ? (
            <p className="rounded-md bg-[var(--app-danger-soft)] px-3 py-2 text-xs text-[var(--app-danger)]">
              {apiErrorMessage(login.error)}
            </p>
          ) : null}

          <Button type="submit" loading={login.isPending}>
            Entrar
          </Button>
        </form>

        <p className="mt-4 text-center text-[11px] text-[var(--app-fg-subtle)]">
          Acesso restrito. Sessões expiradas exigem novo login.
        </p>
      </div>
    </div>
  )
}
