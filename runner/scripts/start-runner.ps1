#Requires -Version 5.1
<#
    Starts the runner in the foreground (interactive mode).
    Ctrl+C stops the runner; a build in progress is lost and recovered by the
    API lease reaper, exactly like a machine reboot.
#>

[CmdletBinding()]
param(
    [string]$ConfigPath = '',
    [switch]$Once,
    [int]$MaxBuilds = 0
)

$ErrorActionPreference = 'Stop'

$runnerRoot = Split-Path -Parent $PSScriptRoot
$entry = Join-Path $runnerRoot 'src\BuildRunner.ps1'

$arguments = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $entry)

if ($ConfigPath) { $arguments += @('-ConfigPath', $ConfigPath) }
if ($Once) { $arguments += '-Once' }
if ($MaxBuilds -gt 0) { $arguments += @('-MaxBuilds', [string]$MaxBuilds) }

Write-Host ("Executando: powershell.exe {0}" -f ($arguments -join ' '))
& powershell.exe @arguments
exit $LASTEXITCODE
