param(
    [string]$PhpDirectory = (Split-Path (Get-Command php.exe).Source),
    [string]$Repository = 'WAH-ISHAN/managemnt'
)
$ErrorActionPreference = 'Stop'
$root = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$distribution = Join-Path $root 'dist'
$stage = Join-Path $distribution ('build-' + [guid]::NewGuid().ToString('N'))
$package = Join-Path $stage 'package'
if (-not $stage.StartsWith($root + [System.IO.Path]::DirectorySeparatorChar, [System.StringComparison]::OrdinalIgnoreCase)) { throw 'Unsafe build directory.' }
New-Item -ItemType Directory -Path (Join-Path $package 'runtime') -Force | Out-Null
try {
    Copy-Item -LiteralPath (Join-Path $root 'app') -Destination $package -Recurse
    Copy-Item -LiteralPath (Join-Path $root 'Start-Crab.ps1'), (Join-Path $root 'Update-Crab.ps1'), (Join-Path $root 'Install-Crab.ps1'), (Join-Path $root 'Crab.exe') -Destination $package
    Copy-Item -Path (Join-Path $PhpDirectory '*') -Destination (Join-Path $package 'runtime') -Recurse -Force
    Copy-Item -LiteralPath (Join-Path $root 'runtime\php.ini') -Destination (Join-Path $package 'runtime\php.ini') -Force
    @{ version = 'v0-0000000'; buildNumber = 0; repository = $Repository } | ConvertTo-Json -Compress | Set-Content -LiteralPath (Join-Path $package 'crab-release.json') -Encoding UTF8
    $zipPath = Join-Path $distribution 'crab-windows.zip'
    if (Test-Path -LiteralPath $zipPath) { Remove-Item -LiteralPath $zipPath -Force }
    Compress-Archive -Path (Join-Path $package '*') -DestinationPath $zipPath -CompressionLevel Optimal
    $compiler = Join-Path $env:WINDIR 'Microsoft.NET\Framework\v4.0.30319\csc.exe'
    & $compiler /nologo /target:winexe /reference:System.Windows.Forms.dll /reference:System.IO.Compression.dll /reference:System.IO.Compression.FileSystem.dll "/resource:$zipPath,crab-package" "/out:$(Join-Path $distribution 'Crab-Setup.exe')" (Join-Path $root 'launcher\CrabSetup.cs')
    if ($LASTEXITCODE -ne 0) { throw 'Setup executable compilation failed.' }
    Write-Host "Built $zipPath and $(Join-Path $distribution 'Crab-Setup.exe')"
} finally {
    Remove-Item -LiteralPath $stage -Recurse -Force
}
