export type BuildStatus = 'queued' | 'preparing' | 'running' | 'success' | 'failed' | 'cancelled'
export type SubmitStatus = 'pending' | 'processing' | 'success' | 'failed' | 'skipped'
export type BuildSource = 'manual' | 'api' | 'webhook'
export type RunnerStatus = 'online' | 'offline' | 'disabled'
export type LogStreamType = 'stdout' | 'stderr' | 'system'
export type ArtifactType = 'apk' | 'aab' | 'other'
export type WebhookStatus = 'accepted' | 'duplicate' | 'rejected' | 'ignored' | 'error'

export interface User {
  id: number
  name: string
  email: string
  is_admin: boolean
  created_at: string
}

export interface PaginationLink {
  url: string | null
  label: string
  active: boolean
}

export interface Paginated<T> {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
  links: PaginationLink[]
}

export interface ProjectSummary {
  id: number
  name: string
  slug: string
}

export interface ProfileSummary {
  id: number
  name: string
  slug: string
}

export interface RunnerSummary {
  id: number
  name: string
}

export interface Project {
  id: number
  name: string
  slug: string
  repo_url: string
  default_branch: string
  working_directory: string
  allowed_branches: string[]
  is_active: boolean
  webhook_enabled: boolean
  webhook_secret_configured: boolean
  webhook_path: string
  profiles_count?: number
  builds_count?: number
  last_build?: Build | null
  created_at: string
  updated_at: string
}

export interface ProfileParameterDefinition {
  key: string
  label?: string
  required?: boolean
  default?: string | null
  options?: string[]
}

export interface SubmitConfig {
  provider: 'google_play'
  package_name: string
  track: string
  service_account_file: string
  artifact_pattern: string
  release_status: 'completed' | 'draft'
  release_name?: string | null
}

export interface BuildProfile {
  id: number
  project_id: number
  name: string
  slug: string
  build_script: string
  working_directory: string | null
  timeout_seconds: number
  artifact_patterns: string[]
  env: Record<string, string>
  parameters: ProfileParameterDefinition[]
  is_active: boolean
  auto_submit: boolean
  submit_config: SubmitConfig | null
  builds_count?: number
  created_at: string
  updated_at: string
}

export interface Artifact {
  id: number
  build_id: number
  original_name: string
  type: ArtifactType
  size_bytes: number
  sha256: string
  download_path: string
  created_at: string
}

export interface Build {
  id: number
  status: BuildStatus
  status_label: string
  submit_status: SubmitStatus | null
  submit_status_label: string | null
  submit_message: string | null
  submit_version_code: string | null
  submit_started_at: string | null
  submitted_at: string | null
  source: BuildSource
  source_label: string
  branch: string | null
  commit_sha: string | null
  project?: ProjectSummary
  profile?: ProfileSummary
  runner?: RunnerSummary | null
  parameters: Record<string, string> | null
  queued_at: string | null
  claimed_at: string | null
  started_at: string | null
  finished_at: string | null
  duration_ms: number | null
  exit_code: number | null
  error_message: string | null
  cancel_requested: boolean
  cancel_requested_at: string | null
  artifacts_count?: number
  artifacts?: Artifact[]
  created_at: string
}

export interface BuildLogEntry {
  id: number
  stream: LogStreamType
  message: string
  created_at: string
}

export interface Runner {
  id: number
  name: string
  status: RunnerStatus
  status_label: string
  software_version: string | null
  protocol_version: number
  os_info: string | null
  last_heartbeat_at: string | null
  current_build: { id: number; status: string | null } | null
  created_at: string
}

export interface BranchTrigger {
  id: number
  project_id: number
  build_profile_id: number
  branch_pattern: string
  is_active: boolean
  profile?: { id: number; name: string; slug: string; is_active: boolean } | null
  created_at: string
}

export interface WebhookDelivery {
  id: number
  delivery_id: string | null
  event: string | null
  ref: string | null
  sha: string | null
  status: WebhookStatus
  status_label: string
  message: string | null
  build_id: number | null
  created_at: string
}

export interface ProjectSecret {
  id: number
  key: string
  created_at: string
  updated_at: string
}

export interface ProjectSecretFile {
  id: number
  path: string
  size: number
  sha256: string
  created_at: string
  updated_at: string
}
