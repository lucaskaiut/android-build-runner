#Requires -Version 5.1
<#
    Process execution with separated stdout/stderr capture, timeouts and
    process-tree termination.

    Implemented on System.Diagnostics.Process (not Start-Process) because
    Start-Process -PassThru with redirection does not reliably expose
    ExitCode on Windows PowerShell 5.1. Streams are copied to files
    asynchronously so callers can tail them while the process runs.
#>

Set-StrictMode -Version 2.0

function Format-ProcessArgument {
    param([Parameter(Mandatory = $true)][string]$Argument)

    if ($Argument -match '[\s"]') {
        return '"' + ($Argument -replace '"', '\"') + '"'
    }

    return $Argument
}

function Start-RedirectedProcess {
    <#
        Starts a console application with stdout/stderr streamed to files and
        stdin closed (commands must never wait for interactive input).

        Returns a handle: @{ Process; StdoutTask; StderrTask; StdoutStream; StderrStream }
    #>
    param(
        [Parameter(Mandatory = $true)][string]$FilePath,
        [string[]]$Arguments = @(),
        [Parameter(Mandatory = $true)][string]$WorkingDirectory,
        [Parameter(Mandatory = $true)][string]$StdoutPath,
        [Parameter(Mandatory = $true)][string]$StderrPath
    )

    # .cmd/.bat shims (common for git/node wrappers) need cmd.exe /c.
    if ($FilePath -match '\.(cmd|bat)$') {
        $Arguments = @('/c', $FilePath) + $Arguments
        $FilePath = Join-Path $env:SystemRoot 'System32\cmd.exe'
    }

    $argumentString = (($Arguments | ForEach-Object { Format-ProcessArgument $_ }) -join ' ')

    $startInfo = New-Object System.Diagnostics.ProcessStartInfo
    $startInfo.FileName = $FilePath
    $startInfo.Arguments = $argumentString
    $startInfo.WorkingDirectory = $WorkingDirectory
    $startInfo.UseShellExecute = $false
    $startInfo.RedirectStandardOutput = $true
    $startInfo.RedirectStandardError = $true
    $startInfo.RedirectStandardInput = $true
    $startInfo.CreateNoWindow = $true

    $process = New-Object System.Diagnostics.Process
    $process.StartInfo = $startInfo

    if (-not $process.Start()) {
        throw "Não foi possível iniciar o processo '$FilePath'."
    }

    # Guarantee that anything reading input fails instead of hanging.
    try { $process.StandardInput.Close() } catch { }

    # FileShare.ReadWrite lets the runner tail these files while the child
    # is still writing to them. bufferSize 1 disables FileStream buffering so
    # lines hit the disk immediately and stream in near real time.
    $stdoutStream = New-Object System.IO.FileStream(
        $StdoutPath, [System.IO.FileMode]::Create, [System.IO.FileAccess]::Write, [System.IO.FileShare]::ReadWrite, 1)
    $stderrStream = New-Object System.IO.FileStream(
        $StderrPath, [System.IO.FileMode]::Create, [System.IO.FileAccess]::Write, [System.IO.FileShare]::ReadWrite, 1)

    $stdoutTask = $process.StandardOutput.BaseStream.CopyToAsync($stdoutStream)
    $stderrTask = $process.StandardError.BaseStream.CopyToAsync($stderrStream)

    return @{
        Process = $process
        StdoutTask = $stdoutTask
        StderrTask = $stderrTask
        StdoutStream = $stdoutStream
        StderrStream = $stderrStream
    }
}

function Close-RedirectedProcess {
    <#
        Finalizes stream copies and disposes file handles. Safe to call after
        killing the process or after a normal exit.
    #>
    param(
        [Parameter(Mandatory = $true)][hashtable]$Handle,
        [int]$DrainTimeoutSeconds = 10
    )

    $deadline = (Get-Date).AddSeconds($DrainTimeoutSeconds)
    foreach ($task in @($Handle.StdoutTask, $Handle.StderrTask)) {
        try {
            $remaining = [Math]::Max(0, [int]($deadline - (Get-Date)).TotalMilliseconds)
            $null = $task.Wait($remaining)
        } catch { }
    }

    foreach ($stream in @($Handle.StdoutStream, $Handle.StderrStream)) {
        try { $stream.Dispose() } catch { }
    }
}

