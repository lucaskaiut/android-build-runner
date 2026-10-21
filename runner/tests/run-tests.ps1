#Requires -Version 5.1
<#
    Test entry point. Runs every Test_* function found in *.Tests.ps1 files.

    Usage:
        powershell.exe -NoProfile -ExecutionPolicy Bypass -File tests\run-tests.ps1
        powershell.exe ... -File tests\run-tests.ps1 -Filter Config
#>

[CmdletBinding()]
param(
    [string]$Filter = ''
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$testsDirectory = $PSScriptRoot
$runnerRoot = Split-Path -Parent $testsDirectory

. (Join-Path $testsDirectory 'TestFramework.ps1')
. (Join-Path $runnerRoot 'src\RunnerCommon.ps1')
. (Join-Path $runnerRoot 'src\RunnerConfig.ps1')
. (Join-Path $runnerRoot 'src\ApiClient.ps1')
. (Join-Path $runnerRoot 'src\ProcessControl.ps1')
. (Join-Path $runnerRoot 'src\LogCollector.ps1')
. (Join-Path $runnerRoot 'src\Secrets.ps1')
. (Join-Path $runnerRoot 'src\Workspace.ps1')
. (Join-Path $runnerRoot 'src\ArtifactCollector.ps1')
. (Join-Path $runnerRoot 'src\Submit.ps1')

Write-Host '=== Local Build Platform - testes do runner ==='
Write-Host ''

$existingFunctions = @(Get-ChildItem Function: | Select-Object -ExpandProperty Name)

foreach ($file in (Get-ChildItem -LiteralPath $testsDirectory -Filter '*.Tests.ps1' | Sort-Object Name)) {
    . $file.FullName
}

$testFunctions = @(
    Get-ChildItem Function: |
        Select-Object -ExpandProperty Name |
        Where-Object { $_ -like 'Test_*' -and ($existingFunctions -notcontains $_) }
)

if ($Filter) {
    $testFunctions = @($testFunctions | Where-Object { $_ -like "*$Filter*" })
}

if ($testFunctions.Count -eq 0) {
    Write-Host 'Nenhum teste encontrado.' -ForegroundColor Yellow
    exit 1
}

foreach ($name in ($testFunctions | Sort-Object)) {
    try {
        & $name
        Register-TestResult -Name $name -Passed $true
    } catch {
        Register-TestResult -Name $name -Passed $false -Message $_.Exception.Message
    }
}

Write-Host ''
Write-Host ("Resultado: {0} passaram, {1} falharam." -f $script:PassedCount, $script:FailedCount)

if ($script:FailedCount -gt 0) {
    Write-Host ''
    Write-Host 'Falhas:' -ForegroundColor Red
    foreach ($message in $script:FailureMessages) {
        Write-Host ("  - {0}" -f $message) -ForegroundColor Red
    }

    exit 1
}

exit 0
