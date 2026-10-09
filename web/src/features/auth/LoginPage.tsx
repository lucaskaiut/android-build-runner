import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useNavigate } from 'react-router'
import { AlertTriangle, HardDriveDownload } from 'lucide-react'
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
    <div className="flex min-h-screen items-center justify-center bg-canvas px-4 py-10">
      <div className="w-full max-w-[360px]">
        <div className="mb-6 flex items-center gap-2.5">
          <span className="flex size-8 shrink-0 items-center justify-center rounded-control bg-ink text-white">
            <HardDriveDownload className="size-4" aria-hidden />
          </span>
          <div className="leading-tight">
            <p className="text-copy font-semibold text-fg">Local Build Platform</p>
            <p className="text-meta text-fg-subtle">Builds Expo / React Native em runner local</p>
          </div>
        </div>

        <form onSubmit={onSubmit} className="rounded-surface bg-surface p-5 shadow-card" noValidate>
          <h1 className="text-copy font-semibold text-fg">Entrar</h1>
          <p className="mt-0.5 text-meta text-fg-subtle">Acesso restrito à equipe.</p>

          <div className="mt-4 flex flex-col gap-3.5">
            <Field label="E-mail" error={errors.email?.message} required>
              <Input type="email" autoComplete="username" autoFocus {...register('email')} />
            </Field>

            <Field label="Senha" error={errors.password?.message} required>
              <Input type="password" autoComplete="current-password" {...register('password')} />
            </Field>

            {login.isError ? (
              <p className="flex items-start gap-2 rounded-control bg-danger-soft px-3 py-2 text-body text-danger">
                <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden />
                {apiErrorMessage(login.error)}
              </p>
            ) : null}

            <Button type="submit" loading={login.isPending} className="mt-1 w-full">
              Entrar
            </Button>
          </div>
        </form>

        <p className="mt-4 text-center text-micro text-fg-subtle">
          Sessões expiradas exigem novo login.
        </p>
      </div>
    </div>
  )
}
