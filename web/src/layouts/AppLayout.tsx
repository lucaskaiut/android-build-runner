import { NavLink, Outlet, useNavigate } from 'react-router'
import {
  FolderKanban,
  Hammer,
  HardDriveDownload,
  LayoutDashboard,
  LogOut,
  Plus,
  Server,
} from 'lucide-react'
import { ButtonLink, IconButton, Spinner } from '@/components/ui'
import { useToast } from '@/components/toast'
import { cn } from '@/lib/cn'
import { useCurrentUser, useLogout } from '@/features/auth/useAuth'

const navItems = [
  { to: '/', label: 'Dashboard', icon: LayoutDashboard, end: true },
  { to: '/projects', label: 'Projetos', icon: FolderKanban, end: false },
  { to: '/builds', label: 'Builds', icon: Hammer, end: false },
  { to: '/runners', label: 'Runners', icon: Server, end: false },
]

function navItemClass(isActive: boolean) {
  return cn(
    'focus-ring flex h-8 items-center gap-2.5 rounded-control px-2.5 text-body whitespace-nowrap transition-colors',
    isActive
      ? 'bg-accent-soft font-medium text-accent'
      : 'text-fg-muted hover:bg-fill-hover hover:text-fg',
  )
}

function Brand() {
  return (
    <div className="flex items-center gap-2.5">
      <span className="flex size-7 shrink-0 items-center justify-center rounded-control bg-ink text-white">
        <HardDriveDownload className="size-4" aria-hidden />
      </span>
      <span className="min-w-0 leading-tight">
        <span className="block truncate text-body font-semibold text-fg">Build Platform</span>
        <span className="block text-micro text-fg-subtle">runner local · self-hosted</span>
      </span>
    </div>
  )
}

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

  const logoutButton = (
    <IconButton label="Sair" onClick={handleLogout} disabled={logout.isPending}>
      {logout.isPending ? <Spinner className="size-3.5" /> : <LogOut className="size-4" aria-hidden />}
    </IconButton>
  )

  return (
    <div className="flex min-h-screen bg-canvas">
      <aside className="hidden w-[232px] shrink-0 flex-col bg-panel md:flex">
        <div className="px-4 pt-5 pb-4">
          <Brand />
        </div>

        <div className="px-3 pb-3">
          <ButtonLink to="/builds/new" className="w-full">
            <Plus className="size-4" aria-hidden /> Nova build
          </ButtonLink>
        </div>

        <nav aria-label="Navegação principal" className="flex flex-col gap-0.5 px-2">
          {navItems.map((item) => (
            <NavLink key={item.to} to={item.to} end={item.end} className={({ isActive }) => navItemClass(isActive)}>
              <item.icon className="size-4 shrink-0" aria-hidden />
              {item.label}
            </NavLink>
          ))}
        </nav>

        <div className="mt-auto border-t border-line p-3">
          <div className="flex items-center gap-2">
            <div className="min-w-0 flex-1 px-1">
              <p className="truncate text-body font-medium text-fg">{data?.user.name}</p>
              <p className="truncate text-micro text-fg-subtle">{data?.user.email}</p>
            </div>
            {logoutButton}
          </div>
        </div>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex items-center justify-between gap-3 bg-panel px-4 py-3 md:hidden">
          <Brand />
          {logoutButton}
        </header>

        <nav
          aria-label="Navegação principal"
          className="flex gap-1 overflow-x-auto bg-panel px-3 pb-2 md:hidden"
        >
          {navItems.map((item) => (
            <NavLink key={item.to} to={item.to} end={item.end} className={({ isActive }) => navItemClass(isActive)}>
              <item.icon className="size-4 shrink-0" aria-hidden />
              {item.label}
            </NavLink>
          ))}
        </nav>

        <main className="min-w-0 flex-1">
          <div className="mx-auto w-full max-w-[1280px] px-4 py-5 md:px-7 md:py-6">
            <Outlet />
          </div>
        </main>
      </div>
    </div>
  )
}
