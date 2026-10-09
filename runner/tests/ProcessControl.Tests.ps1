#Requires -Version 5.1
<#
    Unit tests for process execution, stream separation and timeouts.
#>

Set-StrictMode -Version 2.0

function Test_Process_Captures_Stdout_Stderr_And_ExitCode {
    $directory = New-TestTempDir
    try {
        $child = Join-Path $directory 'child.ps1'
        $content = @'
Write-Output 'saida ok'
[Console]::Error.WriteLine('erro ok')
exit 7
'@
        $utf8 = New-Object System.Text.UTF8Encoding($true)
        [System.IO.File]::WriteAllText($child, $content, $utf8)

        $result = Invoke-ExternalProcess -FilePath 'powershell.exe' `
            -Arguments @('-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $child) `
            -WorkingDirectory $directory -TimeoutSeconds 60

        Assert-Equal 7 $result.ExitCode
        Assert-Match 'saida ok' $result.Stdout
        Assert-Match 'erro ok' $result.Stderr
    } finally {
        Remove-Item -LiteralPath $directory -Recurse -Force -ErrorAction SilentlyContinue
    }
}

function Test_Process_Timeout_Kills_The_Process {
    $directory = New-TestTempDir
    try {
        $child = Join-Path $directory 'slow.ps1'
        $utf8 = New-Object System.Text.UTF8Encoding($true)
        [System.IO.File]::WriteAllText($child, 'Start-Sleep -Seconds 60', $utf8)

        $stopwatch = [System.Diagnostics.Stopwatch]::StartNew()
        Assert-Throws {
            Invoke-ExternalProcess -FilePath 'powershell.exe' `
                -Arguments @('-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $child) `
                -WorkingDirectory $directory -TimeoutSeconds 3
        } 'Tempo limite'
        $stopwatch.Stop()

        Assert-True ($stopwatch.Elapsed.TotalSeconds -lt 30) 'timeout aplicado sem esperar o processo terminar'
    } finally {
        Remove-Item -LiteralPath $directory -Recurse -Force -ErrorAction SilentlyContinue
    }
}

function Test_Process_Never_Uses_Interactive_Prompts {
    $directory = New-TestTempDir
    try {
        # A command reading from an empty stdin must fail fast, not hang.
        $child = Join-Path $directory 'stdin.ps1'
        $utf8 = New-Object System.Text.UTF8Encoding($true)
        [System.IO.File]::WriteAllText($child, '$null = Read-Host "informe um valor"', $utf8)

        $stopwatch = [System.Diagnostics.Stopwatch]::StartNew()
        $result = Invoke-ExternalProcess -FilePath 'powershell.exe' `
            -Arguments @('-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $child) `
            -WorkingDirectory $directory -TimeoutSeconds 20
        $stopwatch.Stop()

        Assert-Equal 0 $result.ExitCode 'Read-Host com stdin vazio retorna imediatamente'
        Assert-True ($stopwatch.Elapsed.TotalSeconds -lt 15) 'não fica preso aguardando entrada'
    } finally {
        Remove-Item -LiteralPath $directory -Recurse -Force -ErrorAction SilentlyContinue
    }
}
