#Requires -Version 5.1
<#
    Unit tests for the HTTP client (offline behaviour, retry policy).
#>

Set-StrictMode -Version 2.0

function New-TestApiConfig {
    param([string]$Url = 'http://127.0.0.1:59999/api/runner/v1')

    return @{
        api_url = $Url
        token = 'token-de-teste'
        api_timeout_seconds = 2
        use_system_proxy = $false
        extra_headers = @{}
        api_retry_max = 1
    }
}

function Test_Api_Retry_Delay_Is_Bounded {
    foreach ($attempt in 1..10) {
        $delay = Get-ApiRetryDelaySeconds -Attempt $attempt
        Assert-True ($delay -ge 1) "delay mínimo na tentativa $attempt"
        Assert-True ($delay -le 31) "delay máximo na tentativa $attempt"
    }
}

function Test_Api_Classifies_Retryable_Errors {
    $serverError = New-Object RunnerApiException('servidor', 503, '')
    $clientError = New-Object RunnerApiException('inválido', 422, '')
    $rateLimited = New-Object RunnerApiException('limite', 429, '')

    Assert-True (Test-ApiExceptionRetryable -Exception $serverError)
    Assert-True (Test-ApiExceptionRetryable -Exception $rateLimited)
    Assert-False (Test-ApiExceptionRetryable -Exception $clientError)
}

function Test_Api_Network_Failure_Throws_Wrapped_Exception {
    $api = New-RunnerHttpClient -Config (New-TestApiConfig)

    Assert-Throws {
        Invoke-ApiRequest -Api $api -Method 'POST' -Path 'heartbeat' -Body @{} -MaxAttempts 1
    } 'rede|Falha' 
}

function Test_Api_Upload_Network_Failure_Throws {
    $directory = New-TestTempDir
    try {
        $file = Join-Path $directory 'artefato.apk'
        Set-Content -LiteralPath $file -Value 'conteudo'

        $client = New-RunnerUploadClient -Config (New-TestApiConfig)

        Assert-Throws {
            Invoke-ApiUpload -UploadClient $client -Path 'builds/1/artifacts' -FilePath $file -FileName 'artefato.apk' -MaxAttempts 1
        } 'rede|upload' 
    } finally {
        Remove-Item -LiteralPath $directory -Recurse -Force -ErrorAction SilentlyContinue
    }
}

function Test_Api_Header_Redaction_In_Logs {
    $config = New-TestApiConfig
    $config['token'] = 'segredo-super-secreto'
    $summary = Get-RedactedConfigSummary -Config $config

    Assert-False ($summary -match 'segredo-super-secreto') 'token não vaza no resumo'
}
