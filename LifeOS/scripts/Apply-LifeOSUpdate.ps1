#requires -Version 5.1
[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$InstallDirectory,
    [Parameter(Mandatory=$true)][string]$PackagePath,
    [Parameter(Mandatory=$true)][string]$ManifestPath,
    [string]$DataDirectory='',
    [ValidateRange(0,2147483647)][int]$ParentProcessId=0,
    [ValidateRange(0,2147483647)][int]$PhpProcessId=0,
    [string]$PendingPath='',
    [switch]$Restart
)
$ErrorActionPreference='Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$install=[IO.Path]::GetFullPath($InstallDirectory).TrimEnd('\','/')
if(-not $DataDirectory){$DataDirectory=Join-Path $install 'data'}
$updateData=[IO.Path]::GetFullPath($DataDirectory).TrimEnd('\','/')
$updates=Join-Path $updateData 'updates'
$utf8=New-Object Text.UTF8Encoding($false)
$stage=$null;$lock=$null;$applied=$false;$mutationStarted=$false;$rollbackOkay=$true
$movedOld=New-Object Collections.Generic.List[string]
$movedNew=New-Object Collections.Generic.List[string]
$allowed=@('LifeOS.exe','app','public','php','scripts','integrations','qa','README.md','README-si.md','THIRD-PARTY.md','VERIFICATION.md','PERFORMANCE.md','lifeos-release.json')
function Assert-Within([string]$Path,[string]$Root) {
    $full=[IO.Path]::GetFullPath($Path)
    if(-not $full.StartsWith($Root.TrimEnd('\','/')+[IO.Path]::DirectorySeparatorChar,[StringComparison]::OrdinalIgnoreCase)){throw 'Update path is outside its expected directory.'}
    return $full
}
function Assert-NoReparse([string]$Path) {
    if((Test-Path -LiteralPath $Path) -and (((Get-Item -LiteralPath $Path -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0)){throw 'Update paths cannot be reparse points.'}
}
function Wait-UpdaterProcesses {
    $deadline=[DateTime]::UtcNow.AddSeconds(60)
    do {
        $busy=$false
        foreach($processId in @($ParentProcessId,$PhpProcessId)) {
            if($processId -gt 0 -and (Get-Process -Id $processId -ErrorAction SilentlyContinue)){$busy=$true}
        }
        foreach($process in @(Get-Process -Name LifeOS,php -ErrorAction SilentlyContinue)) {
            try {if($process.Path -and $process.Path.StartsWith($install+'\',[StringComparison]::OrdinalIgnoreCase)){$busy=$true}}catch{}
        }
        if(-not $busy){return}
        if([DateTime]::UtcNow -ge $deadline){throw 'LifeOS is still using this installation. Update was not applied.'}
        Start-Sleep -Milliseconds 250
    } while($true)
}
try {
    if(-not(Test-Path -LiteralPath (Join-Path $install 'LifeOS.exe') -PathType Leaf)){throw 'Choose an existing LifeOS installation.'}
    Assert-NoReparse $install
    New-Item -ItemType Directory -Path $updates -Force | Out-Null
    Assert-NoReparse $updateData;Assert-NoReparse $updates
    $package=Assert-Within $PackagePath $updates
    $manifestFile=Assert-Within $ManifestPath $updates
    if($PendingPath){$PendingPath=Assert-Within $PendingPath $updates}
    Assert-NoReparse $package;Assert-NoReparse $manifestFile
    $lock=[IO.File]::Open((Join-Path $updates 'apply.lock'),[IO.FileMode]::OpenOrCreate,[IO.FileAccess]::ReadWrite,[IO.FileShare]::None)
    $manifest=Get-Content -LiteralPath $manifestFile -Raw -Encoding UTF8|ConvertFrom-Json
    if($manifest.schemaVersion -ne 1 -or $manifest.product -cne 'LifeOS' -or $manifest.repository -cne 'WAH-ISHAN/managemnt' -or $manifest.channel -cne 'stable' -or $manifest.version -notmatch '^\d+\.\d+\.\d+$' -or $manifest.buildNumber -isnot [ValueType] -or $manifest.buildNumber -lt 1 -or $manifest.commit -notmatch '^[a-f0-9]{40}$'){throw 'Invalid LifeOS release manifest.'}
    $tag='lifeos-'+$manifest.version+'-build.'+$manifest.buildNumber
    if($manifest.package.url -cne "https://github.com/WAH-ISHAN/managemnt/releases/download/$tag/LifeOS-update.zip" -or $manifest.package.sha256 -notmatch '^[a-f0-9]{64}$' -or $manifest.package.size -lt 1 -or $manifest.package.size -gt 2147483648){throw 'Invalid release package metadata.'}
    if((Get-Item -LiteralPath $package).Length -ne $manifest.package.size -or (Get-FileHash -LiteralPath $package -Algorithm SHA256).Hash.ToLowerInvariant() -cne $manifest.package.sha256){throw 'Update package checksum or size mismatch.'}
    $installedMetadata=Join-Path $install 'lifeos-release.json'
    if(Test-Path -LiteralPath $installedMetadata) {
        $current=Get-Content -LiteralPath $installedMetadata -Raw -Encoding UTF8|ConvertFrom-Json
        if([version]$manifest.version -lt [version]$current.version -or ([version]$manifest.version -eq [version]$current.version -and [long]$manifest.buildNumber -le [long]$current.buildNumber)){throw 'Release is not newer than this installation.'}
    }
    $stage=Assert-Within (Join-Path $install ('.lifeos-update-'+[guid]::NewGuid().ToString('N'))) $install
    $payload=Join-Path $stage 'payload';$rollback=Join-Path $stage 'rollback'
    New-Item -ItemType Directory -Path $payload,$rollback | Out-Null
    $zip=[IO.Compression.ZipFile]::OpenRead($package)
    try {
        if($zip.Entries.Count -gt 30000){throw 'Update contains too many files.'}
        $seen=New-Object 'Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
        [long]$total=0
        foreach($entry in $zip.Entries) {
            $name=$entry.FullName.Replace('\','/')
            if($name -match '(^/|:|[\x00-\x1f]|(^|/)\.\.?(/|$))' -or $name -match '(^|/)[^/]*[. ](/|$)' -or -not $seen.Add($name.TrimEnd('/'))){throw 'Unsafe or duplicate update archive path.'}
            $rootName=$name.Split('/')[0]
            if($rootName -cnotin $allowed){throw 'Update archive contains an unmanaged path (including personal data).'}
            if((($entry.ExternalAttributes -shr 16) -band 0xF000) -eq 0xA000 -or ($entry.ExternalAttributes -band 0x400) -ne 0){throw 'Update archive links are not allowed.'}
            $total+=$entry.Length
            if($total -gt 2147483648){throw 'Update expands beyond its size limit.'}
            $target=Assert-Within (Join-Path $payload $name.Replace('/','\')) $payload
            if($name.EndsWith('/')){New-Item -ItemType Directory -Path $target -Force|Out-Null;continue}
            New-Item -ItemType Directory -Path ([IO.Path]::GetDirectoryName($target)) -Force|Out-Null
            [IO.Compression.ZipFileExtensions]::ExtractToFile($entry,$target,$false)
        }
    } finally {$zip.Dispose()}
    foreach($required in @('LifeOS.exe','app','public','php/php.exe','scripts/Apply-LifeOSUpdate.ps1','scripts/update-backup.php','lifeos-release.json')){if(-not(Test-Path -LiteralPath (Join-Path $payload $required))){throw "Incomplete update: $required"}}
    $embedded=Get-Content -LiteralPath (Join-Path $payload 'lifeos-release.json') -Raw -Encoding UTF8|ConvertFrom-Json
    foreach($key in @('schemaVersion','product','repository','channel','version','buildNumber','commit')){if($embedded.$key -cne $manifest.$key){throw 'Embedded release identity does not match the manifest.'}}
    foreach($rootName in $allowed){Assert-NoReparse (Join-Path $install $rootName)}
    Wait-UpdaterProcesses
    $database=Join-Path $updateData 'lifeos.db'
    if(Test-Path -LiteralPath $database) {
        $backups=Join-Path $updateData 'backups';New-Item -ItemType Directory -Path $backups -Force|Out-Null
        $backup=Join-Path $backups ('lifeos-before-update-'+(Get-Date -Format 'yyyyMMdd-HHmmss')+'-'+[guid]::NewGuid().ToString('N')+'.db')
        & (Join-Path $install 'php/php.exe') -c (Join-Path $install 'php/php.ini') -d ('extension_dir='+(Join-Path $install 'php/ext')) (Join-Path $install 'scripts/update-backup.php') $database $backup
        if($LASTEXITCODE -ne 0){throw 'Verified database backup failed. Update was not applied.'}
    }
    $mutationStarted=$true
    foreach($rootName in $allowed) {
        $old=Join-Path $install $rootName;$new=Join-Path $payload $rootName
        if(Test-Path -LiteralPath $old){Move-Item -LiteralPath $old -Destination (Join-Path $rollback $rootName);$movedOld.Add($rootName)}
        if(Test-Path -LiteralPath $new){Move-Item -LiteralPath $new -Destination $old;$movedNew.Add($rootName)}
    }
    $applied=$true
    if($PendingPath -and (Test-Path -LiteralPath $PendingPath)){Remove-Item -LiteralPath $PendingPath -Force}
    [IO.File]::WriteAllText((Join-Path $updates 'last-result.json'),(@{success=$true;version=$manifest.version;buildNumber=$manifest.buildNumber;finishedAt=[DateTime]::UtcNow.ToString('o')}|ConvertTo-Json),$utf8)
} catch {
    $failure=$_.Exception.Message
    if($mutationStarted -and -not $applied) {
        try {
            foreach($rootName in $movedNew){$target=Assert-Within (Join-Path $install $rootName) $install;Remove-Item -LiteralPath $target -Recurse -Force}
            foreach($rootName in $movedOld){Move-Item -LiteralPath (Join-Path $rollback $rootName) -Destination (Join-Path $install $rootName)}
        }catch{$rollbackOkay=$false;$failure+=' File rollback needs attention: '+$_.Exception.Message}
    }
    if(Test-Path -LiteralPath $updates){[IO.File]::WriteAllText((Join-Path $updates 'last-result.json'),(@{success=$false;error=$failure;rollbackSucceeded=$rollbackOkay;finishedAt=[DateTime]::UtcNow.ToString('o')}|ConvertTo-Json),$utf8)}
    Write-Error $failure
} finally {
    if($lock){$lock.Dispose()}
    if($stage -and $applied){$safeStage=Assert-Within $stage $install;Remove-Item -LiteralPath $safeStage -Recurse -Force}
    if($Restart -and $rollbackOkay){$env:LIFEOS_DATA_DIR=$updateData;Start-Process -FilePath (Join-Path $install 'LifeOS.exe') -WorkingDirectory $install -WindowStyle Hidden|Out-Null}
}
