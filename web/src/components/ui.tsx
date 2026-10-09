import type { ButtonHTMLAttributes, InputHTMLAttributes, ReactNode, SelectHTMLAttributes, TextareaHTMLAttributes } from 'react'
import { Link } from 'react-router'
import { Loader2, X } from 'lucide-react'
import { cn } from '@/lib/cn'

type ButtonVariant = 'primary' | 'secondary' | 'danger' | 'ghost'
type ButtonSize = 'sm' | 'md'

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant
  size?: ButtonSize
  loading?: boolean
}

const buttonVariants: Record<ButtonVariant, string> = {
  primary:
    'bg-[var(--app-primary)] text-[var(--app-primary-fg)] hover:bg-[var(--app-primary-hover)] border border-transparent',
  secondary:
    'bg-[var(--app-surface)] text-[var(--app-fg)] border border-[var(--app-border-strong)] hover:bg-[var(--app-surface-2)]',
  danger:
    'bg-[var(--app-danger)] text-white hover:bg-red-700 border border-transparent',
  ghost: 'bg-transparent text-[var(--app-fg-muted)] hover:bg-[var(--app-surface-2)] border border-transparent',
}

const buttonSizes: Record<ButtonSize, string> = {
  sm: 'h-8 px-2.5 text-[13px] gap-1.5',
  md: 'h-9 px-3.5 text-sm gap-2',
}

export function Button({
  variant = 'primary',
  size = 'md',
  loading = false,
  disabled,
  className,
  children,
  ...props
}: ButtonProps) {
  return (
    <button
      {...props}
      disabled={disabled || loading}
      className={cn(
        'inline-flex items-center justify-center rounded-md font-medium transition-colors',
        'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--app-ring)]',
        'disabled:pointer-events-none disabled:opacity-50',
        buttonVariants[variant],
        buttonSizes[size],
        className,
      )}
    >
      {loading ? <Loader2 className="size-4 animate-spin" aria-hidden /> : null}
      {children}
    </button>
  )
}

export function ButtonLink({
  to,
  variant = 'primary',
  size = 'md',
  className,
  children,
}: {
  to: string
  variant?: ButtonVariant
  size?: ButtonSize
  className?: string
  children: ReactNode
}) {
  return (
    <Link
      to={to}
      className={cn(
        'inline-flex items-center justify-center rounded-md font-medium transition-colors',
        'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--app-ring)]',
        buttonVariants[variant],
        buttonSizes[size],
        className,
      )}
    >
      {children}
    </Link>
  )
}

interface FieldProps {
  label?: string
  hint?: string
  error?: string
  required?: boolean
  children: ReactNode
  className?: string
}

export function Field({ label, hint, error, required, children, className }: FieldProps) {
  return (
    <label className={cn('flex flex-col gap-1.5', className)}>
      {label ? (
        <span className="text-[13px] font-medium text-[var(--app-fg)]">
          {label}
          {required ? <span className="text-[var(--app-danger)]"> *</span> : null}
        </span>
      ) : null}
      {children}
      {hint && !error ? <span className="text-xs text-[var(--app-fg-subtle)]">{hint}</span> : null}
      {error ? <span className="text-xs text-[var(--app-danger)]">{error}</span> : null}
    </label>
  )
}

const controlClass =
  'w-full rounded-md border border-[var(--app-border-strong)] bg-[var(--app-surface)] px-3 py-2 text-sm text-[var(--app-fg)] ' +
  'placeholder:text-[var(--app-fg-subtle)] focus:border-[var(--app-accent)] focus:outline-2 focus:outline-offset-0 focus:outline-[var(--app-ring)] disabled:opacity-60'

export function Input({ className, ...props }: InputHTMLAttributes<HTMLInputElement>) {
  return <input {...props} className={cn(controlClass, 'h-9 py-0', className)} />
}

export function Textarea({ className, ...props }: TextareaHTMLAttributes<HTMLTextAreaElement>) {
  return <textarea {...props} className={cn(controlClass, 'font-mono text-[13px] leading-relaxed', className)} />
}

export function Select({ className, children, ...props }: SelectHTMLAttributes<HTMLSelectElement>) {
  return (
    <select {...props} className={cn(controlClass, 'h-9 py-0', className)}>
      {children}
    </select>
  )
}

interface CardProps {
  title?: ReactNode
  description?: string
  actions?: ReactNode
  children: ReactNode
  className?: string
  bodyClassName?: string
}

export function Card({ title, description, actions, children, className, bodyClassName }: CardProps) {
  return (
    <section
      className={cn(
        'rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] shadow-[0_1px_2px_rgb(16_24_40_/_0.04)]',
        className,
      )}
    >
      {title || actions ? (
        <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--app-border)] px-4 py-3">
          <div>
            <h2 className="text-sm font-semibold text-[var(--app-fg)]">{title}</h2>
            {description ? <p className="mt-0.5 text-xs text-[var(--app-fg-subtle)]">{description}</p> : null}
          </div>
          {actions ? <div className="flex items-center gap-2">{actions}</div> : null}
        </header>
      ) : null}
      <div className={cn('p-4', bodyClassName)}>{children}</div>
    </section>
  )
}

