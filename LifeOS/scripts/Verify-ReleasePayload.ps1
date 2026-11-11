#requires -Version 5.1
[CmdletBinding()]
param(
    [string]$Distribution='',
    [string]$Installer='',
    [string]$EvidencePath='',
    [string]$AiExecutable="$env:LOCALAPPDATA/LifeOS/ai-runtime/opencode-2.2.5/opencode.exe",
    [switch]$Commercial,
    [switch]$WithoutAiRuntime,
    [switch]$RequireSigned,
    [switch]$RequireReleaseIdentity
)
$ErrorActionPreference='Stop'
$project=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
if(!$Distribution){$Distribution=Join-Path $project 'dist'}
if(!$Installer){$Installer=Join-Path $project 'Output/LifeOS-Setup.exe'}
if(!$EvidencePath){$EvidencePath=Join-Path $project ('tests/.tmp/release-payload-'+[DateTime]::UtcNow.ToString('yyyyMMdd-HHmmss')+'.json')}
$distributionPath=[IO.Path]::GetFullPath($Distribution)
$checks=[Collections.Generic.List[string]]::new()
$manifest=[Collections.Generic.List[object]]::new()
function Check([bool]$Valid,[string]$Label){if(!$Valid){throw $Label};$checks.Add($Label)}
function Normalize-ArtifactVersion([string]$Value){
    $parts=$Value.Trim().Split('.')
    if($parts.Count -lt 2 -or $parts.Count -gt 4 -or @($parts | Where-Object {$_ -notmatch '^\d+$'}).Count){throw ('Invalid artifact version: '+$Value)}
    $numbers=@($parts | ForEach-Object {[int]$_})
    while($numbers.Count -lt 4){$numbers+=0}
    return ($numbers -join '.')
}
function Compare-PayloadFile([string]$Relative){
    $source=Join-Path $script:project $Relative
    $target=Join-Path $script:distributionPath $Relative
    Check (Test-Path -LiteralPath $target -PathType Leaf) ('Payload contains '+$Relative)
    $hash=(Get-FileHash -LiteralPath $source -Algorithm SHA256).Hash.ToLowerInvariant()
    Check ($hash -ceq (Get-FileHash -LiteralPath $target -Algorithm SHA256).Hash.ToLowerInvariant()) ('Payload matches source: '+$Relative)
    $manifest.Add([ordered]@{path=$Relative;sha256=$hash})
}
foreach($folder in @('app','public','docs','qa')){
    $sourceFolder=Join-Path $project $folder
    foreach($file in Get-ChildItem -LiteralPath $sourceFolder -File -Recurse){
        $relative=$file.FullName.Substring($project.Length+1).Replace('\','/')
        if($relative -eq 'app/Core/license-config.json'){continue}
        Compare-PayloadFile $relative
    }
    foreach($file in Get-ChildItem -LiteralPath (Join-Path $distributionPath $folder) -File -Recurse){
        $relative=$file.FullName.Substring($distributionPath.Length+1).Replace('\','/')
        Check (Test-Path -LiteralPath (Join-Path $project $relative) -PathType Leaf) ('No obsolete payload file: '+$relative)
    }
}
foreach($name in @('README.md','README-si.md','THIRD-PARTY.md','VERIFICATION.md','PERFORMANCE.md')){Compare-PayloadFile $name}
foreach($name in @('Apply-LifeOSUpdate.ps1','update-backup.php','Set-WindowsStartup.ps1','mobile_companion.py','setup_mobile_tls.py','connectivity_worker.py','authorize_calendar.py','lifeos_cli.py','Track-ActiveWindow.ps1','opencode_worker.py')){
    Compare-PayloadFile ('scripts/'+$name)
}
# The development data directory is deliberately preserved between local builds,
# but Inno excludes it. Audit every file that can actually ship.
$forbiddenNames='(^|/)(\.env(?:\..+)?|credentials?\.json|client_secret[^/]*\.json|[^/]+\.(?:pdb|pfx|p12|pem|key|db|sqlite|sqlite3))$'
foreach($item in Get-ChildItem -LiteralPath $distributionPath -Recurse -Force){
    $relative=$item.FullName.Substring($distributionPath.Length+1).Replace('\','/')
    if($relative -like 'data/*'){continue}
    Check (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -eq 0) ('Shipped payload is not a reparse point: '+$relative)
    Check ($relative -notmatch '(^|/)(\.git|\.hg|\.svn|node_modules|__pycache__)(/|$)') ('Shipped payload excludes development directories: '+$relative)
    if(-not $item.PSIsContainer){Check ($relative -notmatch $forbiddenNames) ('Shipped payload excludes debug, database, credential and private-key artifacts: '+$relative)}
}
$inno=[IO.File]::ReadAllText((Join-Path $project 'lifeos.iss'))
Check ($inno -match '(?im)^Source:\s*"dist\\\*";[^\r\n]*Excludes:\s*"[^"]*data\\\*[^"]*"') 'Installer source excludes preserved development data'
Check ($inno -match '(?im)^Name:\s*"\{app\}\\data";[^\r\n]*uninsneveruninstall') 'Installer preserves local data during uninstall'
$configuration=Get-Content -LiteralPath (Join-Path $distributionPath 'app/Core/license-config.json') -Raw|ConvertFrom-Json
Check ([bool]$configuration.required -eq [bool]$Commercial) 'Payload has the requested commercial activation policy'
if($Commercial){Check ($configuration.server -eq 'https://orean.tech' -and [Convert]::FromBase64String($configuration.publicKey).Length -eq 32) 'Commercial public verification key is present'}
[xml]$launcher=Get-Content -LiteralPath (Join-Path $project 'launcher/LifeOS.csproj')
$version=[string]$launcher.Project.PropertyGroup.Version
$installerVersion=[regex]::Match([IO.File]::ReadAllText((Join-Path $project 'lifeos.iss')),'(?m)^AppVersion=(.+)$').Groups[1].Value.Trim()
Check ($version -ceq $installerVersion) 'Launcher and installer source versions agree'
$settingsVersion=[IO.File]::ReadAllText((Join-Path $project 'app/Modules/Settings/views/index.php'))
Check ($settingsVersion.Contains('LifeOS '+$version)) 'Settings UI version agrees with the launcher'
$pluginTrust=[IO.File]::ReadAllText((Join-Path $project 'app/Modules/Platform/src/PluginTrustService.php'))
Check ($pluginTrust -match ('DEVELOPMENT_VERSION\s*=\s*[''"]'+[regex]::Escape($version)+'[''"]')) 'Plugin compatibility version agrees with the launcher'
$releaseIdentity=$null
$releaseIdentityPath=Join-Path $distributionPath 'lifeos-release.json'
if(Test-Path -LiteralPath $releaseIdentityPath -PathType Leaf){
    $identity=Get-Content -LiteralPath $releaseIdentityPath -Raw|ConvertFrom-Json
    Check ([int]$identity.schemaVersion -eq 1) 'Release identity schema is supported'
    Check ([string]$identity.product -ceq 'LifeOS') 'Release identity product is LifeOS'
    Check ([string]$identity.channel -ceq 'stable') 'Release identity channel is stable'
    Check ([string]$identity.version -ceq $version) 'Release identity version matches source'
    Check ([int]$identity.buildNumber -gt 0) 'Release identity has a positive build number'
    Check ([string]$identity.repository -match '^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$') 'Release identity repository is valid'
    Check ([string]$identity.commit -match '^[a-f0-9]{40}$') 'Release identity commit is a full lowercase Git hash'
    $releaseIdentity=[ordered]@{schemaVersion=[int]$identity.schemaVersion;product=[string]$identity.product;repository=[string]$identity.repository;channel=[string]$identity.channel;version=[string]$identity.version;buildNumber=[int]$identity.buildNumber;commit=[string]$identity.commit}
} elseif($RequireReleaseIdentity){throw 'A stamped lifeos-release.json is required for this release build.'}
$executable=Get-Item -LiteralPath (Join-Path $distributionPath 'LifeOS.exe')
Check ($executable.VersionInfo.ProductVersion.Split('+')[0] -ceq $version) 'Built launcher version matches source'
$launcherSignature=(Get-AuthenticodeSignature -LiteralPath $executable.FullName).Status.ToString()
if($RequireSigned){Check ($launcherSignature -eq 'Valid') 'Built launcher has a valid Authenticode signature'}
$runtimeHash=$null
if(!$WithoutAiRuntime){
    $runtime=Join-Path $distributionPath 'scripts/ai-runtime'
    Check (Test-Path -LiteralPath (Join-Path $runtime 'opencode.exe') -PathType Leaf) 'Approved native AI executable is bundled'
    Check (Test-Path -LiteralPath $AiExecutable -PathType Leaf) 'Selected installed AI executable is available for comparison'
    $runtimeHash=(Get-FileHash -LiteralPath (Join-Path $runtime 'opencode.exe') -Algorithm SHA256).Hash.ToLowerInvariant()
    Check ($runtimeHash -ceq (Get-FileHash -LiteralPath $AiExecutable -Algorithm SHA256).Hash.ToLowerInvariant()) 'Bundled AI executable matches the selected installed runtime'
    $python=Join-Path $runtime 'python/python.exe'
    Check (Test-Path -LiteralPath $python -PathType Leaf) 'Private Python helper is bundled'
    $probe=& $python -I -c "import ssl,sqlite3,json,subprocess;print('OK')"
    Check ($LASTEXITCODE -eq 0 -and $probe -ceq 'OK') 'Bundled helper imports required standard modules in isolation'
    $runtimeMetadata=Get-Content -LiteralPath (Join-Path $runtime 'runtime.json') -Raw|ConvertFrom-Json
    Check (!$runtimeMetadata.model_weights -and $runtimeMetadata.opencode -eq '2.2.5') 'Runtime metadata contains no model weights'
}
$setup=Get-Item -LiteralPath $Installer
$installerHash=(Get-FileHash -LiteralPath $setup.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
$sidecar=Get-Content -LiteralPath ($setup.FullName+'.sha256') -Raw
Check ($sidecar.Split(' ')[0] -ceq $installerHash) 'Installer checksum sidecar matches the artifact'
# Inno's Windows version resource pads AppVersion to four numeric components.
# Compare equivalent numeric versions, while still rejecting any changed component.
$artifactVersion=Normalize-ArtifactVersion $setup.VersionInfo.ProductVersion
$sourceVersion=Normalize-ArtifactVersion $version
Check ($artifactVersion -ceq $sourceVersion) ('Installer artifact version matches source (artifact '+$setup.VersionInfo.ProductVersion+', source '+$version+')')
$installerSignature=(Get-AuthenticodeSignature -LiteralPath $setup.FullName).Status.ToString()
if($RequireSigned){Check ($installerSignature -eq 'Valid') 'Installer has a valid Authenticode signature'}
$result=[ordered]@{success=$true;version=$version;commercial=[bool]$Commercial;signedRelease=[bool]$RequireSigned;releaseIdentity=$releaseIdentity;verifiedUtc=[DateTime]::UtcNow.ToString('o');checks=@($checks.ToArray());sourceFiles=@($manifest.ToArray());launcherSha256=(Get-FileHash -LiteralPath $executable.FullName -Algorithm SHA256).Hash.ToLowerInvariant();launcherSignature=$launcherSignature;aiRuntimeSha256=$runtimeHash;installer=[ordered]@{path=$setup.FullName;bytes=$setup.Length;sha256=$installerHash;signature=$installerSignature};scope='Distribution/source file identity, stamped release identity, sensitive-file and reparse-point gates, selected runtime identity/imports, launcher version, signatures and installer metadata/checksum. Does not extract the installer or establish compiled-source equivalence, clean-machine installation, account approval or physical-device acceptance.'}
$evidenceFile=[IO.Path]::GetFullPath($EvidencePath)
[void][IO.Directory]::CreateDirectory([IO.Path]::GetDirectoryName($evidenceFile))
[IO.File]::WriteAllText($evidenceFile,($result|ConvertTo-Json -Depth 8),[Text.UTF8Encoding]::new($false))
Write-Host ($checks.Count.ToString()+' release payload checks passed. Evidence: '+$evidenceFile)
