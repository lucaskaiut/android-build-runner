import {
  useEffect,
  useRef,
  type ButtonHTMLAttributes,
  type InputHTMLAttributes,
  type KeyboardEvent,
  type ReactNode,
  type SelectHTMLAttributes,
  type TextareaHTMLAttributes,
} from 'react'
import { Link } from 'react-router'
import { AlertTriangle, ChevronLeft, ChevronRight, Loader2, X } from 'lucide-react'
import { cn } from '@/lib/cn'

/* -------------------------------------------------------------------------- */
/* Botões                                                                     */
/* -------------------------------------------------------------------------- */

type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger'
type ButtonSize = 'sm' | 'md'

const buttonBase =
  'focus-ring inline-flex shrink-0 select-none items-center justify-center gap-1.5 rounded-control font-medium transition-colors duration-100 disabled:pointer-events-none disabled:opacity-45'

const buttonVariants: Record<ButtonVariant, string> = {
  primary: 'bg-ink text-white hover:bg-ink-hover',
  secondary: 'border border-line-strong bg-surface text-fg hover:bg-fill-hover',
  ghost: 'text-fg-muted hover:bg-fill-hover hover:text-fg',
  danger: 'bg-danger text-white hover:bg-danger-hover',
}

const buttonSizes: Record<ButtonSize, string> = {
  sm: 'h-7 px-2.5 text-meta',
  md: 'h-8 px-3 text-body',
}

export function buttonClass({
  variant = 'primary',
  size = 'md',
  className,
}: {
  variant?: ButtonVariant
  size?: ButtonSize
  className?: string
} = {}) {
  return cn(buttonBase, buttonVariants[variant], buttonSizes[size], className)
}

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant
  size?: ButtonSize
  loading?: boolean
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
      className={cn(buttonBase, buttonVariants[variant], buttonSizes[size], className)}
    >
      {loading ? <Loader2 className="size-3.5 animate-spin" aria-hidden /> : null}
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
    <Link to={to} className={buttonClass({ variant, size, className })}>
      {children}
    </Link>
  )
}

interface IconButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  /** Acessível e usado também como tooltip nativo. */
  label: string
  tone?: 'default' | 'danger'
  size?: 'sm' | 'md'
}

export function IconButton({
  label,
  tone = 'default',
  size = 'md',
  className,
  children,
  ...props
}: IconButtonProps) {
  return (
    <button
      {...props}
      type={props.type ?? 'button'}
      aria-label={label}
      title={label}
      className={cn(
        buttonBase,
        size === 'sm' ? 'size-7' : 'size-8',
        tone === 'danger'
          ? 'text-fg-muted hover:bg-danger-soft hover:text-danger'
          : 'text-fg-muted hover:bg-fill-hover hover:text-fg',
        className,
      )}
    >
      {children}
    </button>
  )
}

/* -------------------------------------------------------------------------- */
/* Formulários                                                                */
/* -------------------------------------------------------------------------- */

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
    <label className={cn('flex min-w-0 flex-col gap-1.5', className)}>
      {label ? (
        <span className="text-meta font-medium text-fg">
          {label}
          {required ? (
            <span className="text-danger" aria-hidden>
              {' '}
              *
            </span>
          ) : null}
        </span>
      ) : null}
      {children}
      {hint && !error ? <span className="text-meta text-fg-subtle">{hint}</span> : null}
      {error ? (
        <span role="alert" className="text-meta text-danger">
          {error}
        </span>
      ) : null}
    </label>
  )
}

const controlBase =
  'w-full rounded-control border border-line-strong bg-surface text-fg placeholder:text-fg-subtle/75 ' +
  'transition-[border-color,box-shadow] duration-100 ' +
  'focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/25 ' +
  'disabled:cursor-not-allowed disabled:bg-inset disabled:opacity-60'

export function Input({ className, ...props }: InputHTMLAttributes<HTMLInputElement>) {
  return <input {...props} className={cn(controlBase, 'h-8 px-2.5 text-body', className)} />
}

