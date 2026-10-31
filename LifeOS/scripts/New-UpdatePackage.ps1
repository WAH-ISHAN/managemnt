#requires -Version 5.1
[CmdletBinding()]
param(
    [string]$Distribution = '',
    [string]$OutputDirectory = '',
    [Parameter(Mandatory=$true)][ValidatePattern('^\d+\.\d+\.\d+$')][string]$Version,
    [Parameter(Mandatory=$true)][ValidateRange(1,2147483647)][int]$BuildNumber,
    [Parameter(Mandatory=$true)][ValidatePattern('^[a-f0-9]{40}$')][string]$Commit,
    [ValidatePattern('^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$')][string]$Repository = 'WAH-ISHAN/managemnt'
)
$ErrorActionPreference='Stop'
if(-not $Distribution){$Distribution=Join-Path $PSScriptRoot '../dist'}
if(-not $OutputDirectory){$OutputDirectory=Join-Path $PSScriptRoot '../Output'}
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$distributionPath=[IO.Path]::GetFullPath($Distribution)
$outputPath=[IO.Path]::GetFullPath($OutputDirectory)
New-Item -ItemType Directory -Path $outputPath -Force | Out-Null
$metadata=[ordered]@{schemaVersion=1;product='LifeOS';repository=$Repository;channel='stable';version=$Version;buildNumber=$BuildNumber;commit=$Commit}
$utf8=New-Object Text.UTF8Encoding($false)
[IO.File]::WriteAllText((Join-Path $distributionPath 'lifeos-release.json'),($metadata|ConvertTo-Json -Compress),$utf8)
foreach($required in @('LifeOS.exe','app','public','php/php.exe','scripts/Apply-LifeOSUpdate.ps1','scripts/update-backup.php')) {
    if(-not(Test-Path -LiteralPath (Join-Path $distributionPath $required))){throw "Missing release payload: $required"}
}
$package=Join-Path $outputPath 'LifeOS-update.zip'
if(Test-Path -LiteralPath $package){Remove-Item -LiteralPath $package -Force}
$allowed=@('LifeOS.exe','app','public','php','scripts','integrations','qa','README.md','README-si.md','THIRD-PARTY.md','VERIFICATION.md','PERFORMANCE.md','lifeos-release.json')
$archive=[IO.Compression.ZipFile]::Open($package,[IO.Compression.ZipArchiveMode]::Create)
try {
    foreach($rootName in $allowed) {
        $itemPath=Join-Path $distributionPath $rootName
        if(-not(Test-Path -LiteralPath $itemPath)){continue}
        $rootItem=Get-Item -LiteralPath $itemPath -Force
        $files=if($rootItem.PSIsContainer){@(Get-ChildItem -LiteralPath $itemPath -Recurse -File -Force)}else{@($rootItem)}
        foreach($file in $files) {
            if(($file.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0){throw 'Release payload cannot contain reparse points.'}
            $relative=$file.FullName.Substring($distributionPath.TrimEnd('\','/').Length+1).Replace('\','/')
            if($relative -match '(^|/)(\.git|node_modules|data|\.env)(/|$)' -or $relative.EndsWith('.pdb')){continue}
            [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive,$file.FullName,$relative,[IO.Compression.CompressionLevel]::Optimal)|Out-Null
        }
    }
} finally {$archive.Dispose()}
$tag="lifeos-$Version-build.$BuildNumber"
$base="https://github.com/$Repository/releases/download/$tag"
$manifest=[ordered]@{};foreach($key in $metadata.Keys){$manifest[$key]=$metadata[$key]}
$manifest.publishedAt=[DateTime]::UtcNow.ToString('o')
$manifest.package=[ordered]@{url="$base/LifeOS-update.zip";sha256=(Get-FileHash -LiteralPath $package -Algorithm SHA256).Hash.ToLowerInvariant();size=(Get-Item -LiteralPath $package).Length}
$setup=Join-Path $outputPath 'LifeOS-Setup.exe'
if(Test-Path -LiteralPath $setup){$manifest.installer=[ordered]@{url="$base/LifeOS-Setup.exe";sha256=(Get-FileHash -LiteralPath $setup -Algorithm SHA256).Hash.ToLowerInvariant();size=(Get-Item -LiteralPath $setup).Length}}
[IO.File]::WriteAllText((Join-Path $outputPath 'lifeos-update.json'),($manifest|ConvertTo-Json -Depth 5),$utf8)
Write-Host "Created $package and lifeos-update.json ($tag). Personal data and WebView profiles are excluded."
