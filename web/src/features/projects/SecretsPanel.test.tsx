import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ToastProvider } from '@/components/toast'
import { SecretsPanel } from './SecretsPanel'

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()

  return {
    ...actual,
    api: {
      ...actual.api,
      secrets: vi.fn(),
      createSecret: vi.fn(),
      updateSecret: vi.fn(),
      deleteSecret: vi.fn(),
      uploadSecretFile: vi.fn(),
      deleteSecretFile: vi.fn(),
    },
  }
})

import { api } from '@/lib/api'

const secretsMock = vi.mocked(api.secrets)
const createSecretMock = vi.mocked(api.createSecret)

function renderPanel() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  return render(
    <QueryClientProvider client={queryClient}>
      <ToastProvider>
        <SecretsPanel projectId={7} />
      </ToastProvider>
    </QueryClientProvider>,
  )
}

describe('SecretsPanel', () => {
  beforeEach(() => {
    secretsMock.mockReset()
    createSecretMock.mockReset()
  })

  it('lists secret names and files without their values', async () => {
    secretsMock.mockResolvedValue({
      secrets: [{ id: 1, key: 'TENANT_BRANDS_TOKEN', created_at: '', updated_at: new Date().toISOString() }],
      secret_files: [
        {
          id: 2,
          path: 'credentials/android/keystore.jks',
          size: 2048,
          sha256: 'a'.repeat(64),
          created_at: '',
          updated_at: new Date().toISOString(),
        },
      ],
    })

    renderPanel()

    expect(await screen.findByText('TENANT_BRANDS_TOKEN')).toBeInTheDocument()
    expect(screen.getAllByText('credentials/android/keystore.jks').length).toBeGreaterThanOrEqual(2)
    expect(screen.getByText('2.0 KB')).toBeInTheDocument()
  })

  it('creates a new environment secret', async () => {
    const user = userEvent.setup()

    secretsMock.mockResolvedValue({ secrets: [], secret_files: [] })
    createSecretMock.mockResolvedValue({
      secret: { id: 1, key: 'MY_TOKEN', created_at: '', updated_at: '' },
    })

    renderPanel()

    await screen.findByText(/Nenhuma variável configurada/)

    await user.type(screen.getByPlaceholderText('TENANT_BRANDS_TOKEN'), 'MY_TOKEN')
    await user.type(screen.getByPlaceholderText('valor secreto'), 'super-secret-value')
    await user.click(screen.getByRole('button', { name: /adicionar/i }))

    await waitFor(() =>
      expect(createSecretMock).toHaveBeenCalledWith(7, { key: 'MY_TOKEN', value: 'super-secret-value' }),
    )
  })
})
