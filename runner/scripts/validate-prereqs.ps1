#Requires -Version 5.1
<#
    Validates the Windows build environment required by the runner.
    Exit code 0 = ok (warnings allowed), 1 = missing mandatory tooling.
#>

[CmdletBinding()]
param(
    [string]$RepoUrl = '',
    [switch]$RequireBuildTools
)

$ErrorActionPreference = 'Stop'

function New-CheckResult {
    param([string]$Name, [string]$Status, [string]$Detail)
    return [pscustomobject]@{ Name = $Name; Status = $Status; Detail = $Detail }
}

$results = New-Object System.Collections.Generic.List[object]

function Test-Tool {
    param(
        [string]$Name,
        [string]$Command,
        [string[]]$Arguments,
        [switch]$Required,
        [switch]$BuildTool
    )

    $found = Get-Command $Command -ErrorAction SilentlyContinue
    $mandatory = $Required -or ($BuildTool -and $RequireBuildTools)

    if (-not $found) {
        $results.Add((New-CheckResult -Name $Name -Status ($(if ($mandatory) { 'FALTA' } else { 'AVISO' })) -Detail "comando '$Command' não encontrado no PATH"))
        return $false
    }

    $version = ''
    try {
        $output = & $Command @Arguments 2>&1 | Select-Object -First 1
        $version = [string]$output
    } catch { }

    $results.Add((New-CheckResult -Name $Name -Status 'OK' -Detail ("{0} ({1})" -f $found.Source, $version.Trim())))
    return $true
}

Write-Host '=== Local Build Platform - validação de pré-requisitos do runner ==='
Write-Host ''

$gitOk = Test-Tool -Name 'Git' -Command 'git' -Arguments @('--version') -Required

Test-Tool -Name 'Node.js' -Command 'node' -Arguments @('--version') -BuildTool
Test-Tool -Name 'npm' -Command 'npm' -Arguments @('--version') -BuildTool
Test-Tool -Name 'Java' -Command 'java' -Arguments @('-version') -BuildTool
Test-Tool -Name 'Android adb' -Command 'adb' -Arguments @('version') -BuildTool
Test-Tool -Name 'OpenSSH' -Command 'ssh' -Arguments @('-V') -BuildTool

# Android SDK
$androidHome = $env:ANDROID_HOME
if (-not $androidHome) { $androidHome = $env:ANDROID_SDK_ROOT }

if ($androidHome -and (Test-Path -LiteralPath $androidHome)) {
    $platforms = @()
    $buildTools = @()
    if (Test-Path (Join-Path $androidHome 'platforms')) { $platforms = Get-ChildItem (Join-Path $androidHome 'platforms') -Directory | Select-Object -ExpandProperty Name }
    if (Test-Path (Join-Path $androidHome 'build-tools')) { $buildTools = Get-ChildItem (Join-Path $androidHome 'build-tools') -Directory | Select-Object -ExpandProperty Name }

    $results.Add((New-CheckResult -Name 'Android SDK' -Status 'OK' -Detail ("{0} (platforms: {1}; build-tools: {2})" -f $androidHome, ($platforms -join ', '), ($buildTools -join ', '))))

    if ($platforms.Count -eq 0 -or $buildTools.Count -eq 0) {
        $results.Add((New-CheckResult -Name 'Android SDK completo' -Status 'AVISO' -Detail 'instale pelo menos um platforms;android-XX e build-tools;XX via sdkmanager'))
    }
} else {
    $status = if ($RequireBuildTools) { 'FALTA' } else { 'AVISO' }
    $results.Add((New-CheckResult -Name 'Android SDK' -Status $status -Detail 'ANDROID_HOME/ANDROID_SDK_ROOT não definido (obrigatório para builds Android)'))
}

# SSH key to GitHub (only a warning; depends on the account used)
$sshDir = Join-Path $env:USERPROFILE '.ssh'
if (Test-Path -LiteralPath $sshDir) {
    $keys = Get-ChildItem -LiteralPath $sshDir -File | Where-Object { $_.Name -notmatch '\.pub$|known_hosts|config|agent' }
    if ($keys) {
        $results.Add((New-CheckResult -Name 'Chave SSH' -Status 'OK' -Detail (($keys | Select-Object -ExpandProperty Name) -join ', ')))
    } else {
        $results.Add((New-CheckResult -Name 'Chave SSH' -Status 'AVISO' -Detail 'nenhuma chave privada encontrada em ~/.ssh'))
    }
} else {
    $results.Add((New-CheckResult -Name 'Chave SSH' -Status 'AVISO' -Detail 'diretório ~/.ssh não encontrado'))
}

# Repository connectivity
if ($RepoUrl -and $gitOk) {
    Write-Host "Testando acesso ao repositório $RepoUrl ..."
    try {
        $output = & git ls-remote --heads $RepoUrl 2>&1 | Out-String
        if ($LASTEXITCODE -eq 0) {
            $results.Add((New-CheckResult -Name 'Acesso ao repositório' -Status 'OK' -Detail 'git ls-remote respondeu com sucesso'))
        } else {
            $detail = ($output.Trim() -split "`r?`n" | Select-Object -First 3) -join ' | '
            $results.Add((New-CheckResult -Name 'Acesso ao repositório' -Status 'FALTA' -Detail $detail))
        }
    } catch {
        $results.Add((New-CheckResult -Name 'Acesso ao repositório' -Status 'FALTA' -Detail $_.Exception.Message))
    }
}

Write-Host ''
$results | Format-Table -AutoSize

$failures = @($results | Where-Object { $_.Status -eq 'FALTA' })
$warnings = @($results | Where-Object { $_.Status -eq 'AVISO' })
$okCount = @($results | Where-Object { $_.Status -eq 'OK' }).Count

Write-Host ("Resultado: {0} OK, {1} aviso(s), {2} falha(s)." -f $okCount, $warnings.Count, $failures.Count)

if ($failures.Count -gt 0) {
    Write-Host 'Corrija os itens marcados como FALTA antes de executar builds reais.' -ForegroundColor Red
    exit 1
}

if ($warnings.Count -gt 0) {
    Write-Host 'Avisos não bloqueiam o runner, mas podem impedir builds específicos.' -ForegroundColor Yellow
}

exit 0
