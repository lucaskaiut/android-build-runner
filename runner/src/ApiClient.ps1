#Requires -Version 5.1
<#
    HTTP client for the runner API. Uses System.Net.Http directly so
    multipart uploads, timeouts and retries behave predictably on Windows
    PowerShell 5.1.
#>

Set-StrictMode -Version 2.0

# Windows PowerShell 5.1 does not load System.Net.Http by default.
try {
    Add-Type -AssemblyName System.Net.Http -ErrorAction Stop
} catch {
    throw "Não foi possível carregar System.Net.Http: $($_.Exception.Message)"
}

class RunnerApiException : System.Exception {
    [int]$StatusCode
    [string]$ResponseBody

    RunnerApiException([string]$message, [int]$statusCode, [string]$responseBody) : base($message) {
        $this.StatusCode = $statusCode
        $this.ResponseBody = $responseBody
    }
}

function New-RunnerHttpClient {
    param(
        [Parameter(Mandatory = $true)][hashtable]$Config,
        [int]$TimeoutSeconds = 0
    )

    try {
        [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor [System.Net.SecurityProtocolType]::Tls12
    } catch { }

    $handler = New-Object System.Net.Http.HttpClientHandler
    $handler.UseProxy = [bool]$Config['use_system_proxy']
    $handler.AutomaticDecompression = [System.Net.DecompressionMethods]::GZip -bor [System.Net.DecompressionMethods]::Deflate

    $client = New-Object System.Net.Http.HttpClient($handler)
    $timeout = $TimeoutSeconds
    if ($timeout -le 0) { $timeout = [int]$Config['api_timeout_seconds'] }
    $client.Timeout = [System.TimeSpan]::FromSeconds($timeout)

    $client.DefaultRequestHeaders.Authorization =
        New-Object System.Net.Http.Headers.AuthenticationHeaderValue('Bearer', [string]$Config['token'])
    $client.DefaultRequestHeaders.Accept.Add(
        (New-Object System.Net.Http.Headers.MediaTypeWithQualityHeaderValue('application/json')))

    if ($Config['extra_headers'] -is [hashtable]) {
        foreach ($name in $Config['extra_headers'].Keys) {
            try {
                $client.DefaultRequestHeaders.Add([string]$name, [string]$Config['extra_headers'][$name])
            } catch {
                Write-RunnerLog "Cabeçalho extra inválido ignorado: $name" 'WARN'
            }
        }
    }

    return @{
        Client = $client
        BaseUrl = ([string]$Config['api_url']).TrimEnd('/')
        Config = $Config
    }
}

function New-RunnerUploadClient {
    param(
        [Parameter(Mandatory = $true)][hashtable]$Config,
        [int]$TimeoutSeconds = 3600
    )

    return New-RunnerHttpClient -Config $Config -TimeoutSeconds $TimeoutSeconds
}

function Get-ApiRetryDelaySeconds {
    param([Parameter(Mandatory = $true)][int]$Attempt)

    $base = [Math]::Min(30, [Math]::Pow(2, $Attempt))
    $jitter = (Get-Random -Minimum 0 -Maximum 1000) / 1000.0

    return [Math]::Round($base + $jitter, 2)
}

function Test-ApiExceptionRetryable {
    param([Parameter(Mandatory = $true)][System.Exception]$Exception)

    if ($Exception -is [RunnerApiException]) {
        $status = $Exception.StatusCode
        return ($status -ge 500 -or $status -eq 408 -or $status -eq 429)
    }

    # Network / DNS / timeout errors are retryable.
    return $true
}

function Invoke-ApiRequest {
    param(
        [Parameter(Mandatory = $true)][hashtable]$Api,
        [Parameter(Mandatory = $true)][string]$Method,
        [Parameter(Mandatory = $true)][string]$Path,
        $Body = $null,
        [int]$MaxAttempts = 0,
        [switch]$NoRetry
    )

    if ($NoRetry) { $MaxAttempts = 1 }
    if ($MaxAttempts -le 0) { $MaxAttempts = [int]$Api.Config['api_retry_max'] }
    if ($MaxAttempts -lt 1) { $MaxAttempts = 1 }

    $uri = '{0}/{1}' -f $Api.BaseUrl, ($Path.TrimStart('/'))
    $attempt = 0
    $lastError = $null

    while ($attempt -lt $MaxAttempts) {
        $attempt++
        $request = $null
        try {
            $request = New-Object System.Net.Http.HttpRequestMessage
            $request.Method = New-Object System.Net.Http.HttpMethod($Method)
            $request.RequestUri = [System.Uri]$uri

            if ($null -ne $Body) {
                $json = $Body | ConvertTo-Json -Depth 12 -Compress
                $request.Content = New-Object System.Net.Http.StringContent($json, [System.Text.Encoding]::UTF8, 'application/json')
            }

            $response = $Api.Client.SendAsync($request).GetAwaiter().GetResult()
            $content = $response.Content.ReadAsStringAsync().GetAwaiter().GetResult()
            $status = [int]$response.StatusCode

            if ($status -ge 200 -and $status -le 299) {
                if ([string]::IsNullOrWhiteSpace($content)) { return $null }
                return ($content | ConvertFrom-Json)
            }

            $message = "API respondeu $status em $Method $Path"
            try {
                $parsed = $content | ConvertFrom-Json
                if ($parsed.message) { $message = "$message - $($parsed.message)" }
            } catch { }

            $apiError = New-Object RunnerApiException($message, $status, $content)
            $apiError.Data['path'] = $Path

            if (-not (Test-ApiExceptionRetryable -Exception $apiError) -or $attempt -ge $MaxAttempts) {
                throw $apiError
            }

            $lastError = $apiError
        } catch [RunnerApiException] {
            throw
        } catch {
            $wrapped = New-Object RunnerApiException(
                ("Falha de rede em {0} {1}: {2}" -f $Method, $Path, $_.Exception.Message), 0, '')
            $lastError = $wrapped

            if ($attempt -ge $MaxAttempts) { throw $wrapped }
        } finally {
            if ($request) { $request.Dispose() }
        }

        $delay = Get-ApiRetryDelaySeconds -Attempt $attempt
        Write-RunnerLog ("Tentativa {0}/{1} falhou em {2} {3}: {4}. Nova tentativa em {5}s." -f `
                $attempt, $MaxAttempts, $Method, $Path, $lastError.Message, $delay) 'WARN'
        Start-Sleep -Seconds $delay
    }

    throw $lastError
}

function Invoke-ApiUpload {
    param(
        [Parameter(Mandatory = $true)][hashtable]$UploadClient,
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][string]$FilePath,
        [Parameter(Mandatory = $true)][string]$FileName,
        [string]$Sha256 = '',
        [string]$RelativePath = '',
        [int]$MaxAttempts = 3
    )

    $uri = '{0}/{1}' -f $UploadClient.BaseUrl, ($Path.TrimStart('/'))
    $attempt = 0
    $lastError = $null

    while ($attempt -lt $MaxAttempts) {
        $attempt++
        $fileStream = $null
        $multipart = $null
        try {
            $fileStream = [System.IO.File]::Open($FilePath, [System.IO.FileMode]::Open, [System.IO.FileAccess]::Read, [System.IO.FileShare]::Read)
            $multipart = New-Object System.Net.Http.MultipartFormDataContent
            $fileContent = New-Object System.Net.Http.StreamContent($fileStream)
            $fileContent.Headers.ContentType = [System.Net.Http.Headers.MediaTypeHeaderValue]::Parse('application/octet-stream')
            $multipart.Add($fileContent, 'file', $FileName)

            if ($Sha256) {
                $shaContent = New-Object System.Net.Http.StringContent($Sha256)
                $multipart.Add($shaContent, 'sha256')
            }

            if ($RelativePath) {
                $relativeContent = New-Object System.Net.Http.StringContent($RelativePath)
                $multipart.Add($relativeContent, 'relative_path')
            }

            $response = $UploadClient.Client.PostAsync([System.Uri]$uri, $multipart).GetAwaiter().GetResult()
            $content = $response.Content.ReadAsStringAsync().GetAwaiter().GetResult()
            $status = [int]$response.StatusCode

            if ($status -ge 200 -and $status -le 299) {
                if ([string]::IsNullOrWhiteSpace($content)) { return $null }
                return ($content | ConvertFrom-Json)
            }

            $message = "Upload de artefato falhou com status $status"
            try {
                $parsed = $content | ConvertFrom-Json
                if ($parsed.message) { $message = "$message - $($parsed.message)" }
            } catch { }

            $uploadError = New-Object RunnerApiException($message, $status, $content)

            if (-not (Test-ApiExceptionRetryable -Exception $uploadError) -or $attempt -ge $MaxAttempts) {
                throw $uploadError
            }

            $lastError = $uploadError
        } catch [RunnerApiException] {
            throw
        } catch {
            $lastError = New-Object RunnerApiException(
                ("Falha de rede no upload de {0}: {1}" -f $FileName, $_.Exception.Message), 0, '')

            if ($attempt -ge $MaxAttempts) { throw $lastError }
        } finally {
            if ($multipart) { $multipart.Dispose() }
            if ($fileStream) { $fileStream.Dispose() }
        }

        $delay = Get-ApiRetryDelaySeconds -Attempt $attempt
        Write-RunnerLog ("Upload de {0} falhou ({1}). Nova tentativa em {2}s." -f $FileName, $lastError.Message, $delay) 'WARN'
        Start-Sleep -Seconds $delay
    }

    throw $lastError
}
