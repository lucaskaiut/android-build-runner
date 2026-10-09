#Requires -Version 5.1
<#
    Installs the runner as a Scheduled Task.

    The task runs as the current (or given) Windows account when that account
    logs on - no password is stored by default. This guarantees access to the
    user's PATH, SSH keys and Android/Gradle caches, which LocalSystem does
    NOT have. For a true unattended service, prefer a dedicated account with
    "run whether user is logged on or not" or a service wrapper (e.g. NSSM)
    documented in runner/README.md.
#>

[CmdletBinding()]
param(
    [string]$ConfigPath = (Join-Path $env:ProgramData 'LocalBuildPlatform\runner\config.json'),
    [string]$TaskName = 'LocalBuildPlatformRunner',
    [string]$Account = "$env:USERDOMAIN\$env:USERNAME",
    [switch]$StartNow
)

$ErrorActionPreference = 'Stop'

$runnerRoot = Split-Path -Parent $PSScriptRoot
$entry = Join-Path $runnerRoot 'src\BuildRunner.ps1'

if (-not (Test-Path -LiteralPath $entry)) {
    throw "BuildRunner.ps1 não encontrado em $entry"
}

if (-not (Test-Path -LiteralPath $ConfigPath)) {
    throw "Configuração não encontrada em $ConfigPath. Execute scripts\install.ps1 primeiro."
}

$action = "powershell.exe -NoProfile -ExecutionPolicy Bypass -File `"$entry`" -ConfigPath `"$ConfigPath`""

$existing = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if ($existing) {
    Write-Host "Removendo tarefa existente '$TaskName'..."
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
}

$taskAction = New-ScheduledTaskAction -Execute 'powershell.exe' `
    -Argument ("-NoProfile -ExecutionPolicy Bypass -File `"$entry`" -ConfigPath `"$ConfigPath`"")
$trigger = New-ScheduledTaskTrigger -AtLogOn -User $Account
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -StartWhenAvailable -RestartCount 5 -RestartInterval (New-TimeSpan -Minutes 1) `
    -ExecutionTimeLimit ([TimeSpan]::Zero)

$principal = New-ScheduledTaskPrincipal -UserId $Account -LogonType Interactive -RunLevel Highest

Register-ScheduledTask -TaskName $TaskName -Action $taskAction -Trigger $trigger `
    -Settings $settings -Principal $principal `
    -Description 'Executa builds Expo/React Native da Local Build Platform.' | Out-Null

Write-Host "Tarefa '$TaskName' registrada para o usuário $Account (inicia no logon)."

if ($StartNow) {
    Start-ScheduledTask -TaskName $TaskName
    Write-Host 'Tarefa iniciada agora.'
}

Write-Host ''
Write-Host 'Para gerenciar:'
Write-Host "  Iniciar:  Start-ScheduledTask -TaskName $TaskName"
Write-Host "  Parar:    Stop-ScheduledTask -TaskName $TaskName"
Write-Host "  Status:   scripts\runner-status.ps1"
Write-Host "  Remover:  scripts\uninstall-service.ps1"
