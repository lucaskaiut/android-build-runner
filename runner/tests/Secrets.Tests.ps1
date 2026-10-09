#Requires -Version 5.1
<#
    Unit tests for secret file materialization, path validation and log
    redaction.
#>

Set-StrictMode -Version 2.0

function New-SecretTestContext {
    param(
        [string]$Workspace = '',
        [hashtable]$Secrets = @{},
        [object[]]$SecretFiles = @()
    )

    if (-not $Workspace) { $Workspace = New-TestTempDir }

    return @{
        Workspace = $Workspace
        Secrets = $Secrets
        SecretFiles = $SecretFiles
    }
}

function Get-TestSha256 {
    param([byte[]]$Bytes)

    $sha = [System.Security.Cryptography.SHA256]::Create()
    try {
        return [System.BitConverter]::ToString($sha.ComputeHash($Bytes)).Replace('-', '').ToLowerInvariant()
    } finally {
        $sha.Dispose()
    }
}

function Test_Secrets_Path_Validation {
    Assert-True (Test-SecretFilePath -Path '.env') 'arquivo .env aceito'
    Assert-True (Test-SecretFilePath -Path 'credentials.json') 'credentials.json aceito'
    Assert-True (Test-SecretFilePath -Path 'credentials/android/keystore.jks') 'caminho aninhado aceito'

    Assert-False (Test-SecretFilePath -Path ' ') 'vazio rejeitado'
    Assert-False (Test-SecretFilePath -Path '../escape.txt') 'traversal rejeitado'
    Assert-False (Test-SecretFilePath -Path 'a/../../b') 'traversal no meio rejeitado'
    Assert-False (Test-SecretFilePath -Path '/etc/passwd') 'absoluto rejeitado'
    Assert-False (Test-SecretFilePath -Path 'C:\temp\x') 'unidade de disco e backslash rejeitados'
    Assert-False (Test-SecretFilePath -Path '_runner/build.ps1') '_runner rejeitado'
    Assert-False (Test-SecretFilePath -Path '.git/config') '.git rejeitado'
    Assert-False (Test-SecretFilePath -Path 'a//b') 'segmento vazio rejeitado'
}

function Test_Secrets_Writes_Files_And_Verifies_Hash {
    $workspace = New-TestTempDir
    $envContent = "A=1`nB=2`n"
    $envBytes = [System.Text.Encoding]::UTF8.GetBytes($envContent)
    $binary = [byte[]](0, 1, 2, 255, 254)

    $context = New-SecretTestContext -Workspace $workspace -SecretFiles @(
        @{
            Path = '.env'
            ContentBase64 = [Convert]::ToBase64String($envBytes)
            Sha256 = (Get-TestSha256 -Bytes $envBytes)
            Size = $envBytes.Length
        },
        @{
            Path = 'credentials/android/keystore.jks'
            ContentBase64 = [Convert]::ToBase64String($binary)
            Sha256 = (Get-TestSha256 -Bytes $binary)
            Size = $binary.Length
        }
    )

    $written = Write-BuildSecretFiles -Context $context
    Assert-Equal 2 $written 'dois arquivos escritos'

    Assert-True (Test-Path -LiteralPath (Join-Path $workspace '.env')) 'arquivo raiz criado'
    Assert-Equal $envContent ([System.IO.File]::ReadAllText((Join-Path $workspace '.env'))) 'conteúdo preservado'
    Assert-True (Test-Path -LiteralPath (Join-Path $workspace 'credentials\android\keystore.jks')) 'diretório pai criado'

    $writtenBytes = [System.IO.File]::ReadAllBytes((Join-Path $workspace 'credentials\android\keystore.jks'))
    Assert-Equal $binary.Length $writtenBytes.Length 'bytes binários preservados'
}

function Test_Secrets_Writer_Rejects_Invalid_Paths_And_Corruption {
    $workspace = New-TestTempDir

    Assert-Throws {
        Write-BuildSecretFiles -Context (New-SecretTestContext -Workspace $workspace -SecretFiles @(
            @{ Path = '../escape.txt'; ContentBase64 = 'eA=='; Sha256 = ''; Size = 0 }
        ))
    } 'inválido'

    $bytes = [System.Text.Encoding]::UTF8.GetBytes('conteudo')
    Assert-Throws {
        Write-BuildSecretFiles -Context (New-SecretTestContext -Workspace $workspace -SecretFiles @(
            @{ Path = '.env'; ContentBase64 = [Convert]::ToBase64String($bytes); Sha256 = ('a' * 64); Size = $bytes.Length }
        ))
    } 'corrompido'
}

function Test_Secrets_Masking_Protects_Log_Lines {
    $context = New-SecretTestContext -Secrets @{
        LONG_TOKEN = 'super-secret-token-value'
        SHORT = 'abcd'
        TINY = 'xy'
    }

    $masks = Get-SecretMaskValues -Context $context
    Assert-Equal 2 $masks.Count 'apenas valores com tamanho seguro'

    $masked = Protect-SecretText -Text 'linha com super-secret-token-value e abcd no meio' -Masks $masks
    Assert-False ($masked -match 'super-secret-token-value') 'valor longo mascarado'
    Assert-False ($masked -match 'abcd') 'valor curto mascarado'
    Assert-Match '\*\*\*' $masked 'marcador presente'
}

function Test_Secrets_Environment_Lines_Do_Not_Override_Runner_Vars {
    $payload = [pscustomobject]@{
        id = 9
        branch = 'main'
        commit_sha = ''
        parameters = $null
        working_directory = '.'
        script = 'Write-Output "x"'
        timeout_seconds = 60
        secrets = [pscustomobject]@{
            TENANT_BRANDS_TOKEN = 'secret-token-value'
            BUILD_ID = 'attacker'
            APP_DIR = 'attacker'
        }
        project = [pscustomobject]@{
            id = '1'
            name = 'App'
            slug = 'app'
            repo_url = 'git@github.com:acme/app.git'
        }
        profile = [pscustomobject]@{
            name = 'Production'
            slug = 'production'
            timeout_seconds = 60
            env = [pscustomobject]@{}
            artifact_patterns = @()
        }
    }

    $config = @{ workspace_root = (New-TestTempDir); git_ssh_command = '' }
    $context = New-BuildContext -Config $config -Build $payload
    $lines = (New-BuildEnvironmentLines -Context $context) -join "`n"

    Assert-Match "TENANT_BRANDS_TOKEN = 'secret-token-value'" $lines 'segredo injetado'
    Assert-Match "BUILD_ID = '9'" $lines 'BUILD_ID não é sobrescrito'
    Assert-False ($lines -match "BUILD_ID = 'attacker'") 'chave reservada ignorada'
    Assert-False ($lines -match "APP_DIR = 'attacker'") 'APP_DIR ignorado'
}
