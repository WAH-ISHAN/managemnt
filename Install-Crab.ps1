param([string]$Destination = (Join-Path $env:LOCALAPPDATA 'Crab'), [switch]$SkipShortcut, [switch]$SkipScheduledTask)
$ErrorActionPreference = 'Stop'
$source = $PSScriptRoot
$release = Get-Content -LiteralPath (Join-Path $source 'crab-release.json') -Raw | ConvertFrom-Json
if ($release.repository -notmatch '^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$') { throw 'This package has no GitHub repository. Download a Crab release built by GitHub Actions.' }
if ($release.version -notmatch '^v[0-9]+-[a-f0-9]{7}$') { throw 'Invalid release version.' }
$destination = [System.IO.Path]::GetFullPath($Destination)
$versionDir = Join-Path (Join-Path $destination 'versions') $release.version
New-Item -ItemType Directory -Path $versionDir -Force | Out-Null
New-Item -ItemType Directory -Path (Join-Path $destination 'data') -Force | Out-Null
Copy-Item -LiteralPath (Join-Path $source 'app') -Destination $versionDir -Recurse -Force
Copy-Item -LiteralPath (Join-Path $source 'runtime') -Destination $versionDir -Recurse -Force
Copy-Item -LiteralPath (Join-Path $source 'crab-release.json') -Destination $versionDir -Force
Copy-Item -LiteralPath (Join-Path $source 'Start-Crab.ps1') -Destination $destination -Force
Copy-Item -LiteralPath (Join-Path $source 'Update-Crab.ps1') -Destination $destination -Force
Copy-Item -LiteralPath (Join-Path $source 'Crab.exe') -Destination $destination -Force
@{ repository = $release.repository } | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $destination 'config.json') -Encoding UTF8
Set-Content -LiteralPath (Join-Path $destination 'current.txt') -Value $release.version -Encoding ASCII

if (-not $SkipShortcut) {
    $startMenu = Join-Path $env:APPDATA 'Microsoft\Windows\Start Menu\Programs'
    $shortcut = (New-Object -ComObject WScript.Shell).CreateShortcut((Join-Path $startMenu 'Crab.lnk'))
    $shortcut.TargetPath = Join-Path $destination 'Crab.exe'
    $shortcut.WorkingDirectory = $destination
    $shortcut.Save()
}

if (-not $SkipScheduledTask) {
    $updateCommand = 'powershell.exe -NoProfile -ExecutionPolicy Bypass -File "' + (Join-Path $destination 'Update-Crab.ps1') + '"'
    & schtasks.exe /Create /TN 'Crab Update' /SC HOURLY /MO 1 /TR $updateCommand /F | Out-Null
    if ($LASTEXITCODE -ne 0) { Write-Warning 'Hourly update task could not be registered. Crab still checks for updates when it starts.' }
}
Write-Host "Crab installed at $destination. Open Crab from the Start menu."
