#Requires -Version 5.1
<#
    Workspace lifecycle: isolated directories per build, git clone/checkout
    of the exact commit and safe path validation.
#>

Set-StrictMode -Version 2.0

function New-BuildContext {
    param(
        [Parameter(Mandatory = $true)][hashtable]$Config,
        [Parameter(Mandatory = $true)]$Build
    )

    $projectId = [string]$Build.project.id
    $buildId = [string]$Build.id

    $projectRoot = Join-Path ([string]$Config['workspace_root']) ('project-' + (ConvertTo-SafePathSegment $projectId))
    $workspace = Join-Path (Join-Path $projectRoot 'builds') ('build-' + (ConvertTo-SafePathSegment $buildId))

    if (-not (Test-PathInsideRoot -Root ([string]$Config['workspace_root']) -Candidate $workspace)) {
        throw "Caminho de workspace inválido para a build $buildId (fora da raiz configurada)."
    }

    $workingDirectory = [string]$Build.working_directory
    if ([string]::IsNullOrWhiteSpace($workingDirectory)) { $workingDirectory = '.' }

    $appDir = [System.IO.Path]::GetFullPath((Join-Path $workspace $workingDirectory))

    if (-not (Test-PathInsideRoot -Root $workspace -Candidate $appDir)) {
        throw "Diretório de execução inválido para a build $buildId (fora do workspace)."
    }

    $runnerDir = Join-Path $workspace '_runner'

    return @{
        Id = [int]$Build.id
        ProjectId = $projectId
        ProjectName = [string]$Build.project.name
        ProjectSlug = [string]$Build.project.slug
        RepoUrl = [string]$Build.project.repo_url
        Branch = [string]$Build.branch
        CommitSha = [string]$Build.commit_sha
        Parameters = $Build.parameters
        ProfileName = [string]$Build.profile.name
        Script = [string]$Build.script
        ProfileEnv = $Build.profile.env
        TimeoutSeconds = [int]$Build.profile.timeout_seconds
        Workspace = $workspace
        AppDir = $appDir
        RunnerDir = $runnerDir
        StdoutPath = (Join-Path $runnerDir 'stdout.log')
        StderrPath = (Join-Path $runnerDir 'stderr.log')
        ScriptPath = (Join-Path $runnerDir 'build.ps1')
        RunStatePath = (Join-Path $runnerDir 'run.json')
        ResolvedSha = $null
        GitSshCommand = [string]$Config['git_ssh_command']
    }
}

