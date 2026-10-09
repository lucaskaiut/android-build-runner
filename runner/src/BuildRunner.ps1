#Requires -Version 5.1
<#
    Local Build Platform - Windows build runner.

    Polls the API for queued builds, prepares an isolated workspace, runs the
    profile build script, streams logs and uploads artifacts. All connections
    are outbound; the runner never exposes an inbound port.

    Usage:
        powershell.exe -NoProfile -ExecutionPolicy Bypass -File src\BuildRunner.ps1
        powershell.exe ... -File src\BuildRunner.ps1 -ConfigPath C:\...\config.json
        powershell.exe ... -File src\BuildRunner.ps1 -Once     # single claim cycle
#>

[CmdletBinding()]
param(
    [string]$ConfigPath = '',
    [int]$MaxBuilds = 0,
    [switch]$Once,
    [switch]$Diagnose
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

. (Join-Path $PSScriptRoot 'RunnerCommon.ps1')
. (Join-Path $PSScriptRoot 'RunnerConfig.ps1')
. (Join-Path $PSScriptRoot 'ApiClient.ps1')
. (Join-Path $PSScriptRoot 'ProcessControl.ps1')
. (Join-Path $PSScriptRoot 'LogCollector.ps1')
. (Join-Path $PSScriptRoot 'Secrets.ps1')
. (Join-Path $PSScriptRoot 'Workspace.ps1')
. (Join-Path $PSScriptRoot 'ArtifactCollector.ps1')

# ---------------------------------------------------------------------------
# API interactions
# ---------------------------------------------------------------------------

function Send-RunnerHeartbeat {
    param(
        [Parameter(Mandatory = $true)][hashtable]$Api,
        [Parameter(Mandatory = $true)][hashtable]$Config,
        [Parameter(Mandatory = $true)][hashtable]$Diagnostics,
        [int]$CurrentBuildId = 0,
        [int]$MaxAttempts = 0
    )

    $body = @{
        software_version = Get-RunnerVersion
        protocol_version = 1
        os_info = [string]$Diagnostics['os']
        metadata = (Get-CompactDiagnosticsMetadata -Diagnostics $Diagnostics)
    }

    if ($CurrentBuildId -gt 0) { $body['current_build_id'] = $CurrentBuildId }

    return Invoke-ApiRequest -Api $Api -Method 'POST' -Path 'heartbeat' -Body $body -MaxAttempts $MaxAttempts
}

function Get-SystemBufferLines {
    param([Parameter(Mandatory = $true)][hashtable]$LogState)

    $lines = New-Object System.Collections.ArrayList
    foreach ($entry in $LogState.SystemBuffer) { $lines.Add($entry) | Out-Null }
    $LogState.SystemBuffer.Clear()

    # The comma prevents PowerShell from unrolling a single-element list.
    return , $lines
}

function Send-PendingLogBatches {
    <#
        Sends queued batches in order. Returns:
        @{ Abort = bool; Message = string; Control = object }
        The first batch that fails transiently stays in the queue.
    #>
    param(
        [Parameter(Mandatory = $true)][hashtable]$Api,
        [Parameter(Mandatory = $true)][int]$BuildId,
        [Parameter(Mandatory = $true)][hashtable]$LogState,
        [int]$MaxAttempts = 2
    )

    $control = $null

    while ($LogState.PendingBatches.Count -gt 0) {
        $batch = $LogState.PendingBatches[0]

        try {
            $response = Invoke-ApiRequest -Api $Api -Method 'POST' `
                -Path ("builds/{0}/logs" -f $BuildId) `
                -Body @{ batch_id = $batch.BatchId; events = $batch.Events } `
                -MaxAttempts $MaxAttempts

            $LogState.PendingBatches.RemoveAt(0)
            if ($response -and $response.control) { $control = $response.control }
        } catch [RunnerApiException] {
            $status = $_.Exception.StatusCode

            if ($status -eq 409) {
                return @{ Abort = $true; Message = $_.Exception.Message; Control = $control }
            }

            Write-RunnerLog ("Falha ao enviar logs da build {0}: {1}" -f $BuildId, $_.Exception.Message) 'WARN'
            break
        } catch {
            Write-RunnerLog ("Falha ao enviar logs da build {0}: {1}" -f $BuildId, $_.Exception.Message) 'WARN'
            break
        }
    }

    return @{ Abort = $false; Message = ''; Control = $control }
}

function Send-BuildHeartbeat {
    param(
        [Parameter(Mandatory = $true)][hashtable]$Api,
        [Parameter(Mandatory = $true)][int]$BuildId
    )

    $response = Invoke-ApiRequest -Api $Api -Method 'POST' -Path ("builds/{0}/heartbeat" -f $BuildId) -Body @{} -MaxAttempts 2

    return $response
}

function Get-StderrTail {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [int]$Lines = 8
    )

    if (-not (Test-Path -LiteralPath $Path)) { return '' }

    try {
        $content = Get-Content -LiteralPath $Path -Tail $Lines -Encoding UTF8 -ErrorAction SilentlyContinue
        $text = ($content -join ' ').Trim()
        if ($text.Length -gt 1500) { $text = '...' + $text.Substring($text.Length - 1500) }

        return $text
    } catch {
        return ''
    }
}

function Send-CompleteRequest {
    param(
        [Parameter(Mandatory = $true)][hashtable]$Api,
        [Parameter(Mandatory = $true)][hashtable]$Config,
        [Parameter(Mandatory = $true)][int]$BuildId,
        [Parameter(Mandatory = $true)][string]$Status,
        $ExitCode = $null,
        [string]$ErrorMessage = ''
    )

    $body = @{ status = $Status }

    if ($null -ne $ExitCode) {
        $code = [int]$ExitCode
        if ($code -gt 32767) { $code = $null }
        if ($null -ne $code) { $body['exit_code'] = $code }
    }

    if ($ErrorMessage) {
        $body['error_message'] = $ErrorMessage.Substring(0, [Math]::Min(2000, $ErrorMessage.Length))
    }

    try {
        return Invoke-ApiRequest -Api $Api -Method 'POST' -Path ("builds/{0}/complete" -f $BuildId) `
            -Body $body -MaxAttempts ([int]$Config['complete_retry_max'])
    } catch [RunnerApiException] {
        if ($_.Exception.StatusCode -eq 409) {
            Write-RunnerLog "A API já finalizou a build ${BuildId}: $($_.Exception.Message)" 'WARN'
            return $null
        }

        Write-RunnerLog "Falha ao concluir a build $BuildId na API: $($_.Exception.Message)" 'ERROR'
        return $null
    } catch {
        Write-RunnerLog "Falha ao concluir a build $BuildId na API: $($_.Exception.Message)" 'ERROR'
        return $null
    }
}

# ---------------------------------------------------------------------------
# Build execution
# ---------------------------------------------------------------------------

function Invoke-BuildScript {
    <#
        Runs the generated wrapper with redirected output and continuously
        pumps logs. Returns:
        @{ Status; ExitCode; ErrorMessage; AbortReason }
    #>
    param(
        [Parameter(Mandatory = $true)][hashtable]$Api,
        [Parameter(Mandatory = $true)][hashtable]$Config,
        [Parameter(Mandatory = $true)][hashtable]$Context,
        [Parameter(Mandatory = $true)][hashtable]$LogState
    )

    Write-BuildWrapperScript -Context $Context | Out-Null

    $powershellExe = (Get-Command 'powershell.exe' -ErrorAction SilentlyContinue).Source
    if (-not $powershellExe) { $powershellExe = "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe" }

    Remove-Item -LiteralPath $Context.StdoutPath, $Context.StderrPath -Force -ErrorAction SilentlyContinue

    $processHandle = Start-RedirectedProcess -FilePath $powershellExe -Arguments @(
        '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $Context.ScriptPath
    ) -WorkingDirectory $Context.AppDir -StdoutPath $Context.StdoutPath -StderrPath $Context.StderrPath

    Write-BuildRunState -Context $Context -ProcessId $processHandle.Process.Id | Out-Null

    Write-RunnerLog ("Script iniciado (PID {0}, timeout {1}s)" -f $processHandle.Process.Id, $Context.TimeoutSeconds)
    Add-SystemLogLine -LogState $LogState -Message ("Executor: script iniciado (timeout de {0}s)." -f $Context.TimeoutSeconds)

    $startedAt = Get-Date
    $lastLogFlush = Get-Date
    $lastBuildHeartbeat = Get-Date
    $result = @{ Status = 'failed'; ExitCode = $null; ErrorMessage = ''; AbortReason = '' }

    try {
        while ($true) {
            $fileLines = Read-NewBuildLogLines -LogState $LogState -StdoutPath $Context.StdoutPath -StderrPath $Context.StderrPath
            $allLines = New-Object System.Collections.ArrayList
            foreach ($line in $fileLines) { $allLines.Add($line) | Out-Null }
            foreach ($line in (Get-SystemBufferLines -LogState $LogState)) { $allLines.Add($line) | Out-Null }

            if ($allLines.Count -gt 0) {
                foreach ($batch in (New-LogBatch -LogState $LogState -Lines $allLines `
                            -MaxLines ([int]$Config['log_batch_max_lines']) -MaxBytes ([int]$Config['log_batch_max_bytes']))) {
                    Add-PendingLogBatch -LogState $LogState -Batch $batch
                }
            }

            $now = Get-Date

            if (($now - $lastLogFlush).TotalSeconds -ge [int]$Config['log_batch_interval_seconds'] -or $LogState.PendingBatches.Count -ge 5) {
                $flush = Send-PendingLogBatches -Api $Api -BuildId $Context.Id -LogState $LogState
                $lastLogFlush = $now

                if ($flush.Abort) {
                    Write-RunnerLog "A API finalizou a build $($Context.Id); interrompendo o processo local." 'WARN'
                    Stop-ProcessTree -ProcessId $processHandle.Process.Id | Out-Null
                    $result.AbortReason = 'api'
                    return $result
                }

                if ($flush.Control -and $flush.Control.cancel_requested) {
                    Write-RunnerLog "Cancelamento solicitado para a build $($Context.Id); encerrando o processo." 'WARN'
                    Add-SystemLogLine -LogState $LogState -Message 'Executor: cancelamento solicitado pelo painel; encerrando processos.'
                    Stop-ProcessTree -ProcessId $processHandle.Process.Id | Out-Null
                    Start-Sleep -Seconds 2
                    $result.Status = 'cancelled'
                    $result.ErrorMessage = 'Build cancelada pelo painel.'
                    return $result
                }
            }

            if (($now - $lastBuildHeartbeat).TotalSeconds -ge [int]$Config['build_heartbeat_seconds']) {
                $lastBuildHeartbeat = $now

                try {
                    $heartbeat = Send-BuildHeartbeat -Api $Api -BuildId $Context.Id

                    if ($heartbeat -and $heartbeat.control -and $heartbeat.control.cancel_requested) {
                        Add-SystemLogLine -LogState $LogState -Message 'Executor: cancelamento solicitado pelo painel; encerrando processos.'
                        Stop-ProcessTree -ProcessId $processHandle.Process.Id | Out-Null
                        Start-Sleep -Seconds 2
                        $result.Status = 'cancelled'
                        $result.ErrorMessage = 'Build cancelada pelo painel.'
                        return $result
                    }
                } catch [RunnerApiException] {
                    if ($_.Exception.StatusCode -eq 409) {
                        Write-RunnerLog "A API finalizou a build $($Context.Id); interrompendo o processo local." 'WARN'
                        Stop-ProcessTree -ProcessId $processHandle.Process.Id | Out-Null
                        $result.AbortReason = 'api'
                        return $result
                    }
                    Write-RunnerLog "Heartbeat da build $($Context.Id) falhou: $($_.Exception.Message)" 'WARN'
                }
            }

            if (($now - $startedAt).TotalSeconds -gt $Context.TimeoutSeconds) {
                Write-RunnerLog "Timeout de $($Context.TimeoutSeconds)s excedido na build $($Context.Id); encerrando processos." 'ERROR'
                Add-SystemLogLine -LogState $LogState -Message ("Executor: timeout de {0}s excedido; encerrando processos." -f $Context.TimeoutSeconds)
                Stop-ProcessTree -ProcessId $processHandle.Process.Id | Out-Null
                Start-Sleep -Seconds 2
                $result.Status = 'failed'
                $result.ErrorMessage = ("Tempo máximo de execução excedido ({0}s)." -f $Context.TimeoutSeconds)
                return $result
            }

            $exitState = Get-ProcessExitState -Handle $processHandle
            if ($exitState.Exited) {
                # Drain the stream copies so every byte is flushed to disk
                # before the final read.
                Close-RedirectedProcess -Handle $processHandle -DrainTimeoutSeconds 10
                $finalLines = Read-NewBuildLogLines -LogState $LogState -StdoutPath $Context.StdoutPath -StderrPath $Context.StderrPath
                $tailLines = New-Object System.Collections.ArrayList
                foreach ($line in $finalLines) { $tailLines.Add($line) | Out-Null }
                Add-SystemLogLine -LogState $LogState -Message ("Executor: processo finalizado com código {0}." -f $exitState.ExitCode)
                foreach ($line in (Get-SystemBufferLines -LogState $LogState)) { $tailLines.Add($line) | Out-Null }

                if ($tailLines.Count -gt 0) {
                    foreach ($batch in (New-LogBatch -LogState $LogState -Lines $tailLines `
                                -MaxLines ([int]$Config['log_batch_max_lines']) -MaxBytes ([int]$Config['log_batch_max_bytes']))) {
                        Add-PendingLogBatch -LogState $LogState -Batch $batch
                    }
                }

                # Best effort flush; failures keep the batch queued for later retry.
                $null = Send-PendingLogBatches -Api $Api -BuildId $Context.Id -LogState $LogState

                $result.ExitCode = $exitState.ExitCode
                if ($exitState.ExitCode -eq 0) {
                    $result.Status = 'success'
                } else {
                    $result.Status = 'failed'
                    $errorTail = Get-StderrTail -Path $Context.StderrPath
                    if (-not $errorTail) { $errorTail = Get-StderrTail -Path $Context.StdoutPath }
                    $errorTail = Protect-SecretText -Text $errorTail -Masks $LogState.SecretMasks
                    $result.ErrorMessage = if ($errorTail) { "Script terminou com código $($exitState.ExitCode): $errorTail" }
                    else { "Script terminou com código $($exitState.ExitCode)." }
                }

                return $result
            }

            Start-Sleep -Milliseconds 400
        }
    } finally {
        Close-RedirectedProcess -Handle $processHandle -DrainTimeoutSeconds 10
        Clear-BuildRunState -Context $Context
    }
}

function Invoke-BuildWorkflow {
    <#
        Full lifecycle of one claimed build: workspace, checkout, start,
        script, artifacts, completion.
    #>
    param(
        [Parameter(Mandatory = $true)][hashtable]$Api,
        [Parameter(Mandatory = $true)][hashtable]$UploadClient,
        [Parameter(Mandatory = $true)][hashtable]$Config,
        [Parameter(Mandatory = $true)]$BuildPayload,
        [Parameter(Mandatory = $true)][hashtable]$Diagnostics
    )

    $context = New-BuildContext -Config $Config -Build $BuildPayload
    $logState = New-LogCollectorState -Config $Config
    $secretMasks = Get-SecretMaskValues -Context $context
    $logState.SecretMasks = $secretMasks
    $buildId = $context.Id

    Write-RunnerLog ("Build #{0} recebida: projeto '{1}' ({2}), perfil '{3}', ref: {4}" -f `
            $buildId, $context.ProjectName, $context.ProjectSlug, $context.ProfileName,
        $(if ($context.CommitSha) { $context.CommitSha.Substring(0, [Math]::Min(12, $context.CommitSha.Length)) } else { $context.Branch }))

    # ------------------------------------------------------------------
    # Workspace + checkout
    # ------------------------------------------------------------------
    Add-SystemLogLine -LogState $logState -Message 'Executor: preparando workspace e repositório.'

    try {
        Initialize-BuildWorkspace -Context $context -Config $Config | Out-Null
        Add-SystemLogLine -LogState $logState -Message ("Executor: checkout concluído no commit {0}." -f $context.ResolvedSha.Substring(0, 12))

        $secretFilesWritten = Write-BuildSecretFiles -Context $context
        if ($secretFilesWritten -gt 0) {
            Add-SystemLogLine -LogState $logState -Message ("Executor: {0} arquivo(s) secreto(s) aplicado(s) no workspace." -f $secretFilesWritten)
        }
    } catch {
        $message = Protect-SecretText -Text "Preparação falhou: $($_.Exception.Message)" -Masks $secretMasks
        Write-RunnerLog $message 'ERROR'
        Add-SystemLogLine -LogState $logState -Message ("Executor: {0}" -f $message)

        $lines = Get-SystemBufferLines -LogState $logState
        foreach ($batch in (New-LogBatch -LogState $logState -Lines $lines -MaxLines 200 -MaxBytes 262144)) {
            Add-PendingLogBatch -LogState $logState -Batch $batch
        }
        $null = Send-PendingLogBatches -Api $Api -BuildId $buildId -LogState $logState

        $null = Send-CompleteRequest -Api $Api -Config $Config -BuildId $buildId -Status 'failed' -ErrorMessage $message
        return
    }

    $lines = Get-SystemBufferLines -LogState $logState
    foreach ($batch in (New-LogBatch -LogState $logState -Lines $lines -MaxLines 200 -MaxBytes 262144)) {
        Add-PendingLogBatch -LogState $logState -Batch $batch
    }
    $null = Send-PendingLogBatches -Api $Api -BuildId $buildId -LogState $logState

    # ------------------------------------------------------------------
    # Start
    # ------------------------------------------------------------------
    try {
        $null = Invoke-ApiRequest -Api $Api -Method 'POST' -Path ("builds/{0}/start" -f $buildId) `
            -Body @{ resolved_sha = $context.ResolvedSha } -MaxAttempts 4
    } catch [RunnerApiException] {
        Write-RunnerLog "A API recusou o início da build ${buildId}: $($_.Exception.Message)" 'ERROR'
        return
    } catch {
        Write-RunnerLog "Não foi possível confirmar o início da build ${buildId}: $($_.Exception.Message)" 'ERROR'
        return
    }

    # ------------------------------------------------------------------
    # Script execution
    # ------------------------------------------------------------------
    $result = Invoke-BuildScript -Api $Api -Config $Config -Context $context -LogState $logState

    if ($result.AbortReason -eq 'api') {
        Write-RunnerLog "Build #$buildId finalizada pela API durante a execução; nada a reportar." 'WARN'
        return
    }

    Write-RunnerLog ("Build #{0} : script terminou como '{1}' (exit {2})" -f $buildId, $result.Status, $result.ExitCode)

    # ------------------------------------------------------------------
    # Artifacts
    # ------------------------------------------------------------------
    if ($result.Status -ne 'cancelled') {
        $artifactOutcome = Send-BuildArtifacts -Context $context -Config $Config -UploadClient $UploadClient `
            -BuildPayload $BuildPayload -SendSystemLog {
                param($message)
                Add-SystemLogLine -LogState $logState -Message ("Executor: {0}" -f $message)
            }

        if ($artifactOutcome.Found -gt 0) {
            Write-RunnerLog ("Artefatos: {0} enviados, {1} com falha." -f $artifactOutcome.Uploaded.Count, $artifactOutcome.Failed.Count)

            if ($artifactOutcome.Uploaded.Count -gt 0) {
                Add-SystemLogLine -LogState $logState -Message ("Executor: {0} artefato(s) enviados para a API." -f $artifactOutcome.Uploaded.Count)
            }
        } else {
            Write-RunnerLog 'Nenhum artefato encontrado para os padrões configurados.' 'WARN'
            Add-SystemLogLine -LogState $logState -Message 'Executor: nenhum artefato encontrado para os padrões do perfil.'
        }

        $lines = Get-SystemBufferLines -LogState $logState
        if ($lines.Count -gt 0) {
            foreach ($batch in (New-LogBatch -LogState $logState -Lines $lines -MaxLines 200 -MaxBytes 262144)) {
                Add-PendingLogBatch -LogState $logState -Batch $batch
            }
        }
        $null = Send-PendingLogBatches -Api $Api -BuildId $buildId -LogState $logState
    }

    # ------------------------------------------------------------------
    # Completion
    # ------------------------------------------------------------------
    $null = Send-CompleteRequest -Api $Api -Config $Config -BuildId $buildId `
        -Status $result.Status -ExitCode $result.ExitCode -ErrorMessage $result.ErrorMessage

    if ($result.Status -eq 'success' -and [bool]$Config['cleanup_successful_builds']) {
        try {
            if (Test-PathInsideRoot -Root ([string]$Config['workspace_root']) -Candidate $context.Workspace) {
                Remove-Item -LiteralPath $context.Workspace -Recurse -Force -ErrorAction Stop
                Write-RunnerLog "Workspace da build $buildId removido (cleanup_successful_builds=true)."
            }
        } catch {
            Write-RunnerLog "Falha ao limpar o workspace da build ${buildId}: $($_.Exception.Message)" 'WARN'
        }
    }
}

# ---------------------------------------------------------------------------
# Assigned build recovery
# ---------------------------------------------------------------------------

function Resume-AssignedBuild {
    <#
        Handles a build the API says is assigned to this runner (lost claim
        response or runner restart). Returns 'adopted', 'waiting' or 'none'.
    #>
    param(
        [Parameter(Mandatory = $true)][hashtable]$Api,
        [Parameter(Mandatory = $true)][hashtable]$Config,
        [Parameter(Mandatory = $true)]$AssignedBuild
    )

    try {
        $response = Invoke-ApiRequest -Api $Api -Method 'GET' -Path ("builds/{0}" -f [int]$AssignedBuild.id)
    } catch {
        Write-RunnerLog "Não foi possível obter a build atribuída $($AssignedBuild.id): $($_.Exception.Message)" 'WARN'
        return 'waiting'
    }

    if (-not $response -or -not $response.build) { return 'waiting' }

    $context = New-BuildContext -Config $Config -Build $response.build

    if ($response.build.status -eq 'preparing') {
        if (Test-BuildRunStateAlive -Context $context) {
            Write-RunnerLog "Build $($AssignedBuild.id) ainda tem um processo local ativo; aguardando." 'WARN'
            return 'waiting'
        }

        Write-RunnerLog "Retomando build $($AssignedBuild.id) (estado preparing, sem processo local)." 'WARN'
        return @{ Action = 'adopted'; Payload = $response.build }
    }

    # Running without a verifiable local process: never start a second execution.
    Write-RunnerLog ("Build {0} consta como 'running' na API sem processo local verificável. " +
        "Não haverá reexecução; aguardando a reconciliação da API (lease)." -f $AssignedBuild.id) 'WARN'

    return 'waiting'
}

# ---------------------------------------------------------------------------
# Main loop
# ---------------------------------------------------------------------------

function Invoke-RunnerLoop {
    param(
        [Parameter(Mandatory = $true)][hashtable]$Config,
        [Parameter(Mandatory = $true)][hashtable]$Diagnostics,
        [int]$MaxBuilds = 0,
        [switch]$Once
    )

    $api = New-RunnerHttpClient -Config $Config
    $uploadClient = New-RunnerUploadClient -Config $Config
    $completed = 0
    $lastHeartbeat = [datetime]::MinValue
    $failures = 0
    $waiting = $false

    while ($true) {
        # ------------------------------------------------------------------
        # Waiting for the API to reconcile a build that must not be re-run.
        # ------------------------------------------------------------------
        if ($waiting) {
            try {
                $heartbeat = Send-RunnerHeartbeat -Api $api -Config $Config -Diagnostics $Diagnostics
                $lastHeartbeat = Get-Date

                if (-not $heartbeat.current_build) {
                    $waiting = $false
                    Write-RunnerLog 'A API liberou a build pendente; retomando o ciclo normal.'
                }
            } catch {
                Write-RunnerLog "Heartbeat durante espera falhou: $($_.Exception.Message)" 'WARN'
            }

            Start-Sleep -Seconds ([int]$Config['poll_interval_seconds'])
            continue
        }

        $now = Get-Date
        $heartbeatDue = ($lastHeartbeat -eq [datetime]::MinValue) -or
            (($now - $lastHeartbeat).TotalSeconds -ge [int]$Config['idle_heartbeat_seconds'])

        if ($heartbeatDue) {
            try {
                $heartbeat = Send-RunnerHeartbeat -Api $api -Config $Config -Diagnostics $Diagnostics
                $lastHeartbeat = Get-Date
                $failures = 0

                if ($heartbeat -and $heartbeat.current_build) {
                    $resume = Resume-AssignedBuild -Api $api -Config $Config -AssignedBuild $heartbeat.current_build

                    if ($resume -is [hashtable] -and $resume.Action -eq 'adopted') {
                        Invoke-BuildWorkflow -Api $api -UploadClient $uploadClient -Config $Config `
                            -BuildPayload $resume.Payload -Diagnostics $Diagnostics
                        $completed++
                        if ($MaxBuilds -gt 0 -and $completed -ge $MaxBuilds) { return $completed }
                        if ($Once) { return $completed }
                        continue
                    }

                    # Never re-execute blindly; wait for reconciliation.
                    $waiting = $true
                    Start-Sleep -Seconds ([int]$Config['poll_interval_seconds'])
                    continue
                }
            } catch {
                $failures++
                $delay = [Math]::Min(60, [Math]::Pow(2, [Math]::Min($failures, 6)))
                Write-RunnerLog "API indisponível no heartbeat: $($_.Exception.Message). Nova tentativa em ${delay}s." 'WARN'
                Start-Sleep -Seconds $delay
                continue
            }
        }

        # ------------------------------------------------------------------
        # Claim
        # ------------------------------------------------------------------
        try {
            $claim = Invoke-ApiRequest -Api $api -Method 'POST' -Path 'builds/claim' -Body @{} -MaxAttempts 1
        } catch [RunnerApiException] {
            Write-RunnerLog "Claim falhou: $($_.Exception.Message)" 'WARN'
            $claim = $null
        } catch {
            Write-RunnerLog "Claim falhou (rede): $($_.Exception.Message)" 'WARN'
            # A lost response may have claimed a build; force heartbeat first.
            $lastHeartbeat = [datetime]::MinValue
            $claim = $null
            Start-Sleep -Seconds ([int]$Config['poll_interval_seconds'])
            continue
        }

        if ($claim -and $claim.build) {
            Invoke-BuildWorkflow -Api $api -UploadClient $uploadClient -Config $Config `
                -BuildPayload $claim.build -Diagnostics $Diagnostics
            $completed++

            if ($MaxBuilds -gt 0 -and $completed -ge $MaxBuilds) { return $completed }
            if ($Once) { return $completed }
            continue
        }

        if ($Once) { return $completed }
        Start-Sleep -Seconds ([int]$Config['poll_interval_seconds'])
    }
}

# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

try {
    $config = New-RunnerConfig -ConfigPath $ConfigPath
    $null = Assert-RunnerConfig -Config $config
} catch {
    Write-Host "Erro de configuração: $($_.Exception.Message)" -ForegroundColor Red
    exit 2
}

Initialize-RunnerLog -LogDirectory ([string]$config['log_directory'])

$runnerVersion = Get-RunnerVersion
$diagnostics = Get-RunnerDiagnostics -Config $config

if ($Diagnose) {
    Write-Host ("Runner v{0} - diagnóstico" -f $runnerVersion)
    Write-Host (Get-RedactedConfigSummary -Config $config)
    foreach ($key in ($diagnostics.Keys | Sort-Object)) {
        Write-Host ("  {0}: {1}" -f $key, $diagnostics[$key])
    }
    exit 0
}

Write-RunnerLog ("Runner v{0} iniciando. {1}" -f $runnerVersion, (Get-RedactedConfigSummary -Config $config))

$exitCode = 0
try {
    $completed = Invoke-RunnerLoop -Config $config -Diagnostics $diagnostics -MaxBuilds $MaxBuilds -Once:$Once
    Write-RunnerLog ("Runner encerrado. Builds executadas nesta sessão: {0}." -f $completed)
} catch {
    Write-RunnerLog "Erro fatal no runner: $($_.Exception.Message)" 'ERROR'
    Write-RunnerLog $_.ScriptStackTrace 'ERROR'
    $exitCode = 1
}

exit $exitCode
