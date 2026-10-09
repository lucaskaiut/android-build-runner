#Requires -Version 5.1
<#
    Unit tests for incremental log reading, batching and the pending queue.
#>

Set-StrictMode -Version 2.0

function Test_Log_Reads_Complete_Lines_Incrementally_With_Utf8 {
    $directory = New-TestTempDir
    try {
        $path = Join-Path $directory 'stdout.log'
        $utf8 = New-Object System.Text.UTF8Encoding($false)
        [System.IO.File]::WriteAllText($path, "linha um`nacentuação ção`nparcial", $utf8)

        $position = [long]0
        $partial = ''
        $lines = Read-NewFileLines -Path $path -Position ([ref]$position) -Partial ([ref]$partial)

        Assert-Equal 2 $lines.Count 'duas linhas completas'
        Assert-Equal 'linha um' $lines[0]
        Assert-Equal 'acentuação ção' $lines[1]
        Assert-Equal 'parcial' $partial 'linha incompleta fica no buffer'

        [System.IO.File]::AppendAllText($path, " completa`nmais `n", $utf8)
        $lines = Read-NewFileLines -Path $path -Position ([ref]$position) -Partial ([ref]$partial)

        Assert-Equal 2 $lines.Count 'linhas novas após append'
        Assert-Equal 'parcial completa' $lines[0]
        Assert-Equal 'mais ' $lines[1]
        Assert-Equal '' $partial
    } finally {
        Remove-Item -LiteralPath $directory -Recurse -Force -ErrorAction SilentlyContinue
    }
}

function Test_Log_Missing_File_Returns_Empty {
    $position = [long]0
    $partial = ''
    $lines = Read-NewFileLines -Path (Join-Path $env:TEMP 'nao-existe-lbp.log') -Position ([ref]$position) -Partial ([ref]$partial)
    Assert-Equal 0 @($lines).Count
}

function Test_Log_Batch_Splits_By_Lines_And_Numbers_Globally {
    $config = @{ log_batch_max_lines = 2; log_batch_max_bytes = 100000; max_pending_log_lines = 20 }
    $state = New-LogCollectorState -Config $config

    $lines = New-Object System.Collections.ArrayList
    foreach ($index in 1..5) {
        $lines.Add(@{ Stream = 'stdout'; Message = "linha $index" }) | Out-Null
    }

    $batches = New-LogBatch -LogState $state -Lines $lines -MaxLines 2 -MaxBytes 100000

    Assert-Equal 3 $batches.Count '3 lotes para 5 linhas'
    Assert-Equal 2 $batches[0].Events.Count
    Assert-Equal 1 $batches[2].Events.Count
    Assert-Equal 0 $batches[0].Events[0].line_no
    Assert-Equal 4 $batches[2].Events[0].line_no 'numeração global continua'
    Assert-False ($batches[0].BatchId -eq $batches[1].BatchId) 'ids de lote únicos'
}

function Test_Log_Batch_Respects_Byte_Limit {
    $config = @{ log_batch_max_lines = 500; log_batch_max_bytes = 100000; max_pending_log_lines = 20 }
    $state = New-LogCollectorState -Config $config

    $lines = New-Object System.Collections.ArrayList
    foreach ($index in 1..4) {
        $lines.Add(@{ Stream = 'stdout'; Message = 'x' * 10 }) | Out-Null
    }

    $batches = New-LogBatch -LogState $state -Lines $lines -MaxLines 500 -MaxBytes 25

    Assert-Equal 2 $batches.Count 'limite de bytes divide os lotes'
    Assert-Equal 2 $batches[0].Events.Count
}

function Test_Log_Pending_Queue_Is_Bounded {
    $config = @{ log_batch_max_lines = 2; log_batch_max_bytes = 100000; max_pending_log_lines = 4 }
    $state = New-LogCollectorState -Config $config

    Assert-Equal 2 $state.MaxPendingBatches

    foreach ($index in 1..3) {
        Add-PendingLogBatch -LogState $state -Batch @{ BatchId = "b$index"; Events = @(@{ line_no = $index; stream = 'stdout'; message = 'x' }) }
    }

    Assert-Equal 2 $state.PendingBatches.Count 'fila limitada'
    Assert-Equal 1 $state.DroppedBatches 'lote mais antigo descartado'
    Assert-Equal 'b2' $state.PendingBatches[0].BatchId 'os mais novos permanecem'
}
