#Requires -Version 5.1
<#
    Automatic store submission after a successful build.

    The profile carries an optional Google Play configuration. The runner uses
    the project secret file selected by the profile (usually credentials.json)
    and a dependency-free Node.js helper to upload the AAB and commit a track
    release through the Google Play Developer API.
#>

Set-StrictMode -Version 2.0

function Get-SubmitConfigValue {
    param(
        $Config,
        [Parameter(Mandatory = $true)][string]$Name,
        [string]$Default = ''
    )

    if ($null -eq $Config -or $Config -is [string]) { return $Default }

    $property = $Config.PSObject.Properties[$Name]
    if ($null -eq $property -or $null -eq $property.Value) { return $Default }

    return [string]$property.Value
}

function Get-SubmitConfiguration {
    <#
        Reads and validates the submit block received in the build payload.
        Returns $null when automatic submit is disabled.
    #>
    param([Parameter(Mandatory = $true)][hashtable]$Context)

    if (-not $Context.ContainsKey('AutoSubmit') -or -not [bool]$Context.AutoSubmit) {
        return $null
    }

    $config = $Context.SubmitConfig
    if ($null -eq $config -or $config -is [string]) {
        throw 'Submit automático habilitado, mas a configuração do perfil está vazia.'
    }

    $provider = Get-SubmitConfigValue -Config $config -Name 'provider' -Default 'google_play'
    if ($provider -ne 'google_play') {
        throw "Provedor de submit não suportado: $provider."
    }

    $packageName = Get-SubmitConfigValue -Config $config -Name 'package_name'
    if ($packageName -notmatch '^[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*)+$') {
        throw "Package name inválido no submit automático: '$packageName'."
    }

    $track = Get-SubmitConfigValue -Config $config -Name 'track'
    if ($track -notmatch '^[a-z][a-z0-9_-]*$') {
        throw "Track inválida no submit automático: '$track'."
    }

    $serviceAccountFile = Get-SubmitConfigValue -Config $config -Name 'service_account_file'
    if (-not (Test-SecretFilePath -Path $serviceAccountFile)) {
        throw "Caminho inválido para a service account do submit: '$serviceAccountFile'."
    }

    $artifactPattern = Get-SubmitConfigValue -Config $config -Name 'artifact_pattern' `
        -Default 'android/app/build/outputs/bundle/release/*.aab'
    if (-not (Test-SafeArtifactPattern -Pattern $artifactPattern)) {
        throw "Padrão de AAB inválido no submit automático: '$artifactPattern'."
    }

    $releaseStatus = Get-SubmitConfigValue -Config $config -Name 'release_status' -Default 'completed'
    if ($releaseStatus -notin @('completed', 'draft')) {
        throw "Status de release inválido no submit automático: '$releaseStatus'."
    }

    return @{
        Provider = $provider
        PackageName = $packageName
        Track = $track
        ServiceAccountFile = $serviceAccountFile
        ArtifactPattern = $artifactPattern
        ReleaseStatus = $releaseStatus
        ReleaseName = Get-SubmitConfigValue -Config $config -Name 'release_name'
    }
}

function Resolve-SubmitServiceAccount {
    <#
        Resolves the service account inside the workspace. It must have been
        materialized from a project secret file (or exist in the repository),
        but can never escape the workspace.
    #>
    param(
        [Parameter(Mandatory = $true)][hashtable]$Context,
        [Parameter(Mandatory = $true)][string]$RelativePath
    )

    if (-not (Test-SecretFilePath -Path $RelativePath)) {
        throw "Caminho inválido para a service account do submit: '$RelativePath'."
    }

    $candidate = Join-Path ([string]$Context.Workspace) ($RelativePath -replace '/', '\')

    if (-not (Test-PathInsideRoot -Root ([string]$Context.Workspace) -Candidate $candidate)) {
        throw "A service account do submit está fora do workspace: '$RelativePath'."
    }

    if (-not (Test-Path -LiteralPath $candidate -PathType Leaf)) {
        throw "Service account do submit não encontrada em '$RelativePath'. Cadastre o JSON como arquivo secreto do projeto."
    }

    return (Get-Item -LiteralPath $candidate).FullName
}

function Get-SubmitArtifact {
    <#
        Finds the AAB inside the application directory. If more than one file
        matches, the newest one wins and a warning is logged.
    #>
    param(
        [Parameter(Mandatory = $true)][hashtable]$Context,
        [Parameter(Mandatory = $true)][string]$Pattern
    )

    $candidates = @(Get-ArtifactCandidates -BaseDirectory ([string]$Context.AppDir) -Patterns @($Pattern) |
            Where-Object { $_.Extension -ieq '.aab' })

    if ($candidates.Count -eq 0) {
        throw "Nenhum AAB encontrado para o submit com o padrão '$Pattern'."
    }

    if ($candidates.Count -gt 1) {
        Write-RunnerLog ("Submit: {0} AABs encontrados; usando o mais recente." -f $candidates.Count) 'WARN'
        $candidates = @($candidates | Sort-Object -Property LastWriteTime -Descending)
    }

    $file = $candidates[0]

    if (-not (Test-PathInsideRoot -Root ([string]$Context.AppDir) -Candidate $file.FullName)) {
        throw "AAB do submit está fora do diretório da aplicação: $($file.FullName)"
    }

    return $file
}

function Write-SubmitConfigFile {
    param(
        [Parameter(Mandatory = $true)][hashtable]$Context,
        [Parameter(Mandatory = $true)][hashtable]$Settings,
        [Parameter(Mandatory = $true)][string]$ServiceAccountPath,
        [Parameter(Mandatory = $true)][System.IO.FileInfo]$Artifact
    )

    if (-not (Test-Path -LiteralPath $Context.RunnerDir)) {
        New-Item -ItemType Directory -Path $Context.RunnerDir -Force | Out-Null
    }

    $configPath = Join-Path $Context.RunnerDir 'submit.json'
    $payload = [ordered]@{
        serviceAccount = $ServiceAccountPath
        packageName = $Settings.PackageName
        track = $Settings.Track
        aab = $Artifact.FullName
        releaseStatus = $Settings.ReleaseStatus
        releaseName = $Settings.ReleaseName
    } | ConvertTo-Json -Compress

    [System.IO.File]::WriteAllText($configPath, $payload, (New-Object System.Text.UTF8Encoding($false)))

    return $configPath
}

function Get-SubmitTextTail {
    param(
        [string]$Text,
        [int]$MaxLength = 1500
    )

    if ([string]::IsNullOrWhiteSpace($Text)) { return '' }

    $value = ($Text -split "`r?`n" | Where-Object { $_.Trim() -ne '' } | Select-Object -Last 12) -join ' '
    $value = $value.Trim()

    if ($value.Length -gt $MaxLength) {
        $value = '...' + $value.Substring($value.Length - $MaxLength)
    }

    return $value
}

function Invoke-BuildSubmit {
    <#
        Runs the Google Play submit helper, streaming its output to the build
        log and renewing the build lease while the AAB is uploaded.

        Returns:
          $null                                      when auto submit is disabled
          @{ Status; Message; VersionCode; AbortReason }
    #>
    param(
        [Parameter(Mandatory = $true)][hashtable]$Api,
        [Parameter(Mandatory = $true)][hashtable]$Config,
        [Parameter(Mandatory = $true)][hashtable]$Context,
        [Parameter(Mandatory = $true)][hashtable]$LogState
    )

    try {
        $settings = Get-SubmitConfiguration -Context $Context
    } catch {
        return @{ Status = 'failed'; Message = $_.Exception.Message; VersionCode = ''; AbortReason = '' }
    }

    if ($null -eq $settings) { return $null }

    $node = Get-Command 'node' -ErrorAction SilentlyContinue
    if (-not $node) {
        return @{
            Status = 'failed'
            Message = 'Node.js não encontrado no PATH; necessário para o submit automático.'
            VersionCode = ''
            AbortReason = ''
        }
    }

    $scriptPath = Join-Path (Split-Path -Parent $PSScriptRoot) 'scripts\google-play-submit.js'
    if (-not (Test-Path -LiteralPath $scriptPath -PathType Leaf)) {
        return @{
            Status = 'failed'
            Message = "Helper de submit não encontrado: $scriptPath"
            VersionCode = ''
            AbortReason = ''
        }
    }

    try {
        $serviceAccountPath = Resolve-SubmitServiceAccount -Context $Context -RelativePath $settings.ServiceAccountFile
        $artifact = Get-SubmitArtifact -Context $Context -Pattern $settings.ArtifactPattern
        $submitConfigPath = Write-SubmitConfigFile -Context $Context -Settings $settings `
            -ServiceAccountPath $serviceAccountPath -Artifact $artifact
    } catch {
        return @{ Status = 'failed'; Message = $_.Exception.Message; VersionCode = ''; AbortReason = '' }
    }

    $message = "Submit automático: {0} → {1} ({2:N1} MB)" -f `
        $settings.PackageName, $settings.Track, ($artifact.Length / 1MB)
    Write-RunnerLog $message
    Add-SystemLogLine -LogState $LogState -Message ("Executor: {0}" -f $message)

    # The submit process has its own stdout/stderr files; reset the tail
    # positions without losing the global line numbering.
    $LogState.StdoutPosition = [long]0
    $LogState.StderrPosition = [long]0
    $LogState.StdoutPartial = ''
    $LogState.StderrPartial = ''

    Remove-Item -LiteralPath $Context.SubmitStdoutPath, $Context.SubmitStderrPath -Force -ErrorAction SilentlyContinue

    $processHandle = $null
    $result = @{ Status = 'failed'; Message = ''; VersionCode = ''; AbortReason = '' }
    $outputLines = New-Object System.Collections.ArrayList

    try {
        $processHandle = Start-RedirectedProcess -FilePath $node.Source -Arguments @(
            $scriptPath, '--config', $submitConfigPath
        ) -WorkingDirectory ([string]$Context.AppDir) `
            -StdoutPath $Context.SubmitStdoutPath -StderrPath $Context.SubmitStderrPath

        Write-RunnerLog ("Submit iniciado (PID {0}, timeout {1}s)" -f `
                $processHandle.Process.Id, [int]$Config['submit_timeout_seconds'])

        $startedAt = Get-Date
        $lastLogFlush = Get-Date
        $lastBuildHeartbeat = Get-Date

        while ($true) {
            $fileLines = Read-NewBuildLogLines -LogState $LogState `
                -StdoutPath $Context.SubmitStdoutPath -StderrPath $Context.SubmitStderrPath

            $allLines = New-Object System.Collections.ArrayList
            foreach ($line in $fileLines) {
                $allLines.Add($line) | Out-Null
                $outputLines.Add([string]$line.Message) | Out-Null
            }
            foreach ($line in (Get-SystemBufferLines -LogState $LogState)) {
                $allLines.Add($line) | Out-Null
            }

            if ($allLines.Count -gt 0) {
                foreach ($batch in (New-LogBatch -LogState $LogState -Lines $allLines `
                            -MaxLines ([int]$Config['log_batch_max_lines']) -MaxBytes ([int]$Config['log_batch_max_bytes']))) {
                    Add-PendingLogBatch -LogState $LogState -Batch $batch
                }
            }

            $now = Get-Date

            if (($now - $lastLogFlush).TotalSeconds -ge [int]$Config['log_batch_interval_seconds'] -or
                $LogState.PendingBatches.Count -ge 5) {
                $flush = Send-PendingLogBatches -Api $Api -BuildId $Context.Id -LogState $LogState
                $lastLogFlush = $now

                if ($flush.Abort) {
                    Write-RunnerLog "A API finalizou a build $($Context.Id) durante o submit; interrompendo." 'WARN'
                    Stop-ProcessTree -ProcessId $processHandle.Process.Id | Out-Null
                    $result.AbortReason = 'api'
                    return $result
                }

                if ($flush.Control -and $flush.Control.cancel_requested) {
                    Write-RunnerLog "Cancelamento solicitado durante o submit da build $($Context.Id)." 'WARN'
                    Add-SystemLogLine -LogState $LogState -Message 'Executor: cancelamento solicitado durante o submit; encerrando.'
                    Stop-ProcessTree -ProcessId $processHandle.Process.Id | Out-Null
                    Start-Sleep -Seconds 2
                    $result.Status = 'cancelled'
                    $result.Message = 'Build cancelada durante o submit automático.'
                    return $result
                }
            }

            if (($now - $lastBuildHeartbeat).TotalSeconds -ge [int]$Config['build_heartbeat_seconds']) {
                $lastBuildHeartbeat = $now

                try {
                    $heartbeat = Send-BuildHeartbeat -Api $Api -BuildId $Context.Id

                    if ($heartbeat -and $heartbeat.control -and $heartbeat.control.cancel_requested) {
                        Add-SystemLogLine -LogState $LogState -Message 'Executor: cancelamento solicitado durante o submit; encerrando.'
                        Stop-ProcessTree -ProcessId $processHandle.Process.Id | Out-Null
                        Start-Sleep -Seconds 2
                        $result.Status = 'cancelled'
                        $result.Message = 'Build cancelada durante o submit automático.'
                        return $result
                    }
                } catch [RunnerApiException] {
                    if ($_.Exception.StatusCode -eq 409) {
                        Write-RunnerLog "A API finalizou a build $($Context.Id) durante o submit." 'WARN'
                        Stop-ProcessTree -ProcessId $processHandle.Process.Id | Out-Null
                        $result.AbortReason = 'api'
                        return $result
                    }
                    Write-RunnerLog "Heartbeat durante o submit falhou: $($_.Exception.Message)" 'WARN'
                }
            }

            if (($now - $startedAt).TotalSeconds -gt [int]$Config['submit_timeout_seconds']) {
                Write-RunnerLog "Timeout de $($Config['submit_timeout_seconds'])s excedido no submit da build $($Context.Id)." 'ERROR'
                Stop-ProcessTree -ProcessId $processHandle.Process.Id | Out-Null
                Start-Sleep -Seconds 2
                $result.Status = 'failed'
                $result.Message = ("Tempo máximo do submit excedido ({0}s)." -f $Config['submit_timeout_seconds'])
                return $result
            }

            $exitState = Get-ProcessExitState -Handle $processHandle
            if ($exitState.Exited) {
                Close-RedirectedProcess -Handle $processHandle -DrainTimeoutSeconds 10
                $finalLines = Read-NewBuildLogLines -LogState $LogState `
                    -StdoutPath $Context.SubmitStdoutPath -StderrPath $Context.SubmitStderrPath
                $tailLines = New-Object System.Collections.ArrayList
                foreach ($line in $finalLines) {
                    $tailLines.Add($line) | Out-Null
                    $outputLines.Add([string]$line.Message) | Out-Null
                }
                foreach ($line in (Get-SystemBufferLines -LogState $LogState)) {
                    $tailLines.Add($line) | Out-Null
                }

                if ($tailLines.Count -gt 0) {
                    foreach ($batch in (New-LogBatch -LogState $LogState -Lines $tailLines `
                                -MaxLines ([int]$Config['log_batch_max_lines']) -MaxBytes ([int]$Config['log_batch_max_bytes']))) {
                        Add-PendingLogBatch -LogState $LogState -Batch $batch
                    }
                }

                $null = Send-PendingLogBatches -Api $Api -BuildId $Context.Id -LogState $LogState

                $stdoutText = Read-CapturedText -Path $Context.SubmitStdoutPath
                $stderrText = Read-CapturedText -Path $Context.SubmitStderrPath
                $allText = ($outputLines -join "`n") + "`n" + $stdoutText + "`n" + $stderrText

                $versionMatch = [regex]::Match($allText, '(?m)^SUBMIT_VERSION_CODE=(\d+)\s*$')
                if ($versionMatch.Success) { $result.VersionCode = $versionMatch.Groups[1].Value }
                $warningMatch = [regex]::Match($allText, '(?m)^SUBMIT_WARNING=(.+?)\s*$')

                if ($exitState.ExitCode -eq 0) {
                    $result.Status = 'success'
                    $result.Message = "Submit concluído para a track $($settings.Track)"
                    if ($result.VersionCode) { $result.Message += " (versionCode $($result.VersionCode))" }
                    $result.Message += '.'

                    if ($warningMatch.Success -and $warningMatch.Groups[1].Value -eq 'changes_not_sent_for_review') {
                        $result.Message += ' Aviso: o Google não enviou as alterações para revisão; verifique o Play Console.'
                    }
                } else {
                    $result.Status = 'failed'
                    $tail = Get-SubmitTextTail -Text $stderrText
                    if (-not $tail) { $tail = Get-SubmitTextTail -Text $stdoutText }
                    $tail = Protect-SecretText -Text $tail -Masks $LogState.SecretMasks
                    $result.Message = if ($tail) {
                        "Submit falhou com código $($exitState.ExitCode): $tail"
                    } else {
                        "Submit falhou com código $($exitState.ExitCode)."
                    }
                }

                return $result
            }

            Start-Sleep -Milliseconds 400
        }
    } catch {
        if ($processHandle) {
            Stop-ProcessTree -ProcessId $processHandle.Process.Id | Out-Null
        }
        return @{
            Status = 'failed'
            Message = "Falha ao executar o submit automático: $($_.Exception.Message)"
            VersionCode = ''
            AbortReason = ''
        }
    } finally {
        if ($processHandle) {
            Close-RedirectedProcess -Handle $processHandle -DrainTimeoutSeconds 10
        }
    }
}
