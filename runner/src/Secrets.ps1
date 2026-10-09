#Requires -Version 5.1
<#
    Build secrets: validates relative paths, materializes secret files into
    the workspace and computes mask values for log redaction.
#>

Set-StrictMode -Version 2.0

function Test-SecretFilePath {
    <#
        Secret files are written inside the build workspace only. Absolute
        paths, drive letters, traversal and the git/runner control
        directories are rejected.
    #>
    param([Parameter(Mandatory = $true)][string]$Path)

    if ([string]::IsNullOrWhiteSpace($Path)) { return $false }

    $value = $Path.Trim()

    if ($value.Length -gt 500) { return $false }
    if ($value.Contains('\') -or $value.Contains(':') -or $value.Contains("`0")) { return $false }
    if ($value.StartsWith('/')) { return $false }

    foreach ($segment in ($value -split '/')) {
        if ($segment -eq '' -or $segment -eq '.' -or $segment -eq '..') { return $false }
        if ($segment -ieq '.git' -or $segment -ieq '_runner') { return $false }
        if ($segment -notmatch '^[A-Za-z0-9._\- ]+$') { return $false }
    }

    return $true
}

function Write-BuildSecretFiles {
    <#
        Writes every secret file into the workspace, verifying the integrity
        hash sent by the API. Returns the number of files written.
    #>
    param([Parameter(Mandatory = $true)][hashtable]$Context)

    $written = 0

    foreach ($file in @($Context.SecretFiles)) {
        $relative = [string]$file.Path

        if (-not (Test-SecretFilePath -Path $relative)) {
            throw "Caminho de arquivo secreto inválido: $relative"
        }

        $target = Join-Path ([string]$Context.Workspace) ($relative -replace '/', '\')

        if (-not (Test-PathInsideRoot -Root ([string]$Context.Workspace) -Candidate $target)) {
            throw "Arquivo secreto fora do workspace: $relative"
        }

        try {
            $bytes = [Convert]::FromBase64String([string]$file.ContentBase64)
        } catch {
            throw "Conteúdo do arquivo secreto inválido: $relative"
        }

        if ($file.Sha256) {
            $sha = [System.Security.Cryptography.SHA256]::Create()
            try {
                $hash = [System.BitConverter]::ToString($sha.ComputeHash($bytes)).Replace('-', '').ToLowerInvariant()
            } finally {
                $sha.Dispose()
            }

            if ($hash -ne ([string]$file.Sha256).ToLowerInvariant()) {
                throw "Arquivo secreto corrompido (sha256 divergente): $relative"
            }
        }

        if ([int]$file.Size -gt 0 -and $bytes.Length -ne [int]$file.Size) {
            throw "Arquivo secreto corrompido (tamanho divergente): $relative"
        }

        $parent = Split-Path -Parent $target
        if ($parent -and -not (Test-Path -LiteralPath $parent)) {
            New-Item -ItemType Directory -Path $parent -Force | Out-Null
        }

        [System.IO.File]::WriteAllBytes($target, $bytes)
        $written++
    }

    return $written
}

function Get-SecretMaskValues {
    <#
        Values eligible for log redaction. Extremely short values are ignored
        to avoid mangling regular output.
    #>
    param([Parameter(Mandatory = $true)][hashtable]$Context)

    $masks = New-Object System.Collections.Generic.List[string]

    if ($Context.ContainsKey('Secrets') -and $Context.Secrets) {
        foreach ($key in $Context.Secrets.Keys) {
            $value = [string]$Context.Secrets[$key]
            if ($value.Length -ge 4 -and $value.Length -le 300) {
                $masks.Add($value)
            }
        }
    }

    return , @($masks | Sort-Object -Property Length -Descending)
}

function Protect-SecretText {
    <#
        Replaces every known secret value with *** before a line reaches the
        API log stream.
    #>
    param(
        [string]$Text,
        [object]$Masks = $null
    )

    if ([string]::IsNullOrEmpty($Text) -or $null -eq $Masks) { return $Text }

    $result = $Text

    foreach ($mask in @($Masks)) {
        if (-not [string]::IsNullOrEmpty([string]$mask)) {
            $result = $result.Replace([string]$mask, '***')
        }
    }

    return $result
}
