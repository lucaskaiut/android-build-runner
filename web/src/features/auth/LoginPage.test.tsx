import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ToastProvider } from '@/components/toast'
import { ApiError } from '@/lib/api'
import { LoginPage } from './LoginPage'

// Only the HTTP call is mocked; the real useLogin hook (TanStack Mutation)
// drives the component state transitions.
vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()

  return {
    ...actual,
    api: {
      ...actual.api,
      login: vi.fn(),
    },
  }
})

import { api } from '@/lib/api'

const loginMock = vi.mocked(api.login)

function renderLogin() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  return render(
    <QueryClientProvider client={queryClient}>
      <ToastProvider>
        <MemoryRouter initialEntries={['/login']}>
          <Routes>
            <Route path="/login" element={<LoginPage />} />
            <Route path="/" element={<div>Dashboard pronto</div>} />
          </Routes>
        </MemoryRouter>
      </ToastProvider>
    </QueryClientProvider>,
  )
}

describe('LoginPage', () => {
  beforeEach(() => {
    loginMock.mockReset()
  })

  it('validates required fields before submitting', async () => {
    const user = userEvent.setup()
    renderLogin()

    await user.click(screen.getByRole('button', { name: /entrar/i }))

    expect(await screen.findByText('Informe o e-mail.')).toBeInTheDocument()
    expect(screen.getByText('Informe a senha.')).toBeInTheDocument()
    expect(loginMock).not.toHaveBeenCalled()
  })

  it('navigates to the dashboard after a successful login', async () => {
    loginMock.mockResolvedValue({
      user: { id: 1, name: 'Admin', email: 'admin@local.test', is_admin: true, created_at: '2026-01-01T00:00:00Z' },
    })

    const user = userEvent.setup()
    renderLogin()

    await user.type(screen.getByLabelText(/e-mail/i), 'admin@local.test')
    await user.type(screen.getByLabelText(/senha/i), 'secret-password')
    await user.click(screen.getByRole('button', { name: /entrar/i }))

    await waitFor(() =>
      expect(loginMock).toHaveBeenCalledWith('admin@local.test', 'secret-password'),
    )
    expect(await screen.findByText('Dashboard pronto')).toBeInTheDocument()
  })

  it('shows the API error message when credentials are rejected', async () => {
    loginMock.mockRejectedValue(new ApiError('E-mail ou senha inválidos.', 422))

    const user = userEvent.setup()
    renderLogin()

    await user.type(screen.getByLabelText(/e-mail/i), 'admin@local.test')
    await user.type(screen.getByLabelText(/senha/i), 'wrong-password')
    await user.click(screen.getByRole('button', { name: /entrar/i }))

    expect(await screen.findByText('E-mail ou senha inválidos.')).toBeInTheDocument()
  })
})
