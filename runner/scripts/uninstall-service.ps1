#Requires -Version 5.1
<#
    Removes the scheduled task (does not touch configuration, logs or
    workspaces).
#>

[CmdletBinding()]
param(
    [string]$TaskName = 'LocalBuildPlatformRunner'
)

$ErrorActionPreference = 'Stop'

$task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if ($task) {
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
    Write-Host "Tarefa '$TaskName' removida."
} else {
    Write-Host "Tarefa '$TaskName' não encontrada."
}
