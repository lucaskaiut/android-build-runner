import type {
  Artifact,
  BranchTrigger,
  Build,
  BuildLogEntry,
  BuildProfile,
  Paginated,
  Project,
  Runner,
  User,
  WebhookDelivery,
} from './types'

export interface ApiFieldErrors {
  [field: string]: string[]
}

export class ApiError extends Error {
  status: number
  errors: ApiFieldErrors

  constructor(message: string, status: number, errors: ApiFieldErrors = {}) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.errors = errors
  }
}

export function isApiError(error: unknown): error is ApiError {
  return error instanceof ApiError
}

export function apiErrorMessage(error: unknown): string {
  if (isApiError(error)) return error.message
  if (error instanceof Error) return error.message
  return 'Erro inesperado.'
}

interface RequestOptions {
  method?: 'get' | 'post' | 'put' | 'delete'
  body?: unknown
  formData?: FormData
  params?: Record<string, string | number | boolean | undefined>
}

const API_BASE = '/api/v1'

async function parseResponse(response: Response): Promise<unknown> {
  if (response.status === 204) return null

  const text = await response.text()
  if (!text) return null

  try {
    return JSON.parse(text)
  } catch {
    return text
  }
}

async function ensureCsrfCookie(): Promise<void> {
  await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' })
}

function readCookie(name: string): string | null {
  const match = document.cookie.match(new RegExp('(^|;\\s*)' + name + '=([^;]*)'))
  return match ? decodeURIComponent(match[2]) : null
}

async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const method = (options.method ?? 'get').toUpperCase()

  let url = API_BASE + path
  if (options.params) {
    const search = new URLSearchParams()
    for (const [key, value] of Object.entries(options.params)) {
      if (value !== undefined && value !== '') search.set(key, String(value))
    }
    const query = search.toString()
    if (query) url += `?${query}`
  }

  if (method !== 'GET') {
    await ensureCsrfCookie()
  }

  const headers: Record<string, string> = { Accept: 'application/json' }
  const csrf = readCookie('XSRF-TOKEN')
  if (csrf && method !== 'GET') headers['X-XSRF-TOKEN'] = csrf

  let body: BodyInit | undefined
  if (options.formData) {
    body = options.formData
  } else if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json'
    body = JSON.stringify(options.body)
  }

  const response = await fetch(url, {
    method,
    headers,
    body,
    credentials: 'same-origin',
  })

  const payload = await parseResponse(response)

  if (!response.ok) {
    const data = (payload ?? {}) as { message?: string; errors?: ApiFieldErrors }
    throw new ApiError(
      data.message ?? `Erro ${response.status} ao chamar a API.`,
      response.status,
      data.errors ?? {},
    )
  }

  return payload as T
}

