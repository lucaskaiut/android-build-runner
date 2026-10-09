#Requires -Version 5.1
<#
    Installs the runner configuration with restricted ACLs.

    The token is written to config.json inside a directory readable only by
    Administrators, SYSTEM and (optionally) the service account. Prefer
    passing -Token via prompt instead of the command line when possible.
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$ApiUrl,
    [string]$Token = '',
    [string]$Name = $env:COMPUTERNAME,
    [string]$WorkspaceRoot = 'C:\LocalBuilds\projects',
    [string]$LogDirectory = 'C:\LocalBuilds\logs',
    [string]$ConfigDirectory = (Join-Path $env:ProgramData 'LocalBuildPlatform\runner'),
    [string]$GitSshCommand = '',
    [string]$ServiceAccount = ''
)

$ErrorActionPreference = 'Stop'

if (-not $Token) {
    $secure = Read-Host -Prompt 'Token do runner (gerado por runner:create na API)' -AsSecureString
    $bstr = [System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
    try {
        $Token = [System.Runtime.InteropServices.Marshal]::PtrToStringAuto($bstr)
    } finally {
        [System.Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
    }
}

if (-not $Token) {
    throw 'O token do runner é obrigatório.'
}

foreach ($directory in @($ConfigDirectory, $WorkspaceRoot, $LogDirectory)) {
    if (-not (Test-Path -LiteralPath $directory)) {
        New-Item -ItemType Directory -Path $directory -Force | Out-Null
        Write-Host "Diretório criado: $directory"
    }
}

$configPath = Join-Path $ConfigDirectory 'config.json'

$templatePath = Join-Path (Split-Path -Parent $PSScriptRoot) 'config\runner.config.example.json'
$config = Get-Content -LiteralPath $templatePath -Raw | ConvertFrom-Json

$config.api_url = $ApiUrl
$config.token = $Token
$config.name = $Name
$config.workspace_root = $WorkspaceRoot
$config.log_directory = $LogDirectory
$config.git_ssh_command = $GitSshCommand

$utf8 = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText($configPath, ($config | ConvertTo-Json -Depth 6), $utf8)

Write-Host "Configuração gravada em: $configPath"

# ---------------------------------------------------------------------------
# ACL: only Administrators, SYSTEM and the service account may read the file.
# Uses SIDs so it works on localized Windows installs.
# ---------------------------------------------------------------------------
$administrators = '*S-1-5-32-544'
$system = '*S-1-5-18'
$currentUser = "$env:USERDOMAIN\$env:USERNAME"

$grantees = @($administrators, $system, $currentUser)
if ($ServiceAccount -and $ServiceAccount -ne $currentUser) { $grantees += $ServiceAccount }

& icacls.exe $ConfigDirectory /inheritance:r | Out-Null
foreach ($grantee in $grantees) {
    & icacls.exe $ConfigDirectory /grant:r "${grantee}:(OI)(CI)F" | Out-Null
}

& icacls.exe $configPath /inheritance:r | Out-Null
foreach ($grantee in $grantees) {
    & icacls.exe $configPath /grant:r "${grantee}:F" | Out-Null
}

Write-Host 'Permissões restritas aplicadas (Administrators, SYSTEM, usuário atual' -NoNewline
if ($ServiceAccount) { Write-Host ", $ServiceAccount)." } else { Write-Host ').' }

Write-Host ''
Write-Host 'Próximos passos:'
Write-Host '  1. Valide as ferramentas: powershell -ExecutionPolicy Bypass -File scripts\validate-prereqs.ps1 -RepoUrl <url-do-repo>'
Write-Host '  2. Teste conexão/api:     powershell -ExecutionPolicy Bypass -File src\BuildRunner.ps1 -Diagnose'
Write-Host '  3. Execute interativo:    powershell -ExecutionPolicy Bypass -File scripts\start-runner.ps1'
Write-Host '  4. Instale como tarefa:   powershell -ExecutionPolicy Bypass -File scripts\install-service.ps1'
