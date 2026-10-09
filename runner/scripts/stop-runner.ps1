#Requires -Version 5.1
<#
    Stops the runner task and optionally terminates remaining runner processes.
    A build in progress is not reported as success: the API lease reaper will
    reconcile it.
#>

[CmdletBinding()]
param(
    [string]$TaskName = 'LocalBuildPlatformRunner',
    [switch]$Force
)

$ErrorActionPreference = 'Stop'

$task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if ($task) {
    Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    Write-Host "Tarefa '$TaskName' parada."
} else {
    Write-Host "Tarefa '$TaskName' não encontrada."
}

if ($Force) {
    $runnerProcesses = Get-CimInstance Win32_Process -Filter "Name = 'powershell.exe'" |
        Where-Object { $_.CommandLine -and $_.CommandLine -match 'BuildRunner\.ps1' }

    foreach ($process in $runnerProcesses) {
        Write-Host "Encerrando PID $($process.ProcessId)..."
        Stop-Process -Id $process.ProcessId -Force -ErrorAction SilentlyContinue
    }
}
