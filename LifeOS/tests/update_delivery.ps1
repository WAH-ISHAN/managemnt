#requires -Version 5.1
[CmdletBinding()]
param([string]$PhpDirectory='')
$ErrorActionPreference='Stop'
if(-not $PhpDirectory){$PhpDirectory=Join-Path $PSScriptRoot '../dist/php'}
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$project=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$testRoot=Join-Path $PSScriptRoot ('.tmp/update-delivery-'+[guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $testRoot -Force|Out-Null
$checks=0;$utf8=New-Object Text.UTF8Encoding($false)
function Check([bool]$Condition,[string]$Label){if(-not $Condition){throw $Label};$script:checks++}
function Write-Text([string]$Path,[string]$Value){New-Item -ItemType Directory -Path ([IO.Path]::GetDirectoryName($Path)) -Force|Out-Null;[IO.File]::WriteAllText($Path,$Value,$script:utf8)}
$source=Join-Path $testRoot 'source';$output=Join-Path $testRoot 'output'
foreach($entry in @('LifeOS.exe','app/index.php','public/index.php','php/php.exe')){Write-Text (Join-Path $source $entry) ('new payload '+$entry)}
foreach($entry in @('Apply-LifeOSUpdate.ps1','update-backup.php')){New-Item -ItemType Directory -Path (Join-Path $source 'scripts') -Force|Out-Null;Copy-Item -LiteralPath (Join-Path $project ('scripts/'+$entry)) -Destination (Join-Path $source ('scripts/'+$entry))}
Write-Text (Join-Path $source 'data/private.txt') 'PRIVATE MUST NEVER SHIP'
Write-Text (Join-Path $source 'prerequisites/installer.exe') 'exclude offline prerequisite from updates'
& (Join-Path $project 'scripts/New-UpdatePackage.ps1') -Distribution $source -OutputDirectory $output -Version '1.8.0' -BuildNumber 7 -Commit ('a'*40)
$zip=[IO.Compression.ZipFile]::OpenRead((Join-Path $output 'LifeOS-update.zip'))
try{Check (@($zip.Entries|Where-Object {$_.FullName -match '(^|/)(data|prerequisites)(/|$)'}).Count -eq 0) 'Package excludes personal data and prerequisites'}finally{$zip.Dispose()}
function New-Installation([string]$Name) {
    $case=Join-Path $script:testRoot $Name;$install=Join-Path $case 'install';$data=Join-Path $case 'external-data';$updates=Join-Path $data 'updates'
    foreach($entry in @('LifeOS.exe','app/obsolete.php','public/old.txt','php/php.exe')){Write-Text (Join-Path $install $entry) ('old payload '+$entry)}
    Write-Text (Join-Path $install 'lifeos-release.json') '{"schemaVersion":1,"version":"1.7.2","buildNumber":2}'
    Write-Text (Join-Path $data 'uploads/personal.txt') 'my original document'
    Write-Text (Join-Path $data 'settings.txt') 'my preferences'
    New-Item -ItemType Directory -Path $updates -Force|Out-Null
    Copy-Item -LiteralPath (Join-Path $script:output 'LifeOS-update.zip'),(Join-Path $script:output 'lifeos-update.json') -Destination $updates
    Write-Text (Join-Path $updates 'pending.json') '{"ready":true}'
    return @{install=$install;data=$data;updates=$updates;package=(Join-Path $updates 'LifeOS-update.zip');manifest=(Join-Path $updates 'lifeos-update.json');pending=(Join-Path $updates 'pending.json')}
}
function Run-Update($Case) {
    $ErrorActionPreference='Continue'
    $result=& powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $script:project 'scripts/Apply-LifeOSUpdate.ps1') -InstallDirectory $Case.install -DataDirectory $Case.data -PackagePath $Case.package -ManifestPath $Case.manifest -PendingPath $Case.pending 2>&1|Out-String
    return @{exitCode=$LASTEXITCODE;output=$result}
}
function Check-Unchanged($Case,[string]$Label) {
    Check ((Get-Content -LiteralPath (Join-Path $Case.install 'LifeOS.exe') -Raw) -ceq 'old payload LifeOS.exe') ($Label+': executable preserved')
    Check ((Get-Content -LiteralPath (Join-Path $Case.install 'app/obsolete.php') -Raw) -ceq 'old payload app/obsolete.php') ($Label+': application preserved')
    Check ((Get-Content -LiteralPath (Join-Path $Case.data 'uploads/personal.txt') -Raw) -ceq 'my original document') ($Label+': document preserved')
    Check (Test-Path -LiteralPath $Case.pending) ($Label+': pending update retained')
}
function Update-Hash($Case) {
    $manifest=Get-Content -LiteralPath $Case.manifest -Raw|ConvertFrom-Json
    $manifest.package.sha256=(Get-FileHash -LiteralPath $Case.package -Algorithm SHA256).Hash.ToLowerInvariant()
    $manifest.package.size=(Get-Item -LiteralPath $Case.package).Length
    Write-Text $Case.manifest ($manifest|ConvertTo-Json -Depth 5)
}
function Add-ZipEntry($Case,[string]$Name,[string]$Value) {
    $zip=[IO.Compression.ZipFile]::Open($Case.package,[IO.Compression.ZipArchiveMode]::Update)
    try{$entry=$zip.CreateEntry($Name);$writer=New-Object IO.StreamWriter($entry.Open());try{$writer.Write($Value)}finally{$writer.Dispose()}}finally{$zip.Dispose()}
    Update-Hash $Case
}
$case=New-Installation 'success'
# Exercise a real, verified SQLite backup using the same bundled PHP runtime as installations.
if(-not(Test-Path -LiteralPath (Join-Path $PhpDirectory 'php.exe'))){throw 'Prepare dist/php or provide -PhpDirectory to run database-preservation checks.'}
Remove-Item -LiteralPath (Join-Path $case.install 'php/php.exe')
Get-ChildItem -LiteralPath ([IO.Path]::GetFullPath($PhpDirectory)) -Force|Copy-Item -Destination (Join-Path $case.install 'php') -Recurse -Force
New-Item -ItemType Directory -Path (Join-Path $case.install 'scripts') -Force|Out-Null
Copy-Item -LiteralPath (Join-Path $project 'scripts/update-backup.php') -Destination (Join-Path $case.install 'scripts/update-backup.php')
$seed=Join-Path $testRoot 'seed.php'
Write-Text $seed '<?php $db=new SQLite3($argv[1]);$db->exec("CREATE TABLE personal(value TEXT); INSERT INTO personal VALUES (\"keep my record\")");'
& (Join-Path ([IO.Path]::GetFullPath($PhpDirectory)) 'php.exe') -c (Join-Path ([IO.Path]::GetFullPath($PhpDirectory)) 'php.ini') -d ('extension_dir='+(Join-Path ([IO.Path]::GetFullPath($PhpDirectory)) 'ext')) $seed (Join-Path $case.data 'lifeos.db')
if($LASTEXITCODE -ne 0){throw 'Could not create disposable SQLite fixture.'}
$before=(Get-FileHash -LiteralPath (Join-Path $case.data 'lifeos.db')).Hash
$result=Run-Update $case
Check ($result.exitCode -eq 0) ('Valid update succeeds: '+$result.output)
Check ((Get-Content -LiteralPath (Join-Path $case.install 'LifeOS.exe') -Raw) -ceq 'new payload LifeOS.exe') 'New executable installed'
Check (-not(Test-Path -LiteralPath (Join-Path $case.install 'app/obsolete.php'))) 'Obsolete managed code removed'
Check ((Get-FileHash -LiteralPath (Join-Path $case.data 'lifeos.db')).Hash -ceq $before) 'Original database bytes preserved'
Check ((Get-Content -LiteralPath (Join-Path $case.data 'uploads/personal.txt') -Raw) -ceq 'my original document') 'External uploaded document preserved'
Check (@(Get-ChildItem -LiteralPath (Join-Path $case.data 'backups') -Filter '*.db').Count -eq 1) 'Verified SQLite backup saved before update'
Check (-not(Test-Path -LiteralPath $case.pending)) 'Pending update cleared only after success'
Check ((Get-Content -LiteralPath (Join-Path $case.install 'lifeos-release.json') -Raw|ConvertFrom-Json).buildNumber -eq 7) 'Release identity installed'
foreach($name in @('checksum','traversal','data','duplicate','identity','downgrade','path')) {
    $case=New-Installation $name
    switch($name) {
        'checksum' {[IO.File]::AppendAllText($case.package,'tampered')}
        'traversal' {Add-ZipEntry $case '../outside.txt' 'escape'}
        'data' {Add-ZipEntry $case 'data/lifeos.db' 'overwrite'}
        'duplicate' {Add-ZipEntry $case 'APP/index.php' 'case-insensitive duplicate'}
        'identity' {$manifest=Get-Content $case.manifest -Raw|ConvertFrom-Json;$manifest.commit='b'*40;Write-Text $case.manifest ($manifest|ConvertTo-Json -Depth 5)}
        'downgrade' {Write-Text (Join-Path $case.install 'lifeos-release.json') '{"version":"1.9.0","buildNumber":1}'}
        'path' {$case.pending=Join-Path $case.data 'outside-pending.json';Write-Text $case.pending 'must remain'}
    }
    $result=Run-Update $case
    Check ($result.exitCode -ne 0) ("Reject $name package")
    Check-Unchanged $case $name
}
$case=New-Installation 'rollback'
$fileLock=[IO.File]::Open((Join-Path $case.install 'public/old.txt'),[IO.FileMode]::Open,[IO.FileAccess]::Read,[IO.FileShare]::None)
try{$result=Run-Update $case;Check ($result.exitCode -ne 0) 'Locked payload forces a failed application'}finally{$fileLock.Dispose()}
Check-Unchanged $case 'rollback'
Check ((Get-Content -LiteralPath (Join-Path $case.updates 'last-result.json') -Raw|ConvertFrom-Json).rollbackSucceeded -eq $true) 'Earlier moved files roll back successfully'
Write-Host "$checks update delivery checks passed. Disposable evidence: $testRoot"