export function Textarea({ className, ...props }: TextareaHTMLAttributes<HTMLTextAreaElement>) {
  return (
    <textarea
      {...props}
      className={cn(controlBase, 'px-2.5 py-2 font-mono text-meta leading-5', className)}
    />
  )
}

export function Select({ className, children, ...props }: SelectHTMLAttributes<HTMLSelectElement>) {
  return (
    <select {...props} className={cn(controlBase, 'h-8 px-2 text-body', className)}>
      {children}
    </select>
  )
}

/* -------------------------------------------------------------------------- */
/* Superfícies                                                                */
/* -------------------------------------------------------------------------- */

interface CardProps {
  title?: ReactNode
  description?: ReactNode
  actions?: ReactNode
  children: ReactNode
  className?: string
  bodyClassName?: string
}

export function Card({ title, description, actions, children, className, bodyClassName }: CardProps) {
  return (
    <section className={cn('rounded-surface bg-surface shadow-card', className)}>
      {title || actions ? (
        <header className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b border-line px-4 py-3">
          <div className="min-w-0">
            <h2 className="text-copy font-semibold text-fg">{title}</h2>
            {description ? <p className="mt-0.5 text-meta text-fg-subtle">{description}</p> : null}
          </div>
          {actions ? <div className="flex shrink-0 items-center gap-2">{actions}</div> : null}
        </header>
      ) : null}
      <div className={cn('p-4', bodyClassName)}>{children}</div>
    </section>
  )
}

/* -------------------------------------------------------------------------- */
/* Estado semântico                                                           */
/* -------------------------------------------------------------------------- */

export type SemanticTone = 'neutral' | 'success' | 'danger' | 'warning' | 'info' | 'accent'

const badgeTones: Record<SemanticTone, string> = {
  neutral: 'bg-inset text-fg-muted',
  success: 'bg-success-soft text-success',
  danger: 'bg-danger-soft text-danger',
  warning: 'bg-warning-soft text-warning',
  info: 'bg-info-soft text-info',
  accent: 'bg-accent-soft text-accent',
}

export function Badge({
  children,
  tone = 'neutral',
  className,
}: {
  children: ReactNode
  tone?: SemanticTone
  className?: string
}) {
  return (
    <span
      className={cn(
        'inline-flex h-5 items-center gap-1 rounded-sm px-1.5 text-micro font-medium whitespace-nowrap',
        badgeTones[tone],
        className,
      )}
    >
      {children}
    </span>
  )
}

const statusTextTones: Record<SemanticTone, string> = {
  neutral: 'text-fg-subtle',
  success: 'text-success',
  danger: 'text-danger',
  warning: 'text-warning',
  info: 'text-info',
  accent: 'text-accent',
}

/** Indicador de estado em texto + ponto: não depende apenas de cor. */
export function StatusText({
  tone = 'neutral',
  children,
  className,
}: {
  tone?: SemanticTone
  children: ReactNode
  className?: string
}) {
  return (
    <span
      className={cn('inline-flex items-center gap-1.5 text-body whitespace-nowrap', statusTextTones[tone], className)}
    >
      <span className="size-1.5 shrink-0 rounded-full bg-current" aria-hidden />
      {children}
    </span>
  )
}

/* -------------------------------------------------------------------------- */
/* Estrutura                                                                  */
/* -------------------------------------------------------------------------- */

export function PageHeader({
  breadcrumb,
  title,
  badge,
  description,
  actions,
}: {
  breadcrumb?: ReactNode
  title: ReactNode
  badge?: ReactNode
  description?: ReactNode
  actions?: ReactNode
}) {
  return (
    <header className="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
      <div className="min-w-0">
        {breadcrumb ? (
          <nav aria-label="Trilha" className="mb-1 flex items-center gap-1.5 text-meta text-fg-subtle">
            {breadcrumb}
          </nav>
        ) : null}
        <div className="flex flex-wrap items-center gap-x-2.5 gap-y-1">
          <h1 className="text-title font-semibold tracking-[-0.01em] text-fg">{title}</h1>
          {badge}
        </div>
        {description ? <div className="mt-1 text-meta text-fg-subtle">{description}</div> : null}
      </div>
      {actions ? <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div> : null}
    </header>
  )
}

