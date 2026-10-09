#Requires -Version 5.1
<#
    Artifact discovery, hashing and upload.
#>

Set-StrictMode -Version 2.0

function Test-SafeArtifactPattern {
    param([AllowEmptyString()][string]$Pattern)

    if ([string]::IsNullOrWhiteSpace($Pattern)) { return $false }
    if ($Pattern -match '^[A-Za-z]:') { return $false }
    if ($Pattern -match '^\\\\') { return $false }
    if ($Pattern.StartsWith('/')) { return $false }
    if ($Pattern -match '(^|[\\/])\.\.([\\/]|$)') { return $false }
    if ($Pattern.Contains([char]0)) { return $false }

    return $true
}

function Get-ArtifactCandidates {
    <#
        Resolves artifact glob patterns relative to the application directory.
        Returns a list of FileInfo objects (deduplicated by full path).
    #>
    param(
        [Parameter(Mandatory = $true)][string]$BaseDirectory,
        [string[]]$Patterns = @(),
        [int]$MaxFiles = 20
    )

    $found = New-Object System.Collections.Generic.List[System.IO.FileInfo]
    $seen = @{}

    foreach ($pattern in $Patterns) {
        if (-not (Test-SafeArtifactPattern -Pattern $pattern)) {
            Write-RunnerLog "Padrão de artefato inseguro ignorado: $pattern" 'WARN'
            continue
        }

        $fullPattern = Join-Path $BaseDirectory $pattern

        $items = @()
        try {
            $items = @(Get-Item -Path $fullPattern -ErrorAction SilentlyContinue)
        } catch {
            $items = @()
        }

        foreach ($item in $items) {
            if ($item -is [System.IO.FileInfo] -and -not $seen.ContainsKey($item.FullName)) {
                if (-not (Test-PathInsideRoot -Root $BaseDirectory -Candidate $item.FullName)) {
                    Write-RunnerLog "Artefato fora do diretório da build ignorado: $($item.FullName)" 'WARN'
                    continue
                }

                $seen[$item.FullName] = $true
                $found.Add($item) | Out-Null

                if ($found.Count -ge $MaxFiles) {
                    Write-RunnerLog "Limite de $MaxFiles artefatos atingido; os demais não serão enviados." 'WARN'
                    return $found
                }
            }
        }
    }

    return $found
}

function Get-FileSha256 {
    param([Parameter(Mandatory = $true)][string]$Path)

    return (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()
}

function Get-RelativeArtifactPath {
    <#
        Path of the artifact relative to the application directory, with
        forward slashes. Falls back to the file name when outside the root.
    #>
    param(
        [string]$AppDir,
        [Parameter(Mandatory = $true)][System.IO.FileInfo]$File
    )

    if ($AppDir -and $File.FullName.StartsWith($AppDir, [System.StringComparison]::OrdinalIgnoreCase)) {
        return ($File.FullName.Substring($AppDir.Length).TrimStart('\', '/')) -replace '\\', '/'
    }

    return $File.Name
}

function Send-BuildArtifact {
    param(
        [Parameter(Mandatory = $true)][hashtable]$UploadClient,
        [Parameter(Mandatory = $true)][int]$BuildId,
        [Parameter(Mandatory = $true)][System.IO.FileInfo]$File,
        [string]$AppDir = ''
    )

    $sha256 = Get-FileSha256 -Path $File.FullName
    $path = 'builds/{0}/artifacts' -f $BuildId
    $relativePath = Get-RelativeArtifactPath -AppDir $AppDir -File $File

    return Invoke-ApiUpload -UploadClient $UploadClient -Path $path -FilePath $File.FullName `
        -FileName $File.Name -Sha256 $sha256 -RelativePath $relativePath
}

function Send-BuildArtifacts {
    <#
        Uploads every artifact that matches the profile patterns.
        Returns @{ Uploaded = @(responses); Failed = @(messages) }
    #>
    param(
        [Parameter(Mandatory = $true)][hashtable]$Context,
        [Parameter(Mandatory = $true)][hashtable]$Config,
        [Parameter(Mandatory = $true)][hashtable]$UploadClient,
        [Parameter(Mandatory = $true)]$BuildPayload,
        [Parameter(Mandatory = $true)][scriptblock]$SendSystemLog
    )

    $patterns = @()
    if ($BuildPayload.profile.artifact_patterns) {
        $patterns = @($BuildPayload.profile.artifact_patterns)
    }

    $uploaded = New-Object System.Collections.ArrayList
    $failed = New-Object System.Collections.ArrayList

    if ($patterns.Count -eq 0) {
        return @{ Uploaded = $uploaded; Failed = $failed; Found = 0 }
    }

    $candidates = @(Get-ArtifactCandidates -BaseDirectory $Context.AppDir -Patterns $patterns)

    # The API informs the configured limit; fall back to a safe default.
    $maxMb = 512
    if ($BuildPayload.PSObject.Properties.Name -contains 'artifact_max_megabytes') {
        $maxMb = [int]$BuildPayload.artifact_max_megabytes
    }
    if ($maxMb -le 0) { $maxMb = 512 }

    $maxBytes = [long]($maxMb * 1024 * 1024)

    if ($candidates.Count -eq 0) {
        Write-RunnerLog 'Nenhum arquivo corresponde aos padrões de artefato do perfil.'
        return @{ Uploaded = $uploaded; Failed = $failed; Found = 0 }
    }

    foreach ($file in $candidates) {
        if ($file.Length -gt $maxBytes) {
            $message = "Artefato {0} ({1:N1} MB) excede o limite de {2} MB e não foi enviado." -f `
                $file.Name, ($file.Length / 1MB), $maxMb
            Write-RunnerLog $message 'WARN'
            & $SendSystemLog $message
            $failed.Add($message) | Out-Null
            continue
        }

        try {
            Write-RunnerLog "Enviando artefato $($file.Name) ($('{0:N1}' -f ($file.Length / 1MB)) MB)..."
            $response = Send-BuildArtifact -UploadClient $UploadClient -BuildId $Context.Id -File $file -AppDir $Context.AppDir
            $uploaded.Add($response.artifact) | Out-Null
        } catch {
            $message = "Falha ao enviar o artefato $($file.Name): $($_.Exception.Message)"
            Write-RunnerLog $message 'ERROR'
            & $SendSystemLog $message
            $failed.Add($message) | Out-Null
        }
    }

    return @{ Uploaded = $uploaded; Failed = $failed; Found = $candidates.Count }
}
