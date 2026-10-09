#Requires -Version 5.1
<#
    Unit tests for workspace paths, wrapper generation and run-state detection.
#>

Set-StrictMode -Version 2.0

function New-TestBuildPayload {
    param(
        [string]$ProjectId = '7',
        [string]$WorkingDirectory = 'apps/mobile',
        [string]$Script = 'Write-Output "hello"',
        [string]$CommitSha = ''
    )

    return [pscustomobject]@{
        id = 42
        branch = 'main'
        commit_sha = $CommitSha
        parameters = [pscustomobject]@{ variant = 'preview' }
        working_directory = $WorkingDirectory
        script = $Script
        timeout_seconds = 120
        project = [pscustomobject]@{
            id = $ProjectId
            name = 'App Mobile'
            slug = 'app-mobile'
            repo_url = 'git@github.com:acme/app.git'
        }
        profile = [pscustomobject]@{
            name = 'Preview'
            slug = 'preview'
            timeout_seconds = 120
            env = [pscustomobject]@{ APP_VARIANT = 'preview' }
            artifact_patterns = @('*.apk')
        }
    }
}

function Test_Workspace_Context_Builds_Expected_Paths {
    $root = Join-Path ([System.IO.Path]::GetTempPath()) 'lbp-ws-root'
    $config = @{ workspace_root = $root; git_ssh_command = '' }

    $context = New-BuildContext -Config $config -Build (New-TestBuildPayload)

    Assert-True ($context.Workspace -like "*project-7*build-42") 'workspace isolado por projeto/build'
    Assert-True ($context.AppDir -like '*build-42*apps*mobile') 'diretório de execução relativo'
    Assert-True ($context.ScriptPath -like '*_runner*build.ps1') 'caminho do wrapper'
    Assert-True (Test-PathInsideRoot -Root $root -Candidate $context.Workspace) 'workspace dentro da raiz'
}

function Test_Workspace_Rejects_Traversal_Working_Directory {
    $config = @{ workspace_root = (Join-Path ([System.IO.Path]::GetTempPath()) 'lbp-ws-root'); git_ssh_command = '' }

    Assert-Throws {
        New-BuildContext -Config $config -Build (New-TestBuildPayload -WorkingDirectory '..\..\Windows\System32')
    } 'inválido|fora'
}

function Test_Workspace_Sanitizes_Project_Identifier {
    $root = Join-Path ([System.IO.Path]::GetTempPath()) 'lbp-ws-root'
    $config = @{ workspace_root = $root; git_ssh_command = '' }

    $context = New-BuildContext -Config $config -Build (New-TestBuildPayload -ProjectId '..\..\etc')

    Assert-True (Test-PathInsideRoot -Root $root -Candidate $context.Workspace) 'identificador malicioso é neutralizado'
}

function Test_Workspace_Path_In_Root_Helper {
    $root = Join-Path ([System.IO.Path]::GetTempPath()) 'lbp-root-check'

    Assert-True (Test-PathInsideRoot -Root $root -Candidate (Join-Path $root 'a\b'))
    Assert-True (Test-PathInsideRoot -Root $root -Candidate $root)
    Assert-False (Test-PathInsideRoot -Root $root -Candidate (Join-Path $root '..\fora'))
}

function Test_Workspace_RunState_Liveness_Detection {
    $directory = New-TestTempDir
    $process = $null
    try {
        $process = Start-Process -FilePath 'powershell.exe' `
            -ArgumentList @('-NoProfile', '-Command', 'Start-Sleep -Seconds 30') -PassThru -WindowStyle Hidden

        $runStatePath = Join-Path $directory 'run.json'
        @{
            pid = $process.Id
            started_at = (Get-Date).ToUniversalTime().ToString('o')
            build_id = 42
        } | ConvertTo-Json | Set-Content -LiteralPath $runStatePath

        $context = @{ RunStatePath = $runStatePath }
        Assert-True (Test-BuildRunStateAlive -Context $context) 'processo vivo detectado'

        Stop-Process -Id $process.Id -Force
        Start-Sleep -Milliseconds 1200

        Assert-False (Test-BuildRunStateAlive -Context $context) 'processo morto não é considerado ativo'
    } finally {
        if ($process -and -not $process.HasExited) { Stop-Process -Id $process.Id -Force -ErrorAction SilentlyContinue }
        Remove-Item -LiteralPath $directory -Recurse -Force -ErrorAction SilentlyContinue
    }
}

function Test_Wrapper_Generates_Environment_And_Propagates_Exit_Code {
    $directory = New-TestTempDir
    try {
        $config = @{ workspace_root = $directory; git_ssh_command = '' }

        $payload = New-TestBuildPayload -Script @'
Write-Output "APP_VARIANT=$env:APP_VARIANT BUILD_PARAM_VARIANT=$env:BUILD_PARAM_VARIANT BUILD_ID=$env:BUILD_ID"
[Console]::Error.WriteLine('linha de erro com acentuação')
cmd /c "exit 5"
'@

        $context = New-BuildContext -Config $config -Build $payload
        New-Item -ItemType Directory -Path $context.RunnerDir -Force | Out-Null
        New-Item -ItemType Directory -Path $context.AppDir -Force | Out-Null

        $scriptPath = Write-BuildWrapperScript -Context $context
        Assert-True (Test-Path -LiteralPath $scriptPath) 'wrapper gerado'

        $result = Invoke-ExternalProcess -FilePath 'powershell.exe' `
            -Arguments @('-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $scriptPath) `
            -WorkingDirectory $context.AppDir -TimeoutSeconds 120

        Assert-Equal 5 $result.ExitCode 'código de saída nativo propagado'
        Assert-Match 'APP_VARIANT=preview' $result.Stdout 'variável do perfil disponível'
        Assert-Match 'BUILD_PARAM_VARIANT=preview' $result.Stdout 'parâmetro de build disponível'
        Assert-Match 'BUILD_ID=42' $result.Stdout 'metadados do build disponíveis'
        Assert-Match 'acentuação' $result.Stderr 'stderr capturado com UTF-8'
    } finally {
        Remove-Item -LiteralPath $directory -Recurse -Force -ErrorAction SilentlyContinue
    }
}

function Test_Wrapper_Uncaught_Exception_Fails_The_Script {
    $directory = New-TestTempDir
    try {
        $config = @{ workspace_root = $directory; git_ssh_command = '' }
        $payload = New-TestBuildPayload -Script 'throw "falha simulada"'

        $context = New-BuildContext -Config $config -Build $payload
        New-Item -ItemType Directory -Path $context.RunnerDir -Force | Out-Null
        New-Item -ItemType Directory -Path $context.AppDir -Force | Out-Null

        $scriptPath = Write-BuildWrapperScript -Context $context

        $result = Invoke-ExternalProcess -FilePath 'powershell.exe' `
            -Arguments @('-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $scriptPath) `
            -WorkingDirectory $context.AppDir -TimeoutSeconds 120

        Assert-True ($result.ExitCode -ne 0) 'exceção não tratada retorna código diferente de zero'
        Assert-Match 'falha simulada' $result.Stderr 'mensagem de erro preservada'
    } finally {
        Remove-Item -LiteralPath $directory -Recurse -Force -ErrorAction SilentlyContinue
    }
}
