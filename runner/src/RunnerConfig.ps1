#Requires -Version 5.1
<#
    Configuration loading and validation for the Windows build runner.

    Precedence: explicit -ConfigPath file < environment variables.
    Secrets (the token) may also be supplied via RUNNER_TOKEN so they never
    need to live in the JSON file, although the install script supports a
    locked-down configuration file.
#>

Set-StrictMode -Version 2.0

function Get-DefaultRunnerConfigPath {
    $programData = $env:ProgramData
    if ($programData) {
        return (Join-Path $programData 'LocalBuildPlatform\runner\config.json')
    }
    return (Join-Path (Split-Path -Parent $PSScriptRoot) 'config\runner.config.json')
}

function New-RunnerConfig {
    param([string]$ConfigPath)

    $defaults = @{
        api_url = 'http://localhost:8100/api/runner/v1'
        token = ''
        name = $env:COMPUTERNAME
        poll_interval_seconds = 10
        idle_heartbeat_seconds = 30
        build_heartbeat_seconds = 15
        log_batch_interval_seconds = 1
        log_batch_max_lines = 200
        log_batch_max_bytes = 262144
        log_read_chunk_bytes = 262144
        max_pending_log_lines = 5000
        workspace_root = 'C:\LocalBuilds\projects'
        log_directory = 'C:\LocalBuilds\logs'
        artifact_scan_root = ''
        default_timeout_seconds = 3600
        api_timeout_seconds = 30
        api_retry_max = 5
        complete_retry_max = 10
        submit_timeout_seconds = 900
        use_system_proxy = $false
        git_ssh_command = ''
        extra_headers = @{}
        cleanup_successful_builds = $false
    }

    $fileConfig = @{}
    $resolvedPath = $ConfigPath
    if (-not $resolvedPath) { $resolvedPath = Get-DefaultRunnerConfigPath }

    if (Test-Path -LiteralPath $resolvedPath) {
        $raw = Get-Content -LiteralPath $resolvedPath -Raw
        try {
            $parsed = $raw | ConvertFrom-Json
        } catch {
            throw "Arquivo de configuração inválido (${resolvedPath}): $($_.Exception.Message)"
        }

        foreach ($property in $parsed.PSObject.Properties) {
            $fileConfig[$property.Name] = $property.Value
        }
        $fileConfig['config_path'] = (Resolve-Path -LiteralPath $resolvedPath).Path
    } else {
        $fileConfig['config_path'] = $resolvedPath
    }

    $config = $fileConfig.Clone()
    foreach ($key in $defaults.Keys) {
        if (-not $config.ContainsKey($key) -or $null -eq $config[$key] -or $config[$key] -eq '') {
            $config[$key] = $defaults[$key]
        }
    }

    if ($env:RUNNER_API_URL) { $config['api_url'] = $env:RUNNER_API_URL }
    if ($env:RUNNER_TOKEN) { $config['token'] = $env:RUNNER_TOKEN }
    if ($env:RUNNER_NAME) { $config['name'] = $env:RUNNER_NAME }
    if ($env:RUNNER_WORKSPACE_ROOT) { $config['workspace_root'] = $env:RUNNER_WORKSPACE_ROOT }
    if ($env:RUNNER_LOG_DIRECTORY) { $config['log_directory'] = $env:RUNNER_LOG_DIRECTORY }

    # Normalize types (JSON numbers may arrive as Int64/Double).
    foreach ($key in @('poll_interval_seconds', 'idle_heartbeat_seconds', 'build_heartbeat_seconds',
            'log_batch_interval_seconds', 'log_batch_max_lines', 'log_batch_max_bytes', 'log_read_chunk_bytes',
            'max_pending_log_lines', 'default_timeout_seconds', 'api_timeout_seconds', 'api_retry_max',
            'complete_retry_max', 'submit_timeout_seconds')) {
        $config[$key] = [int]$config[$key]
    }

    foreach ($key in @('use_system_proxy', 'cleanup_successful_builds')) {
        $config[$key] = [bool]$config[$key]
    }

    if ($config['extra_headers'] -isnot [hashtable]) {
        $headers = @{}
        if ($config['extra_headers']) {
            foreach ($property in $config['extra_headers'].PSObject.Properties) {
                $headers[$property.Name] = [string]$property.Value
            }
        }
        $config['extra_headers'] = $headers
    }

    return $config
}

function Assert-RunnerConfig {
    param([Parameter(Mandatory = $true)][hashtable]$Config)

    $errors = New-Object System.Collections.Generic.List[string]

    $uri = $null
    if (-not [System.Uri]::TryCreate([string]$Config['api_url'], [System.UriKind]::Absolute, [ref]$uri)) {
        $errors.Add('api_url deve ser uma URL absoluta (ex.: http://localhost:8100/api/runner/v1).')
    } elseif ($uri.Scheme -ne 'http' -and $uri.Scheme -ne 'https') {
        $errors.Add('api_url deve usar http ou https.')
    }

    if ([string]::IsNullOrWhiteSpace([string]$Config['token'])) {
        $errors.Add('token do runner não configurado (config.json ou variável RUNNER_TOKEN).')
    }

    if ([string]::IsNullOrWhiteSpace([string]$Config['name'])) {
        $errors.Add('name do runner não configurado.')
    }

    foreach ($pathKey in @('workspace_root', 'log_directory')) {
        $value = [string]$Config[$pathKey]
        if ([string]::IsNullOrWhiteSpace($value)) {
            $errors.Add("$pathKey não pode ser vazio.")
            continue
        }
        if (-not [System.IO.Path]::IsPathRooted($value)) {
            $errors.Add("$pathKey deve ser um caminho absoluto do Windows.")
        }
    }

    foreach ($intKey in @('poll_interval_seconds', 'idle_heartbeat_seconds', 'build_heartbeat_seconds')) {
        if ([int]$Config[$intKey] -lt 1) {
            $errors.Add("$intKey deve ser maior que zero.")
        }
    }

    if ([int]$Config['log_batch_max_lines'] -lt 1 -or [int]$Config['log_batch_max_lines'] -gt 500) {
        $errors.Add('log_batch_max_lines deve estar entre 1 e 500.')
    }

    if ([int]$Config['submit_timeout_seconds'] -lt 60 -or [int]$Config['submit_timeout_seconds'] -gt 7200) {
        $errors.Add('submit_timeout_seconds deve estar entre 60 e 7200.')
    }

    if ($errors.Count -gt 0) {
        throw ("Configuração inválida:`n - " + ($errors -join "`n - "))
    }

    return $true
}

function Get-RedactedConfigSummary {
    param([Parameter(Mandatory = $true)][hashtable]$Config)

    $token = [string]$Config['token']
    if ($token.Length -gt 12) {
        $token = $token.Substring(0, 6) + '...' + $token.Substring($token.Length - 4)
    } elseif ($token.Length -gt 0) {
        $token = '***'
    }

    return ('api_url={0}; name={1}; token={2}; workspace_root={3}; poll={4}s' -f `
            $Config['api_url'], $Config['name'], $token, $Config['workspace_root'], $Config['poll_interval_seconds'])
}
