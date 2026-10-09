import { Navigate, Outlet, createBrowserRouter } from 'react-router'
import { AppLayout } from '@/layouts/AppLayout'
import { LoginPage } from '@/features/auth/LoginPage'
import { useCurrentUser } from '@/features/auth/useAuth'
import { Spinner } from '@/components/ui'
import { DashboardPage } from '@/features/dashboard/DashboardPage'
import { ProjectsPage } from '@/features/projects/ProjectsPage'
import { ProjectFormPage } from '@/features/projects/ProjectFormPage'
import { ProjectDetailPage } from '@/features/projects/ProjectDetailPage'
import { BuildsPage } from '@/features/builds/BuildsPage'
import { BuildNewPage } from '@/features/builds/BuildNewPage'
import { BuildDetailPage } from '@/features/builds/BuildDetailPage'
import { RunnersPage } from '@/features/runners/RunnersPage'

function RequireAuth() {
  const { data, isLoading, isError } = useCurrentUser()

  if (isLoading) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <Spinner className="size-6" />
      </div>
    )
  }

  if (isError || !data) {
    return <Navigate to="/login" replace />
  }

  return <Outlet />
}

function LoginRoute() {
  const { data, isLoading } = useCurrentUser()

  if (isLoading) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <Spinner className="size-6" />
      </div>
    )
  }

  if (data) {
    return <Navigate to="/" replace />
  }

  return <LoginPage />
}

export const router = createBrowserRouter([
  { path: '/login', element: <LoginRoute /> },
  {
    path: '/',
    element: <RequireAuth />,
    children: [
      {
        element: <AppLayout />,
        children: [
          { index: true, element: <DashboardPage /> },
          { path: 'projects', element: <ProjectsPage /> },
          { path: 'projects/new', element: <ProjectFormPage /> },
          { path: 'projects/:id', element: <ProjectDetailPage /> },
          { path: 'projects/:id/edit', element: <ProjectFormPage /> },
          { path: 'builds', element: <BuildsPage /> },
          { path: 'builds/new', element: <BuildNewPage /> },
          { path: 'builds/:id', element: <BuildDetailPage /> },
          { path: 'runners', element: <RunnersPage /> },
        ],
      },
    ],
  },
  { path: '*', element: <Navigate to="/" replace /> },
])