export function MetaList({ children, className }: { children: ReactNode; className?: string }) {
  return <dl className={cn('grid gap-x-8 gap-y-4', className)}>{children}</dl>
}

export function MetaItem({
  label,
  children,
  mono = false,
  title,
  className,
}: {
  label: string
  children: ReactNode
  mono?: boolean
  title?: string
  className?: string
}) {
  return (
    <div className={cn('min-w-0', className)}>
      <dt className="text-meta text-fg-subtle">{label}</dt>
      <dd
        className={cn(
          'mt-0.5 min-w-0 text-copy text-fg [overflow-wrap:anywhere]',
          mono && 'font-mono text-body',
        )}
        title={title}
      >
        {children}
      </dd>
    </div>
  )
}

/* -------------------------------------------------------------------------- */
/* Abas                                                                       */
/* -------------------------------------------------------------------------- */

export function Tabs({
  items,
  active,
  onChange,
  className,
}: {
  items: Array<{ id: string; label: string; count?: number }>
  active: string
  onChange: (id: string) => void
  className?: string
}) {
  const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return
    event.preventDefault()

    const index = items.findIndex((item) => item.id === active)
    const next =
      event.key === 'ArrowRight' ? (index + 1) % items.length : (index - 1 + items.length) % items.length

    onChange(items[next].id)
    event.currentTarget.querySelectorAll<HTMLButtonElement>('[role="tab"]')[next]?.focus()
  }

  return (
    <div
      role="tablist"
      onKeyDown={onKeyDown}
      className={cn('flex items-center gap-0.5 overflow-x-auto border-b border-line', className)}
    >
      {items.map((item) => {
        const selected = item.id === active

        return (
          <button
            key={item.id}
            type="button"
            role="tab"
            id={`tab-${item.id}`}
            aria-selected={selected}
            aria-controls={`panel-${item.id}`}
            tabIndex={selected ? 0 : -1}
            onClick={() => onChange(item.id)}
            className={cn(
              'focus-ring -mb-px flex h-9 shrink-0 items-center gap-1.5 rounded-t-sm border-b-2 px-2.5 text-body transition-colors',
              selected
                ? 'border-accent font-medium text-fg'
                : 'border-transparent text-fg-muted hover:text-fg',
            )}
          >
            {item.label}
            {typeof item.count === 'number' ? (
              <span className="text-micro text-fg-subtle tabular-nums">{item.count}</span>
            ) : null}
          </button>
        )
      })}
    </div>
  )
}

/* -------------------------------------------------------------------------- */
/* Tabelas                                                                    */
/* -------------------------------------------------------------------------- */

export function TableShell({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <div className={cn('overflow-x-auto', className)}>
      <table className="w-full border-collapse text-body">{children}</table>
    </div>
  )
}

export function Th({ children, className }: { children?: ReactNode; className?: string }) {
  return (
    <th
      className={cn(
        'h-9 border-b border-line bg-inset px-3 text-left text-meta font-medium whitespace-nowrap text-fg-subtle',
        className,
      )}
    >
      {children}
    </th>
  )
}

export function Td({
  children,
  className,
  title,
}: {
  children?: ReactNode
  className?: string
  title?: string
}) {
  return (
    <td title={title} className={cn('border-b border-line px-3 py-2 align-middle', className)}>
      {children}
    </td>
  )
}

/* -------------------------------------------------------------------------- */
/* Estados de tela                                                           */
/* -------------------------------------------------------------------------- */

export function Spinner({ className }: { className?: string }) {
  return (
    <Loader2
      className={cn('size-4 animate-spin text-fg-subtle', className)}
      aria-label="Carregando"
    />
  )
}

