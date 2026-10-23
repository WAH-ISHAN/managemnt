$ErrorActionPreference = 'Stop'
$root = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$testRoot = Join-Path $root ('dist\install-test-' + [guid]::NewGuid().ToString('N'))
if (-not $testRoot.StartsWith($root + [System.IO.Path]::DirectorySeparatorChar, [System.StringComparison]::OrdinalIgnoreCase)) { throw 'Unsafe test directory.' }
New-Item -ItemType Directory -Path $testRoot | Out-Null
try {
    $package = Join-Path $testRoot 'package'
    $installation = Join-Path $testRoot 'installed'
    Expand-Archive -LiteralPath (Join-Path $root 'dist\crab-windows.zip') -DestinationPath $package
    & (Join-Path $package 'Install-Crab.ps1') -Destination $installation -SkipShortcut -SkipScheduledTask
    $version = (Get-Content -LiteralPath (Join-Path $installation 'current.txt') -Raw).Trim()
    $php = Join-Path $installation "versions\$version\runtime\php.exe"
    $ini = Join-Path $installation "versions\$version\runtime\php.ini"
    $env:CRAB_DATA_DIR = Join-Path $installation 'data'
    & $php -c $ini -r "require '$(Join-Path $installation "versions\$version\app\src\bootstrap.php")'; echo extension_loaded('pdo_sqlite') && extension_loaded('mbstring') ? 'PHP and database OK' : 'Missing extensions';"
    if ($LASTEXITCODE -ne 0) { throw 'Installed PHP runtime failed.' }
    if (-not (Test-Path -LiteralPath (Join-Path $installation 'Crab.exe'))) { throw 'Crab.exe is missing.' }
    Write-Host 'PASS: package extraction, installation, PHP runtime, SQLite, Crab.exe'
} finally {
    Remove-Item -LiteralPath $testRoot -Recurse -Force
}