function Invoke-GitCommand {
    param(
        [Parameter(Mandatory = $true)][hashtable]$Context,
        [Parameter(Mandatory = $true)][string[]]$Arguments,
        [int]$TimeoutSeconds = 900
    )

    $git = Get-Command 'git' -ErrorAction SilentlyContinue
    if (-not $git) {
        throw "Git não encontrado no PATH. Instale o Git for Windows e reabra o serviço/terminal do runner."
    }

    $gitArguments = @()
    if ($Context.GitSshCommand) {
        $gitArguments += @('-c', "core.sshCommand=$($Context.GitSshCommand)")
    }
    $gitArguments += $Arguments

    return Invoke-ExternalProcess -FilePath $git.Source -Arguments $gitArguments `
        -WorkingDirectory $Context.Workspace -TimeoutSeconds $TimeoutSeconds
}

function Get-ExternalErrorText {
    param([Parameter(Mandatory = $true)][hashtable]$Result)

    $text = ''
    if ($Result.Stderr -and $Result.Stderr.Trim()) { $text = $Result.Stderr }
    elseif ($Result.Stdout -and $Result.Stdout.Trim()) { $text = $Result.Stdout }

    $text = $text.Trim()
    if ($text.Length -gt 800) { $text = $text.Substring(0, 800) + '...' }

    if (-not $text) { return 'sem saída do comando' }
    return $text
}

function Initialize-BuildWorkspace {
    <#
        Clones (or reuses) the repository and checks out the requested ref.
        The checked out commit is read back and recorded as the resolved SHA.
    #>
    param(
        [Parameter(Mandatory = $true)][hashtable]$Context,
        [Parameter(Mandatory = $true)][hashtable]$Config
    )

    if (Test-Path -LiteralPath $Context.Workspace) {
        Write-RunnerLog "Workspace existente reutilizado: $($Context.Workspace)" 'WARN'
    } else {
        New-Item -ItemType Directory -Path $Context.Workspace -Force | Out-Null
    }

    $gitDirectory = Join-Path $Context.Workspace '.git'

    if (-not (Test-Path -LiteralPath $gitDirectory)) {
        Write-RunnerLog "Clonando $($Context.RepoUrl) ..."
        $clone = Invoke-GitCommand -Context $Context -Arguments @('clone', '--quiet', $Context.RepoUrl, '.') -TimeoutSeconds 3600

        if ($clone.ExitCode -ne 0) {
            throw "Falha no git clone: $(Get-ExternalErrorText -Result $clone)"
        }
    } else {
        Write-RunnerLog 'Repositório já presente no workspace; atualizando referências...'
        $fetch = Invoke-GitCommand -Context $Context -Arguments @('fetch', '--prune', 'origin') -TimeoutSeconds 1800

        if ($fetch.ExitCode -ne 0) {
            throw "Falha no git fetch: $(Get-ExternalErrorText -Result $fetch)"
        }
    }

    if ($Context.CommitSha) {
        $checkout = Invoke-GitCommand -Context $Context -Arguments @(
            '-c', 'advice.detachedHead=false', 'checkout', '--force', $Context.CommitSha
        ) -TimeoutSeconds 600

        if ($checkout.ExitCode -ne 0) {
            throw "Falha no checkout do commit $($Context.CommitSha): $(Get-ExternalErrorText -Result $checkout)"
        }
    } else {
        $branch = $Context.Branch
        if ([string]::IsNullOrWhiteSpace($branch)) {
            throw 'A build não informou branch nem commit para checkout.'
        }

        $checkout = Invoke-GitCommand -Context $Context -Arguments @('checkout', '--force', $branch) -TimeoutSeconds 600

        if ($checkout.ExitCode -ne 0) {
            throw "Falha no checkout da branch '$branch': $(Get-ExternalErrorText -Result $checkout)"
        }

        $pull = Invoke-GitCommand -Context $Context -Arguments @('pull', '--ff-only', 'origin', $branch) -TimeoutSeconds 900

        if ($pull.ExitCode -ne 0) {
            throw "Falha no git pull da branch '$branch': $(Get-ExternalErrorText -Result $pull)"
        }
    }

    $head = Invoke-GitCommand -Context $Context -Arguments @('rev-parse', 'HEAD') -TimeoutSeconds 60
    if ($head.ExitCode -ne 0) {
        throw "Não foi possível resolver o commit atual: $(Get-ExternalErrorText -Result $head)"
    }

    $resolvedSha = $head.Stdout.Trim().ToLowerInvariant()

    if ($Context.CommitSha -and $resolvedSha -ne $Context.CommitSha.ToLowerInvariant()) {
        throw "O checkout resultou no commit $resolvedSha, diferente do solicitado ($($Context.CommitSha))."
    }

    $Context.ResolvedSha = $resolvedSha
    Write-RunnerLog "Checkout concluído no commit $resolvedSha"

    return $resolvedSha
}

function New-BuildEnvironmentLines {
    <#
        Generates $env:VAR = 'value' lines executed by the build wrapper.
        Values are single-quote escaped for PowerShell.
    #>
    param(
        [Parameter(Mandatory = $true)][hashtable]$Context
    )

    $variables = [ordered]@{
        BUILD_ID = [string]$Context.Id
        BUILD_PROJECT_ID = [string]$Context.ProjectId
        BUILD_PROJECT_SLUG = [string]$Context.ProjectSlug
        BUILD_PROJECT_NAME = [string]$Context.ProjectName
        BUILD_PROFILE = $Context.ProfileName
        BUILD_BRANCH = [string]$Context.Branch
        BUILD_COMMIT = [string]$Context.ResolvedSha
        BUILD_REPO_URL = [string]$Context.RepoUrl
        BUILD_WORKSPACE = [string]$Context.Workspace
        APP_DIR = [string]$Context.AppDir
        CI = '1'
        EXPO_NO_TELEMETRY = '1'
    }

    if ($Context.ProfileEnv -and $Context.ProfileEnv -isnot [string]) {
        foreach ($property in $Context.ProfileEnv.PSObject.Properties) {
            $variables['BUILD_ENV_' + $property.Name] = [string]$property.Value
            $variables[$property.Name] = [string]$property.Value
        }
    }

    if ($Context.Parameters -and $Context.Parameters -isnot [string]) {
        foreach ($property in $Context.Parameters.PSObject.Properties) {
            $variables['BUILD_PARAM_' + $property.Name.ToUpperInvariant()] = [string]$property.Value
        }
    }

    $lines = New-Object System.Collections.Generic.List[string]
    foreach ($name in $variables.Keys) {
        $value = ([string]$variables[$name]).Replace("'", "''")
        $lines.Add(('$env:{0} = ''{1}''' -f $name, $value))
    }

    return $lines
}

function Write-BuildWrapperScript {
    param(
        [Parameter(Mandatory = $true)][hashtable]$Context
    )

    if (-not (Test-Path -LiteralPath $Context.RunnerDir)) {
        New-Item -ItemType Directory -Path $Context.RunnerDir -Force | Out-Null
    }

    $builder = New-Object System.Text.StringBuilder
    [void]$builder.AppendLine('# Wrapper gerado automaticamente pelo runner Local Build Platform.')
    [void]$builder.AppendLine('$ErrorActionPreference = ''Stop''')
    [void]$builder.AppendLine('try { [Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false) } catch { }')
    [void]$builder.AppendLine('$OutputEncoding = New-Object System.Text.UTF8Encoding($false)')
    [void]$builder.AppendLine('$global:LASTEXITCODE = 0')
    [void]$builder.AppendLine('')
    [void]$builder.AppendLine('# --- variáveis do build ---')

    foreach ($line in (New-BuildEnvironmentLines -Context $Context)) {
        [void]$builder.AppendLine($line)
    }

    [void]$builder.AppendLine('')
    [void]$builder.AppendLine('# --- script do perfil ---')
    [void]$builder.AppendLine($Context.Script)
    [void]$builder.AppendLine('')
    [void]$builder.AppendLine('# Propaga o código de saída do último comando nativo executado.')
    [void]$builder.AppendLine('if ($global:LASTEXITCODE -ne 0) { exit $global:LASTEXITCODE }')
    [void]$builder.AppendLine('exit 0')

    $utf8Bom = New-Object System.Text.UTF8Encoding($true)
    [System.IO.File]::WriteAllText($Context.ScriptPath, $builder.ToString(), $utf8Bom)

    return $Context.ScriptPath
}

function Write-BuildRunState {
    param(
        [Parameter(Mandatory = $true)][hashtable]$Context,
        [Parameter(Mandatory = $true)][int]$ProcessId
    )

    $state = @{
        pid = $ProcessId
        started_at = (Get-Date).ToUniversalTime().ToString('o')
        build_id = $Context.Id
    }

    try {
        $utf8 = New-Object System.Text.UTF8Encoding($false)
        [System.IO.File]::WriteAllText($Context.RunStatePath, ($state | ConvertTo-Json -Compress), $utf8)
    } catch {
        Write-RunnerLog "Falha ao gravar run.json: $($_.Exception.Message)" 'WARN'
    }

    return $state
}

function Clear-BuildRunState {
    param([Parameter(Mandatory = $true)][hashtable]$Context)

    if (Test-Path -LiteralPath $Context.RunStatePath) {
        Remove-Item -LiteralPath $Context.RunStatePath -Force -ErrorAction SilentlyContinue
    }
}

function Test-BuildRunStateAlive {
    <#
        True when run.json points to a live PowerShell process (another
        execution of the same build may still be running).
    #>
    param([Parameter(Mandatory = $true)][hashtable]$Context)

    if (-not (Test-Path -LiteralPath $Context.RunStatePath)) { return $false }

    try {
        $state = Get-Content -LiteralPath $Context.RunStatePath -Raw | ConvertFrom-Json
        $process = Get-Process -Id ([int]$state.pid) -ErrorAction SilentlyContinue

        if (-not $process) { return $false }

        $startedAt = [datetime]::Parse($state.started_at).ToUniversalTime()
        $processStart = $process.StartTime.ToUniversalTime()
        $deltaSeconds = [Math]::Abs(($processStart - $startedAt).TotalSeconds)

        # The PID must belong to the exact process recorded in run.json.
        return ($deltaSeconds -lt 120)
    } catch {
        return $false
    }
}
