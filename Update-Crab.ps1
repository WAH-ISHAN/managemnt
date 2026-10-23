param([string]$ApiBase = 'https://api.github.com/repos')
$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot
$lock = $null
$stage = $null
try {
    $lockPath = Join-Path $root 'update.lock'
    $lock = [System.IO.File]::Open($lockPath, [System.IO.FileMode]::OpenOrCreate, [System.IO.FileAccess]::ReadWrite, [System.IO.FileShare]::None)
    $config = Get-Content -LiteralPath (Join-Path $root 'config.json') -Raw | ConvertFrom-Json
    $repository = [string]$config.repository
    if ($repository -notmatch '^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$') { throw 'Invalid repository configuration.' }
    $headers = @{ 'User-Agent' = 'Crab-Updater'; 'Accept' = 'application/vnd.github+json' }
    $release = Invoke-RestMethod -Uri "$ApiBase/$repository/releases/latest" -Headers $headers -TimeoutSec 30
    $version = [string]$release.tag_name
    if ($version -notmatch '^v([0-9]+)-[a-f0-9]{7}$') { throw 'Unexpected release version.' }
    $newBuild = [int64]$Matches[1]
    $current = (Get-Content -LiteralPath (Join-Path $root 'current.txt') -Raw).Trim()
    $currentBuild = 0L
    if ($current -match '^v([0-9]+)-[a-f0-9]{7}$') { $currentBuild = [int64]$Matches[1] }
    if ($newBuild -le $currentBuild) { return }

    $asset = @($release.assets | Where-Object { $_.name -eq 'crab-windows.zip' }) | Select-Object -First 1
    if ($null -eq $asset -or [string]$asset.digest -notmatch '^sha256:([a-fA-F0-9]{64})$') { throw 'Release asset or SHA-256 digest is missing.' }
    $expectedHash = $Matches[1].ToLowerInvariant()
    $stage = Join-Path $root ('.update-' + [guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $stage | Out-Null
    $archive = Join-Path $stage 'crab-windows.zip'
    Invoke-WebRequest -Uri ([string]$asset.browser_download_url) -OutFile $archive -TimeoutSec 120
    $actualHash = (Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($actualHash -ne $expectedHash) { throw 'Release checksum verification failed.' }
    if ((Get-Item -LiteralPath $archive).Length -ne [int64]$asset.size) { throw 'Release asset size mismatch.' }

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $zip = [System.IO.Compression.ZipFile]::OpenRead($archive)
    try {
        foreach ($entry in $zip.Entries) {
            $name = $entry.FullName.Replace('\', '/')
            if ($name.StartsWith('/') -or $name -match '(^|/)\.\.(/|$)' -or $name -match '^[A-Za-z]:') { throw 'Unsafe path in release archive.' }
        }
    } finally { $zip.Dispose() }
    $extract = Join-Path $stage 'package'
    Expand-Archive -LiteralPath $archive -DestinationPath $extract
    $metadata = Get-Content -LiteralPath (Join-Path $extract 'crab-release.json') -Raw | ConvertFrom-Json
    if ($metadata.version -ne $version -or $metadata.repository -ne $repository -or [int64]$metadata.buildNumber -ne $newBuild) { throw 'Release metadata does not match GitHub.' }
    if (-not (Test-Path -LiteralPath (Join-Path $extract 'app\public\index.php')) -or -not (Test-Path -LiteralPath (Join-Path $extract 'runtime\php.exe'))) { throw 'Release package is incomplete.' }

    $versions = Join-Path $root 'versions'
    New-Item -ItemType Directory -Path $versions -Force | Out-Null
    $target = Join-Path $versions $version
    if (-not (Test-Path -LiteralPath $target)) { Move-Item -LiteralPath $extract -Destination $target }
    $pointer = Join-Path $root 'current.txt'
    $temporaryPointer = Join-Path $root ('current-' + [guid]::NewGuid().ToString('N') + '.txt')
    $backupPointer = Join-Path $root ('current-backup-' + [guid]::NewGuid().ToString('N') + '.txt')
    [System.IO.File]::WriteAllText($temporaryPointer, $version, [System.Text.Encoding]::ASCII)
    [System.IO.File]::Replace($temporaryPointer, $pointer, $backupPointer)
    Remove-Item -LiteralPath $backupPointer -Force
} catch [System.IO.IOException] {
    # Another updater already holds the lock, or a file is temporarily busy.
    Add-Content -LiteralPath (Join-Path $root 'update.log') -Value "$(Get-Date -Format o) $($_.Exception.Message)"
} catch {
    Add-Content -LiteralPath (Join-Path $root 'update.log') -Value "$(Get-Date -Format o) $($_.Exception.Message)"
} finally {
    if ($lock) { $lock.Dispose() }
    if ($stage -and (Test-Path -LiteralPath $stage)) { Remove-Item -LiteralPath $stage -Recurse -Force }
}
