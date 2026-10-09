import { NavLink, Outlet, useNavigate } from 'react-router'
import { HardDriveDownload, LayoutDashboard, FolderKanban, Hammer, Server, LogOut, Plus } from 'lucide-react'
import { Button } from '@/components/ui'
import { useToast } from '@/components/toast'
import { cn } from '@/lib/cn'
import { useCurrentUser, useLogout } from '@/features/auth/useAuth'

const navItems = [
  { to: '/', label: 'Dashboard', icon: LayoutDashboard, end: true },
  { to: '/projects', label: 'Projetos', icon: FolderKanban, end: false },
  { to: '/builds', label: 'Builds', icon: Hammer, end: false },
  { to: '/runners', label: 'Runners', icon: Server, end: false },
]

export function AppLayout() {
  const { data } = useCurrentUser()
  const logout = useLogout()
  const toast = useToast()
  const navigate = useNavigate()

  const handleLogout = async () => {
    try {
      await logout.mutateAsync()
      navigate('/login', { replace: true })
    } catch {
      toast.error('Falha ao encerrar a sessão')
    }
  }

  return (
    <div className="flex min-h-screen flex-col md:flex-row">
      <aside className="flex shrink-0 flex-col border-b border-[var(--app-border)] bg-[var(--app-surface)] md:w-56 md:border-r md:border-b-0">
        <div className="flex items-center gap-2 px-4 py-4">
          <div className="flex size-8 items-center justify-center rounded-md bg-[var(--app-primary)] text-white">
            <HardDriveDownload className="size-4" />
          </div>
          <div className="leading-tight">
            <p className="text-sm font-semibold">Build Platform</p>
            <p className="text-[11px] text-[var(--app-fg-subtle)]">runner local</p>
          </div>
        </div>

        <nav className="flex gap-1 overflow-x-auto px-2 pb-2 md:flex-col md:gap-0.5 md:pb-0">
          {navItems.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.end}
              className={({ isActive }) =>
                cn(
                  'flex items-center gap-2 rounded-md px-3 py-2 text-sm whitespace-nowrap transition-colors',
                  isActive
                    ? 'bg-[var(--app-surface-2)] font-medium text-[var(--app-fg)]'
                    : 'text-[var(--app-fg-muted)] hover:bg-[var(--app-surface-2)]',
                )
              }
            >
              <item.icon className="size-4" />
              {item.label}
            </NavLink>
          ))}
          <NavLink
            to="/builds/new"
            className="flex items-center gap-2 rounded-md px-3 py-2 text-sm whitespace-nowrap text-[var(--app-fg-muted)] transition-colors hover:bg-[var(--app-surface-2)]"
          >
            <Plus className="size-4" />
            Nova build
          </NavLink>
        </nav>

        <div className="mt-auto hidden border-t border-[var(--app-border)] p-3 md:block">
          <p className="truncate text-xs font-medium">{data?.user.name}</p>
          <p className="truncate text-[11px] text-[var(--app-fg-subtle)]">{data?.user.email}</p>
          <Button variant="secondary" size="sm" className="mt-2 w-full" onClick={handleLogout} loading={logout.isPending}>
            <LogOut className="size-3.5" /> Sair
          </Button>
        </div>
      </aside>

      <main className="min-w-0 flex-1">
        <div className="mx-auto max-w-6xl px-4 py-6 md:px-6">
          <Outlet />
        </div>
      </main>
    </div>
  )
}