export function LoadingBlock({ label = 'Carregando…' }: { label?: string }) {
  return (
    <div className="flex items-center justify-center gap-2 py-12 text-body text-fg-muted">
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
    <div className="flex flex-col items-center gap-1.5 px-4 py-10 text-center">
      <p className="text-body font-medium text-fg">{title}</p>
      {description ? <p className="max-w-md text-meta text-fg-subtle">{description}</p> : null}
      {action ? <div className="mt-2.5">{action}</div> : null}
    </div>
  )
}

export function ErrorState({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <div className="flex flex-col items-center gap-2 px-4 py-10 text-center">
      <AlertTriangle className="size-4 text-danger" aria-hidden />
      <p className="text-body font-medium text-fg">Não foi possível carregar os dados</p>
      <p className="max-w-lg text-meta text-fg-muted">{message}</p>
      {onRetry ? (
        <Button variant="secondary" size="sm" onClick={onRetry} className="mt-1.5">
          Tentar novamente
        </Button>
      ) : null}
    </div>
  )
}

/* -------------------------------------------------------------------------- */
/* Paginação                                                                  */
/* -------------------------------------------------------------------------- */

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
    return <p className="text-meta text-fg-subtle">{total} registro(s)</p>
  }

  return (
    <div className="flex flex-wrap items-center justify-between gap-2">
      <p className="text-meta text-fg-subtle">
        Página {page} de {lastPage} · {total} registro(s)
      </p>
      <div className="flex items-center gap-1">
        <Button
          variant="ghost"
          size="sm"
          disabled={page <= 1}
          onClick={() => onChange(page - 1)}
          aria-label="Página anterior"
        >
          <ChevronLeft className="size-3.5" aria-hidden />
          Anterior
        </Button>
        <Button
          variant="ghost"
          size="sm"
          disabled={page >= lastPage}
          onClick={() => onChange(page + 1)}
          aria-label="Próxima página"
        >
          Próxima
          <ChevronRight className="size-3.5" aria-hidden />
        </Button>
      </div>
    </div>
  )
}

/* -------------------------------------------------------------------------- */
/* Modal                                                                      */
/* -------------------------------------------------------------------------- */

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
  const panelRef = useRef<HTMLDivElement>(null)
  const onCloseRef = useRef(onClose)
  onCloseRef.current = onClose

  useEffect(() => {
    if (!open) return

    const previouslyFocused = document.activeElement as HTMLElement | null
    const focusTimer = window.setTimeout(() => panelRef.current?.focus(), 0)

    const handleKeyDown = (event: globalThis.KeyboardEvent) => {
      if (event.key === 'Escape') onCloseRef.current()
    }

    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    document.addEventListener('keydown', handleKeyDown)

    return () => {
      window.clearTimeout(focusTimer)
      document.removeEventListener('keydown', handleKeyDown)
      document.body.style.overflow = previousOverflow
      previouslyFocused?.focus?.()
    }
  }, [open])

  if (!open) return null

  return (
    <div
      className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-fg/35 p-4 pt-[7vh]"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) onClose()
      }}
    >
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-label={title}
        tabIndex={-1}
        className={cn(
          'w-full rounded-overlay bg-surface shadow-overlay outline-none',
          wide ? 'max-w-3xl' : 'max-w-lg',
        )}
      >
        <header className="flex items-start justify-between gap-4 px-5 pt-4 pb-3">
          <h2 className="min-w-0 text-copy font-semibold text-fg">{title}</h2>
          <IconButton label="Fechar" size="sm" onClick={onClose} className="-mt-0.5 -mr-1">
            <X className="size-4" aria-hidden />
          </IconButton>
        </header>
        <div className="max-h-[65vh] overflow-y-auto px-5 pb-4">{children}</div>
        {footer ? (
          <footer className="flex justify-end gap-2 border-t border-line px-5 py-3">{footer}</footer>
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
      <p className="text-body text-fg-muted">{message}</p>
    </Modal>
  )
}