export const api = {
  // Auth
  login: (email: string, password: string) =>
    request<{ user: User }>('/auth/login', { method: 'post', body: { email, password } }),
  logout: () => request<{ message: string }>('/auth/logout', { method: 'post' }),
  me: () => request<{ user: User }>('/me'),

  // Dashboard
  dashboard: () => request<DashboardPayload>('/dashboard'),

  // Projects
  projects: (params?: Record<string, string | number | boolean | undefined>) =>
    request<Paginated<Project>>('/projects', { params }),
  project: (id: number) =>
    request<{ project: Project; profiles: BuildProfile[]; branch_triggers: BranchTrigger[] }>(
      `/projects/${id}`,
    ),
  createProject: (body: ProjectInput) =>
    request<{ project: Project }>('/projects', { method: 'post', body }),
  updateProject: (id: number, body: Partial<ProjectInput>) =>
    request<{ project: Project }>(`/projects/${id}`, { method: 'put', body }),
  deleteProject: (id: number) => request<{ message: string }>(`/projects/${id}`, { method: 'delete' }),
  activateProject: (id: number) =>
    request<{ project: Project }>(`/projects/${id}/activate`, { method: 'post' }),
  deactivateProject: (id: number) =>
    request<{ project: Project }>(`/projects/${id}/deactivate`, { method: 'post' }),
  rotateWebhookSecret: (id: number) =>
    request<{ webhook_secret: string; webhook_path: string; message: string; project: Project }>(
      `/projects/${id}/webhook-secret`,
      { method: 'post' },
    ),
  webhookDeliveries: (id: number, params?: Record<string, number>) =>
    request<Paginated<WebhookDelivery>>(`/projects/${id}/webhook-deliveries`, { params }),

  // Branch triggers
  createBranchTrigger: (projectId: number, body: { build_profile_id: number; branch_pattern: string }) =>
    request<{ branch_trigger: BranchTrigger }>(`/projects/${projectId}/branch-triggers`, {
      method: 'post',
      body,
    }),
  updateBranchTrigger: (id: number, body: { build_profile_id?: number; branch_pattern?: string; is_active?: boolean }) =>
    request<{ branch_trigger: BranchTrigger }>(`/branch-triggers/${id}`, { method: 'put', body }),
  deleteBranchTrigger: (id: number) =>
    request<{ message: string }>(`/branch-triggers/${id}`, { method: 'delete' }),

  // Profiles
  createProfile: (projectId: number, body: ProfileInput) =>
    request<{ profile: BuildProfile }>(`/projects/${projectId}/profiles`, { method: 'post', body }),
  updateProfile: (id: number, body: Partial<ProfileInput>) =>
    request<{ profile: BuildProfile }>(`/profiles/${id}`, { method: 'put', body }),
  deleteProfile: (id: number) => request<{ message: string }>(`/profiles/${id}`, { method: 'delete' }),
  activateProfile: (id: number) =>
    request<{ profile: BuildProfile }>(`/profiles/${id}/activate`, { method: 'post' }),
  deactivateProfile: (id: number) =>
    request<{ profile: BuildProfile }>(`/profiles/${id}/deactivate`, { method: 'post' }),

  // Builds
  builds: (params?: Record<string, string | number | undefined>) =>
    request<Paginated<Build>>('/builds', { params }),
  build: (id: number) => request<{ build: Build }>(`/builds/${id}`),
  createBuild: (
    projectId: number,
    body: { build_profile_id: number; branch?: string; commit_sha?: string; parameters?: Record<string, string> },
  ) => request<{ build: Build }>(`/projects/${projectId}/builds`, { method: 'post', body }),
  cancelBuild: (id: number) => request<{ build: Build }>(`/builds/${id}/cancel`, { method: 'post' }),
  buildLogs: (id: number, afterId: number, limit = 500) =>
    request<{ logs: BuildLogEntry[]; last_id: number; build_status: string }>(`/builds/${id}/logs`, {
      params: { after_id: afterId, limit },
    }),
  buildArtifacts: (id: number) => request<{ artifacts: Artifact[] }>(`/builds/${id}/artifacts`),

  // Runners
  runners: () => request<{ runners: Runner[] }>('/runners'),
}

export interface DashboardPayload {
  stats: {
    by_status: Record<string, number>
    total_builds: number
    projects_count: number
    active_projects_count: number
    runners_online: number
    runners_total: number
  }
  recent_builds: Build[]
  runners: Runner[]
}

export interface ProjectInput {
  name: string
  slug: string
  repo_url: string
  default_branch: string
  working_directory: string
  allowed_branches?: string[]
  is_active?: boolean
  webhook_enabled?: boolean
}

export interface ProfileInput {
  name: string
  slug: string
  build_script: string
  working_directory?: string | null
  timeout_seconds?: number
  artifact_patterns?: string[]
  env?: Record<string, string>
  parameters?: ProfileParameter[]
  is_active?: boolean
}

export interface ProfileParameter {
  key: string
  label?: string
  required?: boolean
  default?: string | null
  options?: string[]
}
