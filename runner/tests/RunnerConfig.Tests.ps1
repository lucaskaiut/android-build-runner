#Requires -Version 5.1
<#
    Unit tests for configuration loading and validation.
#>

Set-StrictMode -Version 2.0

function Test_Config_Defaults_And_Env_Overrides {
    $directory = New-TestTempDir
    try {
        $config = New-RunnerConfig -ConfigPath (Join-Path $directory 'missing.json')

        Assert-Equal 'http://localhost:8100/api/runner/v1' $config['api_url'] 'api_url default'
        Assert-True ($config['workspace_root'] -like 'C:\*') 'workspace_root default deve ser caminho Windows absoluto'
        Assert-Equal 10 $config['poll_interval_seconds'] 'poll default'

        # Token obrigatório.
        Assert-Throws { Assert-RunnerConfig -Config $config } 'token'

        $env:RUNNER_TOKEN = 'token-de-teste-123456'
        try {
            $config = New-RunnerConfig -ConfigPath (Join-Path $directory 'missing.json')
            Assert-Equal 'token-de-teste-123456' $config['token'] 'env override'

            $null = Assert-RunnerConfig -Config $config

            $summary = Get-RedactedConfigSummary -Config $config
            Assert-False ($summary -match 'token-de-teste-123456') 'token não pode aparecer no resumo'
        } finally {
            Remove-Item Env:\RUNNER_TOKEN -ErrorAction SilentlyContinue
        }
    } finally {
        Remove-Item -LiteralPath $directory -Recurse -Force -ErrorAction SilentlyContinue
    }
}

function Test_Config_Rejects_Invalid_Url_And_Relative_Paths {
    $directory = New-TestTempDir
    try {
        $configFile = Join-Path $directory 'config.json'
        @{
            api_url = 'ftp://exemplo/rede'
            token = 'x'
            name = 'runner-teste'
            workspace_root = 'relative\path'
            log_directory = 'logs'
        } | ConvertTo-Json | Set-Content -LiteralPath $configFile

        $config = New-RunnerConfig -ConfigPath $configFile

        Assert-Throws { Assert-RunnerConfig -Config $config } 'http'
        Assert-Throws { Assert-RunnerConfig -Config $config } 'absoluto'
    } finally {
        Remove-Item -LiteralPath $directory -Recurse -Force -ErrorAction SilentlyContinue
    }
}

function Test_Config_Invalid_Json_Throws {
    $directory = New-TestTempDir
    try {
        $configFile = Join-Path $directory 'config.json'
        Set-Content -LiteralPath $configFile -Value '{ isso não é json'

        Assert-Throws { New-RunnerConfig -ConfigPath $configFile } 'inválido'
    } finally {
        Remove-Item -LiteralPath $directory -Recurse -Force -ErrorAction SilentlyContinue
    }
}

function Test_Config_Merges_File_Values {
    $directory = New-TestTempDir
    try {
        $configFile = Join-Path $directory 'config.json'
        @{
            api_url = 'https://builds.exemplo.com/api/runner/v1'
            token = 'token-abc-123456'
            name = 'PC-BUILD-02'
            workspace_root = 'D:\Builds'
            poll_interval_seconds = 25
            extra_headers = @{ 'CF-Access-Client-Id' = 'id.teste' }
        } | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $configFile

        $config = New-RunnerConfig -ConfigPath $configFile

        Assert-Equal 'https://builds.exemplo.com/api/runner/v1' $config['api_url']
        Assert-Equal 'PC-BUILD-02' $config['name']
        Assert-Equal 25 $config['poll_interval_seconds']
        Assert-Equal 'id.teste' $config['extra_headers']['CF-Access-Client-Id']
        Assert-Equal 30 $config['idle_heartbeat_seconds'] 'defaults preenchem o que faltou'
    } finally {
        Remove-Item -LiteralPath $directory -Recurse -Force -ErrorAction SilentlyContinue
    }
}
