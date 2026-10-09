import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from 'react'
import { AlertTriangle, CheckCircle2, Info, X } from 'lucide-react'
import { cn } from '@/lib/cn'

type ToastTone = 'success' | 'error' | 'info'

interface ToastItem {
  id: number
  tone: ToastTone
  title: string
  description?: string
}

interface ToastContextValue {
  success: (title: string, description?: string) => void
  error: (title: string, description?: string) => void
  info: (title: string, description?: string) => void
}

const ToastContext = createContext<ToastContextValue | null>(null)

let nextId = 1

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<ToastItem[]>([])

  const push = useCallback((tone: ToastTone, title: string, description?: string) => {
    const id = nextId++
    setToasts((current) => [...current, { id, tone, title, description }])
    window.setTimeout(() => {
      setToasts((current) => current.filter((toast) => toast.id !== id))
    }, 6000)
  }, [])

  const value = useMemo<ToastContextValue>(
    () => ({
      success: (title, description) => push('success', title, description),
      error: (title, description) => push('error', title, description),
      info: (title, description) => push('info', title, description),
    }),
    [push],
  )

  return (
    <ToastContext.Provider value={value}>
      {children}
      <div className="pointer-events-none fixed top-4 right-4 z-[100] flex w-80 flex-col gap-2">
        {toasts.map((toast) => (
          <div
            key={toast.id}
            role="status"
            className={cn(
              'pointer-events-auto flex items-start gap-2 rounded-lg border bg-[var(--app-surface)] p-3 shadow-lg',
              toast.tone === 'success' && 'border-emerald-200',
              toast.tone === 'error' && 'border-red-200',
              toast.tone === 'info' && 'border-blue-200',
            )}
          >
            {toast.tone === 'success' ? (
              <CheckCircle2 className="mt-0.5 size-4 text-[var(--app-success)]" />
            ) : toast.tone === 'error' ? (
              <AlertTriangle className="mt-0.5 size-4 text-[var(--app-danger)]" />
            ) : (
              <Info className="mt-0.5 size-4 text-[var(--app-info)]" />
            )}
            <div className="flex-1">
              <p className="text-sm font-medium text-[var(--app-fg)]">{toast.title}</p>
              {toast.description ? (
                <p className="mt-0.5 text-xs text-[var(--app-fg-muted)]">{toast.description}</p>
              ) : null}
            </div>
            <button
              type="button"
              className="rounded p-0.5 text-[var(--app-fg-subtle)] hover:bg-[var(--app-surface-2)]"
              onClick={() => setToasts((current) => current.filter((item) => item.id !== toast.id))}
              aria-label="Fechar aviso"
            >
              <X className="size-3.5" />
            </button>
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  )
}

export function useToast(): ToastContextValue {
  const context = useContext(ToastContext)
  if (!context) throw new Error('useToast deve ser usado dentro de ToastProvider')

  return context
}
