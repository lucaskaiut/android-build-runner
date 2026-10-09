#Requires -Version 5.1
<#
    Unit tests for artifact discovery, pattern safety and hashing.
#>

Set-StrictMode -Version 2.0

function Test_Artifacts_Pattern_Safety_Validation {
    Assert-True (Test-SafeArtifactPattern 'app-release.apk')
    Assert-True (Test-SafeArtifactPattern 'android/app/build/outputs/apk/release/*.apk')
    Assert-True (-not (Test-SafeArtifactPattern '..\segredo.txt'))
    Assert-True (-not (Test-SafeArtifactPattern 'C:\Windows\System32\*.dll'))
    Assert-True (-not (Test-SafeArtifactPattern '\\\\servidor\share\*.apk'))
    Assert-True (-not (Test-SafeArtifactPattern '/etc/passwd'))
    Assert-True (-not (Test-SafeArtifactPattern ''))
}

function Test_Artifacts_Finds_Files_Matching_Globs {
    $directory = New-TestTempDir
    try {
        New-Item -ItemType Directory -Path (Join-Path $directory 'android\release') -Force | Out-Null
        Set-Content -LiteralPath (Join-Path $directory 'app-release.apk') -Value 'apk'
        Set-Content -LiteralPath (Join-Path $directory 'notas.txt') -Value 'txt'
        Set-Content -LiteralPath (Join-Path $directory 'android\release\app.aab') -Value 'aab'

        $candidates = Get-ArtifactCandidates -BaseDirectory $directory `
            -Patterns @('app-release.apk', 'android/release/*.aab', '*.txt')

        Assert-Equal 3 @($candidates).Count 'todos os padrões resolvidos'

        $duplicates = Get-ArtifactCandidates -BaseDirectory $directory -Patterns @('*.apk', 'app-release.apk')
        Assert-Equal 1 @($duplicates).Count 'resultados deduplicados'

        $hostile = Get-ArtifactCandidates -BaseDirectory $directory -Patterns @('..\*.apk', 'C:\Windows\*.exe')
        Assert-Equal 0 @($hostile).Count 'padrões hostis ignorados'
    } finally {
        Remove-Item -LiteralPath $directory -Recurse -Force -ErrorAction SilentlyContinue
    }
}

function Test_Artifacts_Relative_Path_Computation {
    $root = Join-Path ([System.IO.Path]::GetTempPath()) 'lbp-artifacts-root'
    $file = New-Object System.IO.FileInfo((Join-Path $root 'android\release\app.apk'))

    Assert-Equal 'android/release/app.apk' (Get-RelativeArtifactPath -AppDir $root -File $file)

    $outside = New-Object System.IO.FileInfo((Join-Path ([System.IO.Path]::GetTempPath()) 'other\app.apk'))
    Assert-Equal 'app.apk' (Get-RelativeArtifactPath -AppDir $root -File $outside)
}

function Test_Artifacts_Sha256_Matches_Dotnet {
    $directory = New-TestTempDir
    try {
        $path = Join-Path $directory 'conteudo.bin'
        $bytes = New-Object byte[] 4096
        (New-Object Random(42)).NextBytes($bytes)
        [System.IO.File]::WriteAllBytes($path, $bytes)

        $hasher = [System.Security.Cryptography.SHA256]::Create()
        try {
            $expected = [System.BitConverter]::ToString($hasher.ComputeHash($bytes)).Replace('-', '').ToLowerInvariant()
        } finally {
            $hasher.Dispose()
        }

        Assert-Equal $expected (Get-FileSha256 -Path $path)
    } finally {
        Remove-Item -LiteralPath $directory -Recurse -Force -ErrorAction SilentlyContinue
    }
}
