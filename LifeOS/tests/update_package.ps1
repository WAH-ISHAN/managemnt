$ErrorActionPreference='Stop'
$project=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
foreach($file in @('build.ps1','scripts/New-UpdatePackage.ps1')) {
 $tokens=$null;$errors=$null
 [void][Management.Automation.Language.Parser]::ParseFile((Join-Path $project $file),[ref]$tokens,[ref]$errors)
 if($errors.Count){throw ($errors|Out-String)}
}
$root=Join-Path $PSScriptRoot ('.tmp/package-smoke-'+[guid]::NewGuid().ToString('N'));$dist=Join-Path $root 'dist';$output=Join-Path $root 'Output'
foreach($file in @('LifeOS.exe','app/index.php','public/index.php','php/php.exe')) {
 $path=Join-Path $dist $file;New-Item -ItemType Directory -Path (Split-Path $path) -Force|Out-Null;Set-Content -LiteralPath $path -Value 'fictional package fixture'
}
New-Item -ItemType Directory -Path (Join-Path $dist 'scripts') -Force|Out-Null
foreach($helper in @('Apply-LifeOSUpdate.ps1','update-backup.php','Set-WindowsStartup.ps1')){Copy-Item -LiteralPath (Join-Path $project ('scripts/'+$helper)) -Destination (Join-Path $dist 'scripts')}
& (Join-Path $project 'scripts/New-UpdatePackage.ps1') -Distribution $dist -OutputDirectory $output -Version '1.8.0' -BuildNumber 17 -Commit ('a'*40)
$archive=[IO.Compression.ZipFile]::OpenRead((Join-Path $output 'LifeOS-update.zip'))
try{foreach($name in @('scripts/Apply-LifeOSUpdate.ps1','scripts/update-backup.php','scripts/Set-WindowsStartup.ps1','lifeos-release.json')){if($name -notin $archive.Entries.FullName){throw "Missing payload entry: $name"}}}finally{$archive.Dispose()}
Remove-Item -LiteralPath (Join-Path $dist 'scripts/update-backup.php')
$rejected=$false
try{& (Join-Path $project 'scripts/New-UpdatePackage.ps1') -Distribution $dist -OutputDirectory (Join-Path $root 'invalid') -Version '1.8.0' -BuildNumber 18 -Commit ('b'*40)}catch{if($_.Exception.Message -like '*Missing release payload*update-backup.php*'){$rejected=$true}else{throw}}
if(!$rejected){throw 'Incomplete package was accepted'}
Write-Host 'PowerShell syntax and package checks passed; all runtime helpers included and incomplete package rejected.'
