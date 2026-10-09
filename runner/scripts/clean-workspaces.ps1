#Requires -Version 5.1
<#
    Removes old build workspaces (retention policy).

    Only removes directories under <workspace_root>\project-*\builds\build-*
    that are older than -Days. Workspaces with a live build process (run.json
    pointing to a running PowerShell) are skipped.
#>

[CmdletBinding(SupportsShouldProcess = $true, ConfirmImpact = 'Medium')]
param(
    [string]$ConfigPath = (Join-Path $env:ProgramData 'LocalBuildPlatform\runner\config.json'),
    [int]$Days = 30,
    [switch]$Force
)

$ErrorActionPreference = 'Stop'

. (Join-Path (Split-Path -Parent $PSScriptRoot) 'src\RunnerCommon.ps1')
. (Join-Path (Split-Path -Parent $PSScriptRoot) 'src\RunnerConfig.ps1')
. (Join-Path (Split-Path -Parent $PSScriptRoot) 'src\Workspace.ps1')

if (-not (Test-Path -LiteralPath $ConfigPath)) {
    throw "Configuração não encontrada em $ConfigPath."
}

$config = New-RunnerConfig -ConfigPath $ConfigPath
$root = [string]$config['workspace_root']

if (-not (Test-Path -LiteralPath $root)) {
    Write-Host "Nada a limpar: $root não existe."
    exit 0
}

$threshold = (Get-Date).AddDays(-$Days)
$removed = 0
$skipped = 0

Get-ChildItem -LiteralPath $root -Directory -Filter 'project-*' | ForEach-Object {
    $buildsDir = Join-Path $_.FullName 'builds'
    if (-not (Test-Path -LiteralPath $buildsDir)) { return }

    Get-ChildItem -LiteralPath $buildsDir -Directory -Filter 'build-*' | ForEach-Object {
        $workspace = $_

        if ($workspace.LastWriteTime -gt $threshold) { return }

        $runStatePath = Join-Path $workspace.FullName '_runner\run.json'
        if (Test-Path -LiteralPath $runStatePath) {
            $context = @{ RunStatePath = $runStatePath }
            if (Test-BuildRunStateAlive -Context $context) {
                Write-Host "Pulando workspace com processo ativo: $($workspace.FullName)" -ForegroundColor Yellow
                $script:skipped++
                return
            }
        }

        if ($PSCmdlet.ShouldProcess($workspace.FullName, 'Remover workspace')) {
            try {
                Remove-Item -LiteralPath $workspace.FullName -Recurse -Force -ErrorAction Stop
                $script:removed++
            } catch {
                Write-Host "Falha ao remover $($workspace.FullName): $($_.Exception.Message)" -ForegroundColor Red
            }
        }
    }
}

Write-Host ("Workspaces removidos: {0}; pulados (ativos): {1}." -f $removed, $skipped)
