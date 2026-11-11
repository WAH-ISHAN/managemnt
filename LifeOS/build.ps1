param(
    [string]$PhpDirectory = (Split-Path (Get-Command php.exe -ErrorAction Stop).Source),
    [string]$Dotnet = 'dotnet',
    [string]$InnoCompiler = 'C:/Program Files (x86)/Inno Setup 6/ISCC.exe',
    [string]$WebViewInstaller = "$PSScriptRoot/.tools/MicrosoftEdgeWebView2RuntimeInstallerX64.exe",
    [switch]$Commercial,
    [string]$LicensePublicKey = '',
    [string]$AiExecutable = "$env:LOCALAPPDATA/LifeOS/ai-runtime/opencode-2.2.5/opencode.exe",
    [switch]$WithoutAiRuntime,
    [string]$SignTool = '',
    [ValidatePattern('^[A-Fa-f0-9]{40}$')][string]$SigningCertificateThumbprint = '',
    [ValidatePattern('^https://')][string]$TimestampServer = 'https://timestamp.digicert.com'
)
$ErrorActionPreference = 'Stop'
if($Commercial -and ([string]::IsNullOrWhiteSpace($LicensePublicKey) -or [Convert]::FromBase64String($LicensePublicKey).Length -ne 32)){throw 'Commercial builds require the server Ed25519 public key (-LicensePublicKey). Never pass the private key.'}
if(!$WithoutAiRuntime -and !(Test-Path -LiteralPath $AiExecutable)){throw 'Provide the already-installed approved runtime using -AiExecutable, or explicitly build without bundled AI using -WithoutAiRuntime. This build never downloads AI packages.'}
$projectRoot = [IO.Path]::GetFullPath($PSScriptRoot)
if ($Dotnet -eq 'dotnet' -or [string]::IsNullOrWhiteSpace($Dotnet)) {
    $localDotnet = Join-Path $projectRoot '.tools/dotnet/dotnet.exe'
    if (Test-Path -LiteralPath $localDotnet) { $Dotnet = $localDotnet }
}
if ($InnoCompiler -eq 'C:/Program Files (x86)/Inno Setup 6/ISCC.exe' -and (-not (Test-Path -LiteralPath $InnoCompiler))) {
    $localInno = Join-Path $projectRoot '.tools/Inno/ISCC.exe'
    if (Test-Path -LiteralPath $localInno) { $InnoCompiler = $localInno }
}
$distribution = [IO.Path]::GetFullPath((Join-Path $projectRoot 'dist'))
if ($distribution -ne (Join-Path $projectRoot 'dist')) { throw 'Unsafe distribution path.' }
if (-not (Test-Path -LiteralPath $WebViewInstaller)) { throw 'Provide the offline WebView2 x64 installer with -WebViewInstaller. See README.md.' }
if (-not (Test-Path -LiteralPath $InnoCompiler)) { throw 'Provide the Inno Setup 6 compiler with -InnoCompiler.' }
$phpExe = Join-Path $PhpDirectory 'php.exe'
& $phpExe (Join-Path $projectRoot 'scripts/check-runtime.php')
if ($LASTEXITCODE -ne 0) { throw 'Build requires PHP 8.3 with pdo_sqlite, sqlite3, mbstring, openssl, sodium, zip, fileinfo and curl.' }
$dotnetVersion=(& $Dotnet --version).ToString().Trim()
if ($LASTEXITCODE -ne 0) { throw 'A .NET SDK is required to build the launcher.' }
foreach ($file in @($WebViewInstaller,$InnoCompiler)) {
    if ((Get-AuthenticodeSignature -LiteralPath $file).Status -ne 'Valid') { throw "Invalid publisher signature: $file" }
}
$innoVersionText=(Get-Item -LiteralPath $InnoCompiler).VersionInfo.ProductVersion
if([string]::IsNullOrWhiteSpace($innoVersionText) -or $innoVersionText -eq '0.0.0.0') {
    $uninsCandidate = Join-Path (Split-Path $InnoCompiler) 'unins000.exe'
    if (Test-Path -LiteralPath $uninsCandidate) { $innoVersionText = (Get-Item -LiteralPath $uninsCandidate).VersionInfo.ProductVersion }
}
$innoVersionMatch=[regex]::Match([string]$innoVersionText,'\d+\.\d+\.\d+(?:\.\d+)?')
if(-not $innoVersionMatch.Success -or [version]$innoVersionMatch.Value -lt [version]'6.6.1'){throw 'Inno Setup 6.6.1 or newer is required for reproducible payload timestamps.'}
$signingEnabled = -not [string]::IsNullOrWhiteSpace($SigningCertificateThumbprint)
if ($signingEnabled) {
    if ([string]::IsNullOrWhiteSpace($SignTool)) {
        $signToolCommand = Get-Command signtool.exe -ErrorAction SilentlyContinue
        if ($signToolCommand) { $SignTool = $signToolCommand.Source }
    }
    if (-not (Test-Path -LiteralPath $SignTool -PathType Leaf)) { throw 'Signing requires signtool.exe via -SignTool or PATH.' }
}
function Invoke-LifeOSCodeSign([string]$Path) {
    if (-not $script:signingEnabled) { return }
    & $script:SignTool sign /sha1 $script:SigningCertificateThumbprint /fd SHA256 /tr $script:TimestampServer /td SHA256 $Path
    if ($LASTEXITCODE -ne 0) { throw "Authenticode signing failed: $Path" }
    if ((Get-AuthenticodeSignature -LiteralPath $Path).Status -ne 'Valid') { throw "Authenticode verification failed after signing: $Path" }
}
# A development copy may have real records in dist/data. Rebuild only payload files.
if (Test-Path -LiteralPath $distribution) {
    Get-ChildItem -LiteralPath $distribution -Force | Where-Object { $_.Name -ne 'data' } | ForEach-Object {
        Remove-Item -LiteralPath $_.FullName -Recurse -Force
    }
}
New-Item -ItemType Directory -Path $distribution -Force | Out-Null
& $Dotnet publish (Join-Path $projectRoot 'launcher/LifeOS.csproj') -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -p:IncludeNativeLibrariesForSelfExtract=true -p:DebugType=None -p:DebugSymbols=false -o $distribution
if ($LASTEXITCODE -ne 0) { throw 'Launcher compilation failed.' }
Invoke-LifeOSCodeSign (Join-Path $distribution 'LifeOS.exe')
Copy-Item -LiteralPath (Join-Path $projectRoot 'app'),(Join-Path $projectRoot 'public') -Destination $distribution -Recurse
if($Commercial){
    $licenseConfig=@{server='https://orean.tech';required=$true;publicKey=$LicensePublicKey}|ConvertTo-Json -Compress
    [IO.File]::WriteAllText((Join-Path $distribution 'app/Core/license-config.json'),$licenseConfig,[Text.UTF8Encoding]::new($false))
}
if(!$WithoutAiRuntime){
    & python (Join-Path $projectRoot 'scripts/package_ai_runtime.py') --opencode $AiExecutable --output (Join-Path $distribution 'scripts/ai-runtime')
    if($LASTEXITCODE -ne 0){throw 'Bundled AI runtime verification failed.'}
    Copy-Item -LiteralPath (Join-Path $projectRoot 'docs/OPENCODE-LICENSE.txt') -Destination (Join-Path $distribution 'scripts/ai-runtime/OPENCODE-LICENSE.txt')
}
New-Item -ItemType Directory -Path (Join-Path $distribution 'php/ext'),(Join-Path $distribution 'prerequisites'),(Join-Path $distribution 'scripts') -Force | Out-Null
foreach ($helper in @('Apply-LifeOSUpdate.ps1','update-backup.php','Set-WindowsStartup.ps1','mobile_companion.py','setup_mobile_tls.py','connectivity_worker.py','authorize_calendar.py','lifeos_cli.py','Track-ActiveWindow.ps1','opencode_worker.py')) { Copy-Item -LiteralPath (Join-Path $projectRoot ('scripts/'+$helper)) -Destination (Join-Path $distribution 'scripts') }
$releaseIdentityPath=Join-Path $projectRoot 'lifeos-release.json'
$hasReleaseIdentity=Test-Path -LiteralPath $releaseIdentityPath -PathType Leaf
if ($hasReleaseIdentity) { Copy-Item -LiteralPath $releaseIdentityPath -Destination $distribution }
Copy-Item -LiteralPath $phpExe,(Join-Path $PhpDirectory 'license.txt') -Destination (Join-Path $distribution 'php')
Get-ChildItem -LiteralPath $PhpDirectory -Filter '*.dll' | Copy-Item -Destination (Join-Path $distribution 'php')
foreach ($extension in @('php_pdo_sqlite.dll','php_sqlite3.dll','php_mbstring.dll','php_openssl.dll','php_zip.dll','php_fileinfo.dll','php_curl.dll','php_sodium.dll','php_opcache.dll')) { $extPath = Join-Path $PhpDirectory "ext/$extension"; if (Test-Path -LiteralPath $extPath) { Copy-Item -LiteralPath $extPath -Destination (Join-Path $distribution 'php/ext') } }
if(Test-Path -LiteralPath (Join-Path $projectRoot 'integrations')) { Copy-Item -LiteralPath (Join-Path $projectRoot 'integrations') -Destination $distribution -Recurse }
# App-local Microsoft VC runtime for PHP on clean Windows installations.
foreach ($dll in @('vcruntime140.dll','vcruntime140_1.dll','msvcp140.dll')) {
    $dllPath = Join-Path $env:WINDIR "System32/$dll"
    if (-not (Test-Path -LiteralPath $dllPath)) {
        $fallback = "C:/Program Files/Common Files/microsoft shared/ClickToRun/$dll"
        if (Test-Path -LiteralPath $fallback) { $dllPath = $fallback }
    }
    if (Test-Path -LiteralPath $dllPath) { Copy-Item -LiteralPath $dllPath -Destination (Join-Path $distribution 'php') }
}
Copy-Item -LiteralPath (Join-Path $projectRoot 'php.ini') -Destination (Join-Path $distribution 'php/php.ini')
Copy-Item -LiteralPath $WebViewInstaller -Destination (Join-Path $distribution 'prerequisites/MicrosoftEdgeWebView2RuntimeInstallerX64.exe')
Copy-Item -LiteralPath (Join-Path $projectRoot 'README.md'),(Join-Path $projectRoot 'README-si.md'),(Join-Path $projectRoot 'THIRD-PARTY.md') -Destination $distribution
if(Test-Path -LiteralPath (Join-Path $projectRoot 'docs')) { Copy-Item -LiteralPath (Join-Path $projectRoot 'docs') -Destination $distribution -Recurse }
if(Test-Path -LiteralPath (Join-Path $projectRoot 'qa')) { Copy-Item -LiteralPath (Join-Path $projectRoot 'qa') -Destination $distribution -Recurse }
if (Test-Path -LiteralPath (Join-Path $projectRoot 'VERIFICATION.md')) { Copy-Item -LiteralPath (Join-Path $projectRoot 'VERIFICATION.md') -Destination $distribution }
if (Test-Path -LiteralPath (Join-Path $projectRoot 'PERFORMANCE.md')) { Copy-Item -LiteralPath (Join-Path $projectRoot 'PERFORMANCE.md') -Destination $distribution }
Get-ChildItem -LiteralPath $distribution -Recurse -Directory -Filter '__pycache__' -ErrorAction SilentlyContinue | Remove-Item -Recurse -Force
& $InnoCompiler (Join-Path $projectRoot 'lifeos.iss')
if ($LASTEXITCODE -ne 0) { throw 'Installer compilation failed.' }
$setupPath=Join-Path $projectRoot 'Output/LifeOS-Setup.exe'
Invoke-LifeOSCodeSign $setupPath
$setupHash=(Get-FileHash -LiteralPath $setupPath -Algorithm SHA256).Hash.ToLowerInvariant()
[IO.File]::WriteAllText($setupPath+'.sha256',$setupHash+'  LifeOS-Setup.exe'+[Environment]::NewLine,[Text.Encoding]::ASCII)
$launcherPath=Join-Path $distribution 'LifeOS.exe'
$webViewSignature=Get-AuthenticodeSignature -LiteralPath $WebViewInstaller
$innoSignature=Get-AuthenticodeSignature -LiteralPath $InnoCompiler
$buildInputs=[ordered]@{
    schemaVersion=1
    generatedUtc=[DateTime]::UtcNow.ToString('o')
    modes=[ordered]@{commercial=[bool]$Commercial;withoutAiRuntime=[bool]$WithoutAiRuntime;signedRelease=[bool]$signingEnabled;releaseIdentityStamped=[bool]$hasReleaseIdentity}
    environment=[ordered]@{windows=[Environment]::OSVersion.VersionString;powerShell=$PSVersionTable.PSVersion.ToString()}
    tools=[ordered]@{
        dotnet=[ordered]@{version=$dotnetVersion}
        php=[ordered]@{version=(Get-Item -LiteralPath $phpExe).VersionInfo.ProductVersion;sha256=(Get-FileHash -LiteralPath $phpExe -Algorithm SHA256).Hash.ToLowerInvariant()}
        innoSetup=[ordered]@{version=$innoVersionMatch.Value;sha256=(Get-FileHash -LiteralPath $InnoCompiler -Algorithm SHA256).Hash.ToLowerInvariant();signature=$innoSignature.Status.ToString();signerThumbprint=$innoSignature.SignerCertificate.Thumbprint}
        signTool=$(if($signingEnabled){$signToolItem=Get-Item -LiteralPath $SignTool;[ordered]@{version=$signToolItem.VersionInfo.ProductVersion;sha256=(Get-FileHash -LiteralPath $SignTool -Algorithm SHA256).Hash.ToLowerInvariant()}}else{$null})
    }
    prerequisites=[ordered]@{webView2=[ordered]@{bytes=(Get-Item -LiteralPath $WebViewInstaller).Length;sha256=(Get-FileHash -LiteralPath $WebViewInstaller -Algorithm SHA256).Hash.ToLowerInvariant();signature=$webViewSignature.Status.ToString();signerThumbprint=$webViewSignature.SignerCertificate.Thumbprint};aiRuntimeSha256=$(if($WithoutAiRuntime){$null}else{(Get-FileHash -LiteralPath $AiExecutable -Algorithm SHA256).Hash.ToLowerInvariant()})}
    artifacts=[ordered]@{launcher=[ordered]@{version=(Get-Item -LiteralPath $launcherPath).VersionInfo.ProductVersion;sha256=(Get-FileHash -LiteralPath $launcherPath -Algorithm SHA256).Hash.ToLowerInvariant()};installer=[ordered]@{version=(Get-Item -LiteralPath $setupPath).VersionInfo.ProductVersion;bytes=(Get-Item -LiteralPath $setupPath).Length;sha256=$setupHash}}
}
[IO.File]::WriteAllText((Join-Path $projectRoot 'Output/LifeOS-build-inputs.json'),($buildInputs|ConvertTo-Json -Depth 8),[Text.UTF8Encoding]::new($false))
& (Join-Path $projectRoot 'scripts/Verify-ReleasePayload.ps1') -Distribution $distribution -Installer $setupPath -EvidencePath (Join-Path $projectRoot 'Output/LifeOS-release-verification.json') -AiExecutable $AiExecutable -Commercial:$Commercial -WithoutAiRuntime:$WithoutAiRuntime -RequireSigned:$signingEnabled -RequireReleaseIdentity:$hasReleaseIdentity
Write-Host "Built $(Join-Path $projectRoot 'Output/LifeOS-Setup.exe')"
