#Requires -Version 5.1
<#
    Unit tests for automatic store submit configuration, secret resolution and
    AAB discovery.
#>

Set-StrictMode -Version 2.0

function New-TestSubmitProfileConfig {
    param([hashtable]$Overrides = @{})

    $values = @{
        provider = 'google_play'
        package_name = 'com.example.app'
        track = 'alpha'
        service_account_file = 'credentials.json'
        artifact_pattern = 'android/app/build/outputs/bundle/release/*.aab'
        release_status = 'completed'
        release_name = ''
    }

    foreach ($key in $Overrides.Keys) {
        $values[$key] = $Overrides[$key]
    }

    return [pscustomobject]$values
}

function New-TestSubmitContext {
    param(
        [string]$Workspace,
        [string]$AppDir,
        [string]$RunnerDir,
        [bool]$AutoSubmit = $true,
        $SubmitConfig = $null
    )

    return @{
        Id = 1
        AutoSubmit = $AutoSubmit
        SubmitConfig = $SubmitConfig
        Workspace = $Workspace
        AppDir = $AppDir
        RunnerDir = $RunnerDir
    }
}

function Test_Submit_Disabled_Returns_Null {
    $context = New-TestSubmitContext -Workspace 'C:\tmp' -AppDir 'C:\tmp' -RunnerDir 'C:\tmp\_runner' `
        -AutoSubmit $false -SubmitConfig (New-TestSubmitProfileConfig)

    Assert-True ($null -eq (Get-SubmitConfiguration -Context $context)) 'submit desabilitado'
}

function Test_Submit_Reads_Valid_Configuration {
    $context = New-TestSubmitContext -Workspace 'C:\tmp' -AppDir 'C:\tmp' -RunnerDir 'C:\tmp\_runner' `
        -SubmitConfig (New-TestSubmitProfileConfig @{ release_name = '1.2.3' })

    $settings = Get-SubmitConfiguration -Context $context

    Assert-Equal 'google_play' $settings.Provider
    Assert-Equal 'com.example.app' $settings.PackageName
    Assert-Equal 'alpha' $settings.Track
    Assert-Equal 'completed' $settings.ReleaseStatus
    Assert-Equal '1.2.3' $settings.ReleaseName
}

function Test_Submit_Rejects_Unsupported_Provider {
    $config = New-TestSubmitProfileConfig @{ provider = 'app_store' }
    $context = New-TestSubmitContext -Workspace 'C:\tmp' -AppDir 'C:\tmp' -RunnerDir 'C:\tmp\_runner' `
        -SubmitConfig $config

    Assert-Throws { Get-SubmitConfiguration -Context $context } 'não suportado'
}

function Test_Submit_Rejects_Invalid_Package {
    $config = New-TestSubmitProfileConfig @{ package_name = 'app' }
    $context = New-TestSubmitContext -Workspace 'C:\tmp' -AppDir 'C:\tmp' -RunnerDir 'C:\tmp\_runner' `
        -SubmitConfig $config

    Assert-Throws { Get-SubmitConfiguration -Context $context } 'Package name'
}

function Test_Submit_Rejects_Unsafe_Service_Account_Path {
    $context = New-TestSubmitContext -Workspace 'C:\tmp' -AppDir 'C:\tmp' -RunnerDir 'C:\tmp\_runner' `
        -SubmitConfig (New-TestSubmitProfileConfig @{ service_account_file = '../credentials.json' })

    Assert-Throws { Get-SubmitConfiguration -Context $context } 'Caminho inválido'
}

function Test_Submit_Resolves_Service_Account_Inside_Workspace {
    $workspace = New-TestTempDir
    try {
        $path = Join-Path $workspace 'credentials.json'
        Set-Content -LiteralPath $path -Value '{}'
        $context = New-TestSubmitContext -Workspace $workspace -AppDir $workspace `
            -RunnerDir (Join-Path $workspace '_runner')

        $resolved = Resolve-SubmitServiceAccount -Context $context -RelativePath 'credentials.json'

        Assert-Equal (Get-Item -LiteralPath $path).FullName $resolved
    } finally {
        Remove-Item -LiteralPath $workspace -Recurse -Force -ErrorAction SilentlyContinue
    }
}

function Test_Submit_Rejects_Missing_Service_Account {
    $workspace = New-TestTempDir
    try {
        $context = New-TestSubmitContext -Workspace $workspace -AppDir $workspace `
            -RunnerDir (Join-Path $workspace '_runner')

        Assert-Throws {
            Resolve-SubmitServiceAccount -Context $context -RelativePath 'credentials.json'
        } 'não encontrada'
    } finally {
        Remove-Item -LiteralPath $workspace -Recurse -Force -ErrorAction SilentlyContinue
    }
}

function Test_Submit_Finds_Aab {
    $workspace = New-TestTempDir
    try {
        $releaseDir = Join-Path $workspace 'android\app\build\outputs\bundle\release'
        New-Item -ItemType Directory -Path $releaseDir -Force | Out-Null
        $aab = Join-Path $releaseDir 'app-release.aab'
        Set-Content -LiteralPath $aab -Value 'aab'

        $context = New-TestSubmitContext -Workspace $workspace -AppDir $workspace `
            -RunnerDir (Join-Path $workspace '_runner')

        $found = Get-SubmitArtifact -Context $context `
            -Pattern 'android/app/build/outputs/bundle/release/*.aab'

        Assert-Equal (Get-Item -LiteralPath $aab).FullName $found.FullName
    } finally {
        Remove-Item -LiteralPath $workspace -Recurse -Force -ErrorAction SilentlyContinue
    }
}

function Test_Submit_Rejects_Missing_Aab {
    $workspace = New-TestTempDir
    try {
        $context = New-TestSubmitContext -Workspace $workspace -AppDir $workspace `
            -RunnerDir (Join-Path $workspace '_runner')

        Assert-Throws {
            Get-SubmitArtifact -Context $context `
                -Pattern 'android/app/build/outputs/bundle/release/*.aab'
        } 'Nenhum AAB'
    } finally {
        Remove-Item -LiteralPath $workspace -Recurse -Force -ErrorAction SilentlyContinue
    }
}