function Stop-ProcessTree {
    param(
        [Parameter(Mandatory = $true)][int]$ProcessId,
        [int]$TimeoutSeconds = 15
    )

    try {
        $taskkill = Join-Path $env:SystemRoot 'System32\taskkill.exe'
        if (Test-Path -LiteralPath $taskkill) {
            & $taskkill /PID $ProcessId /T /F 2>$null | Out-Null
        } else {
            Stop-Process -Id $ProcessId -Force -ErrorAction SilentlyContinue
        }
    } catch {
        Write-RunnerLog "Falha ao encerrar árvore de processos ${ProcessId}: $($_.Exception.Message)" 'WARN'
    }

    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    while ((Get-Date) -lt $deadline) {
        $still = Get-Process -Id $ProcessId -ErrorAction SilentlyContinue
        if (-not $still) { return $true }
        Start-Sleep -Milliseconds 250
    }

    return $false
}

function Get-ProcessExitState {
    param([Parameter(Mandatory = $true)][hashtable]$Handle)

    try {
        if ($Handle.Process.HasExited) {
            return @{ Exited = $true; ExitCode = $Handle.Process.ExitCode }
        }
    } catch { }

    return @{ Exited = $false; ExitCode = $null }
}

function Read-CapturedText {
    <#
        Decodes captured process output. Build tooling may emit UTF-8 (node,
        expo) or the Windows OEM code page (git). Strict UTF-8 detection
        keeps both readable.
    #>
    param([Parameter(Mandatory = $true)][string]$Path)

    if (-not (Test-Path -LiteralPath $Path)) { return '' }

    $bytes = [System.IO.File]::ReadAllBytes($Path)
    if ($bytes.Length -eq 0) { return '' }

    $strictUtf8 = New-Object System.Text.UTF8Encoding($false, $true)
    try {
        return $strictUtf8.GetString($bytes)
    } catch {
        $oem = [System.Text.Encoding]::GetEncoding([System.Globalization.CultureInfo]::CurrentCulture.TextInfo.OEMCodePage)
        return $oem.GetString($bytes)
    }
}

function Invoke-ExternalProcess {
    <#
        Runs a console application to completion, capturing stdout and stderr
        separately. Throws on timeout after killing the whole process tree.
    #>
    param(
        [Parameter(Mandatory = $true)][string]$FilePath,
        [string[]]$Arguments = @(),
        [Parameter(Mandatory = $true)][string]$WorkingDirectory,
        [int]$TimeoutSeconds = 900
    )

    $stdoutPath = [System.IO.Path]::GetTempFileName()
    $stderrPath = [System.IO.Path]::GetTempFileName()

    $handle = $null
    try {
        $handle = Start-RedirectedProcess -FilePath $FilePath -Arguments $Arguments `
            -WorkingDirectory $WorkingDirectory -StdoutPath $stdoutPath -StderrPath $stderrPath

        if (-not $handle.Process.WaitForExit($TimeoutSeconds * 1000)) {
            Stop-ProcessTree -ProcessId $handle.Process.Id | Out-Null
            Close-RedirectedProcess -Handle $handle -DrainTimeoutSeconds 5
            throw ("Tempo limite de {0}s excedido ao executar '{1} {2}'." -f $TimeoutSeconds, $FilePath, ($Arguments -join ' '))
        }

        Close-RedirectedProcess -Handle $handle

        $stdout = Read-CapturedText -Path $stdoutPath
        $stderr = Read-CapturedText -Path $stderrPath

        return @{
            ExitCode = $handle.Process.ExitCode
            Stdout = [string]$stdout
            Stderr = [string]$stderr
        }
    } finally {
        if ($handle) { Close-RedirectedProcess -Handle $handle -DrainTimeoutSeconds 2 }
        Remove-Item -LiteralPath $stdoutPath, $stderrPath -Force -ErrorAction SilentlyContinue
    }
}
