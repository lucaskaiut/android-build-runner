#Requires -Version 5.1
<#
    Incremental log collection: tails the redirected stdout/stderr files,
    builds batches and keeps a bounded pending queue for retries.
#>

Set-StrictMode -Version 2.0

function Read-NewFileLines {
    <#
        Reads new UTF-8 lines written since the last read. Returns complete
        lines only; an incomplete trailing line is kept in $Partial.
    #>
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][ref]$Position,
        [Parameter(Mandatory = $true)][ref]$Partial
    )

    if (-not (Test-Path -LiteralPath $Path)) { return @() }

    $text = ''
    try {
        $stream = [System.IO.File]::Open($Path, [System.IO.FileMode]::Open, [System.IO.FileAccess]::Read, [System.IO.FileShare]::ReadWrite)
        try {
            if ($Position.Value -gt $stream.Length) { $Position.Value = 0 }
            $stream.Seek([long]$Position.Value, [System.IO.SeekOrigin]::Begin) | Out-Null
            $reader = New-Object System.IO.StreamReader($stream, (New-Object System.Text.UTF8Encoding($false)))
            $text = $reader.ReadToEnd()
            $Position.Value = $stream.Position
            $reader.Dispose()
        } finally {
            $stream.Dispose()
        }
    } catch {
        Write-RunnerLog "Falha ao ler log incremental ($Path): $($_.Exception.Message)" 'WARN'
        return @()
    }

    if ($text.Length -eq 0) { return @() }

    $combined = ([string]$Partial.Value) + $text
    $parts = $combined -split "`r`n|`n"

    if ($parts.Count -le 1) {
        $Partial.Value = $combined
        return @()
    }

    $Partial.Value = $parts[$parts.Count - 1]
    return @($parts[0..($parts.Count - 2)])
}

function New-LogBatchId {
    return [System.Guid]::NewGuid().ToString()
}

function New-LogBatch {
    <#
        Builds batches respecting the max lines and max bytes limits.
        Line numbers are global per build and live in $LogState.
        Returns an array of hashtables: @{ BatchId; Events }
        Each event: @{ line_no; stream; message }
    #>
    param(
        [Parameter(Mandatory = $true)][hashtable]$LogState,
        [Parameter(Mandatory = $true)][System.Collections.ArrayList]$Lines,
        [Parameter(Mandatory = $true)][int]$MaxLines,
        [Parameter(Mandatory = $true)][int]$MaxBytes
    )

    $batches = @()
    $current = @()
    $currentBytes = 0

    foreach ($line in $Lines) {
        $bytes = [System.Text.Encoding]::UTF8.GetByteCount([string]$line.Message)

        if ($current.Count -gt 0 -and ($current.Count -ge $MaxLines -or ($currentBytes + $bytes) -gt $MaxBytes)) {
            $batches += @{ BatchId = (New-LogBatchId); Events = $current }
            $current = @()
            $currentBytes = 0
        }

        $current += @{
            line_no = [int]$LogState.NextLineNumber
            stream = [string]$line.Stream
            message = [string]$line.Message
        }
        $LogState.NextLineNumber = [int]$LogState.NextLineNumber + 1
        $currentBytes += $bytes

        if ($current.Count -ge $MaxLines) {
            $batches += @{ BatchId = (New-LogBatchId); Events = $current }
            $current = @()
            $currentBytes = 0
        }
    }

    if ($current.Count -gt 0) {
        $batches += @{ BatchId = (New-LogBatchId); Events = $current }
    }

    return $batches
}

function Add-PendingLogBatch {
    param(
        [Parameter(Mandatory = $true)][hashtable]$LogState,
        [Parameter(Mandatory = $true)][hashtable]$Batch
    )

    $LogState.PendingBatches.Add($Batch) | Out-Null

    # Bounded memory: drop the oldest batches if the API stays unreachable.
    while ($LogState.PendingBatches.Count -gt [int]$LogState.MaxPendingBatches) {
        $dropped = $LogState.PendingBatches[0]
        $LogState.PendingBatches.RemoveAt(0)
        $LogState.DroppedBatches++
        Write-RunnerLog ("Backlog de logs excedido: lote com {0} linhas descartado localmente." -f $dropped.Events.Count) 'WARN'
    }
}

function New-LogCollectorState {
    param([Parameter(Mandatory = $true)][hashtable]$Config)

    return @{
        StdoutPosition = [long]0
        StderrPosition = [long]0
        StdoutPartial = ''
        StderrPartial = ''
        NextLineNumber = 0
        PendingBatches = (New-Object System.Collections.ArrayList)
        SystemBuffer = (New-Object System.Collections.ArrayList)
        SecretMasks = @()
        MaxPendingBatches = [Math]::Max(1, [int]([int]$Config['max_pending_log_lines'] / [Math]::Max(1, [int]$Config['log_batch_max_lines'])))
        DroppedBatches = 0
    }
}

function Read-NewBuildLogLines {
    param(
        [Parameter(Mandatory = $true)][hashtable]$LogState,
        [Parameter(Mandatory = $true)][string]$StdoutPath,
        [Parameter(Mandatory = $true)][string]$StderrPath
    )

    $lines = New-Object System.Collections.ArrayList

    $position = [long]$LogState.StdoutPosition
    $partial = [string]$LogState.StdoutPartial
    $stdoutLines = Read-NewFileLines -Path $StdoutPath -Position ([ref]$position) -Partial ([ref]$partial)
    $LogState.StdoutPosition = $position
    $LogState.StdoutPartial = $partial

    foreach ($line in $stdoutLines) {
        $lines.Add(@{ Stream = 'stdout'; Message = (Protect-SecretText -Text $line -Masks $LogState.SecretMasks) }) | Out-Null
    }

    $position = [long]$LogState.StderrPosition
    $partial = [string]$LogState.StderrPartial
    $stderrLines = Read-NewFileLines -Path $StderrPath -Position ([ref]$position) -Partial ([ref]$partial)
    $LogState.StderrPosition = $position
    $LogState.StderrPartial = $partial

    foreach ($line in $stderrLines) {
        $lines.Add(@{ Stream = 'stderr'; Message = (Protect-SecretText -Text $line -Masks $LogState.SecretMasks) }) | Out-Null
    }

    # The comma prevents PowerShell from unrolling a single-element list.
    return , $lines
}

function Add-SystemLogLine {
    <#
        Buffers a platform event sent by the runner (checkout, script start,
        timeout...). It travels through the same ordered log stream.
    #>
    param(
        [Parameter(Mandatory = $true)][hashtable]$LogState,
        [Parameter(Mandatory = $true)][string]$Message
    )

    $LogState.SystemBuffer.Add(@{ Stream = 'system'; Message = $Message }) | Out-Null
}
