#Requires -Version 5.1
<#
    Common helpers: logging, paths, version, diagnostics, shutdown handling.
    Windows PowerShell 5.1 compatible.
#>

Set-StrictMode -Version 2.0

$script:RunnerLogFile = $null
$script:RunnerLogMutex = New-Object System.Object

function Get-RunnerVersion {
    $versionFile = Join-Path (Split-Path -Parent $PSScriptRoot) 'VERSION'
    if (Test-Path -LiteralPath $versionFile) {
        return (Get-Content -LiteralPath $versionFile -TotalCount 1).Trim()
    }
    return '0.0.0'
}

function Get-RunnerScriptRoot { return $PSScriptRoot }

function Initialize-RunnerLog {
    param([Parameter(Mandatory = $true)][string]$LogDirectory)

    if (-not (Test-Path -LiteralPath $LogDirectory)) {
        New-Item -ItemType Directory -Path $LogDirectory -Force | Out-Null
    }

    $script:RunnerLogFile = Join-Path $LogDirectory 'runner.log'

    if (Test-Path -LiteralPath $script:RunnerLogFile) {
        $info = Get-Item -LiteralPath $script:RunnerLogFile
        if ($info.Length -gt 10MB) {
            $rotated = Join-Path $LogDirectory ('runner-{0:yyyyMMddHHmmss}.log' -f (Get-Date))
            Move-Item -LiteralPath $script:RunnerLogFile -Destination $rotated -Force
        }
    }
}

function Write-RunnerLog {
    param(
        [Parameter(Mandatory = $true)][string]$Message,
        [ValidateSet('INFO', 'WARN', 'ERROR', 'DEBUG')][string]$Level = 'INFO'
    )

    $line = '{0} [{1}] {2}' -f (Get-Date).ToString('yyyy-MM-dd HH:mm:ss'), $Level, $Message

    switch ($Level) {
        'ERROR' { Write-Host $line -ForegroundColor Red }
        'WARN' { Write-Host $line -ForegroundColor Yellow }
        'DEBUG' { if ($env:BUILD_RUNNER_DEBUG -eq '1') { Write-Host $line -ForegroundColor DarkGray } }
        default { Write-Host $line }
    }

    if ($script:RunnerLogFile) {
        try {
            Add-Content -LiteralPath $script:RunnerLogFile -Value $line -Encoding UTF8
        } catch {
            Write-Host "Falha ao escrever no log local: $($_.Exception.Message)" -ForegroundColor Red
        }
    }
}

function Test-PathInsideRoot {
    <#
        Guarantees a resolved path is inside an allowed root (defense in depth
        against tampered configuration values).
    #>
    param(
        [Parameter(Mandatory = $true)][string]$Root,
        [Parameter(Mandatory = $true)][string]$Candidate
    )

    $rootFull = [System.IO.Path]::GetFullPath($Root).TrimEnd('\', '/')
    $candidateFull = [System.IO.Path]::GetFullPath($Candidate)

    if ($candidateFull -eq $rootFull) { return $true }

    return $candidateFull.StartsWith($rootFull + [System.IO.Path]::DirectorySeparatorChar, [System.StringComparison]::OrdinalIgnoreCase)
}

function ConvertTo-SafePathSegment {
    param([Parameter(Mandatory = $true)][string]$Value)

    $safe = $Value -replace '[^A-Za-z0-9._-]', '_'
    $safe = $safe.Trim('.', '_')

    if ([string]::IsNullOrWhiteSpace($safe)) { $safe = 'unknown' }

    return $safe
}

function Get-CommandVersion {
    param(
        [Parameter(Mandatory = $true)][string]$Command,
        [string[]]$Arguments = @('--version'),
        [int]$TimeoutSeconds = 20
    )

    $exe = Get-Command $Command -ErrorAction SilentlyContinue
    if (-not $exe) { return $null }

    try {
        $startInfo = New-Object System.Diagnostics.ProcessStartInfo
        $startInfo.FileName = $exe.Source
        $startInfo.Arguments = ($Arguments -join ' ')
        $startInfo.UseShellExecute = $false
        $startInfo.RedirectStandardOutput = $true
        $startInfo.RedirectStandardError = $true
        $startInfo.CreateNoWindow = $true

        $process = [System.Diagnostics.Process]::Start($startInfo)
        if (-not $process.WaitForExit($TimeoutSeconds * 1000)) {
            try { $process.Kill() } catch { }
            return $null
        }

        $output = ($process.StandardOutput.ReadToEnd() + ' ' + $process.StandardError.ReadToEnd()).Trim()
        return ($output -split "`r?`n" | Select-Object -First 1).Trim()
    } catch {
        return $null
    }
}

function Get-RunnerDiagnostics {
    param([Parameter(Mandatory = $true)][hashtable]$Config)

    $os = $null
    try { $os = (Get-CimInstance Win32_OperatingSystem -ErrorAction Stop).Caption } catch { }

    $git = Get-CommandVersion -Command 'git'
    $node = Get-CommandVersion -Command 'node' -Arguments @('--version')
    $npm = Get-CommandVersion -Command 'npm' -Arguments @('--version')
    $java = Get-CommandVersion -Command 'java' -Arguments @('-version')
    $adb = Get-CommandVersion -Command 'adb' -Arguments @('version')
    $ssh = Get-CommandVersion -Command 'ssh' -Arguments @('-V')

    $androidSdk = $env:ANDROID_HOME
    if (-not $androidSdk) { $androidSdk = $env:ANDROID_SDK_ROOT }

    return @{
        os = $os
        powershell = $PSVersionTable.PSVersion.ToString()
        git = $git
        node = $node
        npm = $npm
        java = $java
        adb = $adb
        ssh = $ssh
        android_sdk = $androidSdk
        workspace_root = $Config.workspace_root
        runner_version = Get-RunnerVersion
    }
}

function Get-CompactDiagnosticsMetadata {
    param([Parameter(Mandatory = $true)][hashtable]$Diagnostics)

    $meta = @{}
    foreach ($key in @('os', 'powershell', 'git', 'node', 'java', 'adb', 'ssh', 'android_sdk', 'workspace_root', 'runner_version')) {
        $value = $Diagnostics[$key]
        if ($null -ne $value -and "$value" -ne '') {
            $meta[$key] = "$value"
            if ($meta[$key].Length -gt 200) { $meta[$key] = $meta[$key].Substring(0, 200) }
        }
    }
    return $meta
}
