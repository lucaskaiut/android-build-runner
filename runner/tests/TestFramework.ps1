#Requires -Version 5.1
<#
    Minimal test harness (no external dependencies such as Pester).
    Test functions are named Test_* and may use the Assert-* helpers.
#>

Set-StrictMode -Version 2.0

$script:PassedCount = 0
$script:FailedCount = 0
$script:FailureMessages = New-Object System.Collections.Generic.List[string]

function New-TestTempDir {
    $directory = Join-Path ([System.IO.Path]::GetTempPath()) ('lbp-test-' + [guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $directory -Force | Out-Null

    return $directory
}

function Assert-True {
    param(
        [Parameter(Mandatory = $true)]$Condition,
        [string]$Message = 'condição esperada'
    )

    if (-not $Condition) {
        throw "Assert-True falhou: $Message"
    }
}

function Assert-False {
    param(
        [Parameter(Mandatory = $true)]$Condition,
        [string]$Message = 'condição não esperada'
    )

    if ($Condition) {
        throw "Assert-False falhou: $Message"
    }
}

function Assert-Equal {
    param(
        $Expected,
        $Actual,
        [string]$Message = ''
    )

    if ($Expected -ne $Actual) {
        $suffix = ''
        if ($Message) { $suffix = " ($Message)" }
        throw ("Assert-Equal falhou{0}: esperado [{1}] obtido [{2}]" -f $suffix, $Expected, $Actual)
    }
}

function Assert-Match {
    param(
        [Parameter(Mandatory = $true)][string]$Pattern,
        [Parameter(Mandatory = $true)][string]$Value,
        [string]$Message = ''
    )

    if ($Value -notmatch $Pattern) {
        $suffix = ''
        if ($Message) { $suffix = " ($Message)" }
        throw ("Assert-Match falhou{0}: '{1}' não corresponde a '{2}'" -f $suffix, $Value, $Pattern)
    }
}

function Assert-Throws {
    param(
        [Parameter(Mandatory = $true)][scriptblock]$Body,
        [string]$MessagePattern = ''
    )

    $threw = $false
    $errorMessage = ''

    try {
        & $Body
    } catch {
        $threw = $true
        $errorMessage = $_.Exception.Message
    }

    if (-not $threw) {
        throw 'Assert-Throws falhou: nenhuma exceção foi lançada.'
    }

    if ($MessagePattern -and $errorMessage -notmatch $MessagePattern) {
        throw ("Assert-Throws falhou: mensagem '{0}' não corresponde a '{1}'" -f $errorMessage, $MessagePattern)
    }
}

function Register-TestResult {
    param(
        [Parameter(Mandatory = $true)][string]$Name,
        [Parameter(Mandatory = $true)][bool]$Passed,
        [string]$Message = ''
    )

    if ($Passed) {
        $script:PassedCount++
        Write-Host ("  PASS  {0}" -f $Name) -ForegroundColor Green
    } else {
        $script:FailedCount++
        $script:FailureMessages.Add(("{0}: {1}" -f $Name, $Message))
        Write-Host ("  FAIL  {0}" -f $Name) -ForegroundColor Red
        Write-Host ("        {0}" -f $Message) -ForegroundColor DarkRed
    }
}
