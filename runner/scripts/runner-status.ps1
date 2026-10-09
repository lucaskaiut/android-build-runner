#Requires -Version 5.1
<#
    Controls the runner when it is installed as a scheduled task.
    Also shows local diagnostics and the last log lines.
#>

[CmdletBinding()]
param(
    [string]$ConfigPath = '',
    [int]$Tail = 20,
    [switch]$Json
)

$ErrorActionPreference = 'Stop'

$runnerRoot = Split-Path -Parent $PSScriptRoot
$taskName = 'LocalBuildPlatformRunner'

$configFile = $ConfigPath
if (-not $configFile) {
    $configFile = Join-Path $env:ProgramData 'LocalBuildPlatform\runner\config.json'
    if (-not (Test-Path -LiteralPath $configFile)) {
        $configFile = Join-Path $runnerRoot 'config\runner.config.json'
    }
}

$config = $null
if (Test-Path -LiteralPath $configFile) {
    $config = Get-Content -LiteralPath $configFile -Raw | ConvertFrom-Json
}

$task = $null
try { $task = Get-ScheduledTask -TaskName $taskName -ErrorAction Stop } catch { }

$logFile = $null
if ($config -and $config.log_directory) {
    $logFile = Join-Path $config.log_directory 'runner.log'
}

$status = [pscustomobject]@{
    config_path = $configFile
    config_found = [bool]$config
    api_url = if ($config) { $config.api_url } else { $null }
    runner_name = if ($config) { $config.name } else { $null }
    task_installed = [bool]$task
    task_state = if ($task) { $task.State.ToString() } else { 'nao instalada' }
    log_file = $logFile
    log_file_exists = ($logFile -and (Test-Path -LiteralPath $logFile))
}

if ($Json) {
    $status | ConvertTo-Json -Depth 4
    exit 0
}

Write-Host '=== Runner status ==='
$status | Format-List
if ($task) {
    Write-Host 'Última execução da tarefa:'
    Get-ScheduledTaskInfo -TaskName $taskName | Format-List LastRunTime, LastTaskResult, NextRunTime
}

if ($status.log_file_exists) {
    Write-Host "Últimas $Tail linhas de $logFile :"
    Get-Content -LiteralPath $logFile -Tail $Tail
} else {
    Write-Host 'Nenhum log local encontrado ainda.'
}