export function Badge({
  children,
  tone = 'neutral',
  className,
}: {
  children: ReactNode
  tone?: 'neutral' | 'success' | 'danger' | 'warning' | 'info' | 'accent'
  className?: string
}) {
  const tones = {
    neutral: 'bg-[var(--app-surface-3)] text-[var(--app-fg-muted)]',
    success: 'bg-[var(--app-success-soft)] text-[var(--app-success)]',
    danger: 'bg-[var(--app-danger-soft)] text-[var(--app-danger)]',
    warning: 'bg-[var(--app-warning-soft)] text-[var(--app-warning)]',
    info: 'bg-[var(--app-info-soft)] text-[var(--app-info)]',
    accent: 'bg-[var(--app-accent-soft)] text-[var(--app-accent)]',
  } as const

  return (
    <span
      className={cn(
        'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap',
        tones[tone],
        className,
      )}
    >
      {children}
    </span>
  )
}

export function Spinner({ className }: { className?: string }) {
  return <Loader2 className={cn('size-5 animate-spin text-[var(--app-fg-subtle)]', className)} aria-label="Carregando" />
}

export function LoadingBlock({ label = 'Carregando…' }: { label?: string }) {
  return (
    <div className="flex items-center justify-center gap-2 py-12 text-sm text-[var(--app-fg-muted)]">
      <Spinner /> {label}
    </div>
  )
}

export function EmptyState({
  title,
  description,
  action,
}: {
  title: string
  description?: string
  action?: ReactNode
}) {
  return (
    <div className="flex flex-col items-center gap-2 py-12 text-center">
      <p className="text-sm font-medium text-[var(--app-fg)]">{title}</p>
      {description ? <p className="max-w-md text-xs text-[var(--app-fg-subtle)]">{description}</p> : null}
      {action ? <div className="mt-2">{action}</div> : null}
    </div>
  )
}

export function ErrorState({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <div className="flex flex-col items-center gap-3 py-12 text-center">
      <p className="text-sm font-medium text-[var(--app-danger)]">Não foi possível carregar os dados</p>
      <p className="max-w-lg text-xs text-[var(--app-fg-muted)]">{message}</p>
      {onRetry ? (
        <Button variant="secondary" size="sm" onClick={onRetry}>
          Tentar novamente
        </Button>
      ) : null}
    </div>
  )
}

export function Pagination({
  page,
  lastPage,
  total,
  onChange,
}: {
  page: number
  lastPage: number
  total: number
  onChange: (page: number) => void
}) {
  if (lastPage <= 1) {
    return <p className="px-1 text-xs text-[var(--app-fg-subtle)]">{total} registro(s)</p>
  }

  return (
    <div className="flex items-center justify-between gap-3 px-1">
      <p className="text-xs text-[var(--app-fg-subtle)]">
        Página {page} de {lastPage} — {total} registro(s)
      </p>
      <div className="flex gap-2">
        <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => onChange(page - 1)}>
          Anterior
        </Button>
        <Button variant="secondary" size="sm" disabled={page >= lastPage} onClick={() => onChange(page + 1)}>
          Próxima
        </Button>
      </div>
    </div>
  )
}

export function Modal({
  open,
  title,
  onClose,
  children,
  footer,
  wide = false,
}: {
  open: boolean
  title: string
  onClose: () => void
  children: ReactNode
  footer?: ReactNode
  wide?: boolean
}) {
  if (!open) return null

  return (
    <div
      className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4 pt-[8vh]"
      role="dialog"
      aria-modal="true"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) onClose()
      }}
    >
      <div
        className={cn(
          'w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] shadow-xl',
          wide ? 'max-w-3xl' : 'max-w-lg',
        )}
      >
        <header className="flex items-center justify-between border-b border-[var(--app-border)] px-4 py-3">
          <h2 className="text-sm font-semibold">{title}</h2>
          <button
            type="button"
            onClick={onClose}
            className="rounded p-1 text-[var(--app-fg-subtle)] hover:bg-[var(--app-surface-2)]"
            aria-label="Fechar"
          >
            <X className="size-4" />
          </button>
        </header>
        <div className="max-h-[70vh] overflow-y-auto p-4">{children}</div>
        {footer ? (
          <footer className="flex justify-end gap-2 border-t border-[var(--app-border)] px-4 py-3">{footer}</footer>
        ) : null}
      </div>
    </div>
  )
}

export function ConfirmDialog({
  open,
  title,
  message,
  confirmLabel = 'Confirmar',
  destructive = false,
  loading = false,
  onConfirm,
  onClose,
}: {
  open: boolean
  title: string
  message: string
  confirmLabel?: string
  destructive?: boolean
  loading?: boolean
  onConfirm: () => void
  onClose: () => void
}) {
  return (
    <Modal
      open={open}
      title={title}
      onClose={onClose}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={loading}>
            Cancelar
          </Button>
          <Button variant={destructive ? 'danger' : 'primary'} onClick={onConfirm} loading={loading}>
            {confirmLabel}
          </Button>
        </>
      }
    >
      <p className="text-sm text-[var(--app-fg-muted)]">{message}</p>
    </Modal>
  )
}

export function TableShell({ children }: { children: ReactNode }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full border-collapse text-sm">{children}</table>
    </div>
  )
}

export function Th({ children, className }: { children?: ReactNode; className?: string }) {
  return (
    <th
      className={cn(
        'border-b border-[var(--app-border)] px-3 py-2 text-left text-xs font-semibold tracking-wide text-[var(--app-fg-subtle)] uppercase',
        className,
      )}
    >
      {children}
    </th>
  )
}

export function Td({ children, className, title }: { children?: ReactNode; className?: string; title?: string }) {
  return (
    <td title={title} className={cn('border-b border-[var(--app-border)] px-3 py-2.5 align-middle', className)}>
      {children}
    </td>
  )
}
