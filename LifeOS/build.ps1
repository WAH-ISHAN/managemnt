param(
    [string]$PhpDirectory = (Split-Path (Get-Command php.exe -ErrorAction Stop).Source),
    [string]$Dotnet = 'dotnet',
    [string]$InnoCompiler = 'C:/Program Files (x86)/Inno Setup 6/ISCC.exe',
    [string]$WebViewInstaller = "$PSScriptRoot/.tools/MicrosoftEdgeWebView2RuntimeInstallerX64.exe"
)
$ErrorActionPreference = 'Stop'
$projectRoot = [IO.Path]::GetFullPath($PSScriptRoot)
$distribution = [IO.Path]::GetFullPath((Join-Path $projectRoot 'dist'))
if ($distribution -ne (Join-Path $projectRoot 'dist')) { throw 'Unsafe distribution path.' }
if (-not (Test-Path -LiteralPath $WebViewInstaller)) { throw 'Provide the offline WebView2 x64 installer with -WebViewInstaller. See README.md.' }
if (-not (Test-Path -LiteralPath $InnoCompiler)) { throw 'Provide the Inno Setup 6 compiler with -InnoCompiler.' }
$phpExe = Join-Path $PhpDirectory 'php.exe'
& $phpExe (Join-Path $projectRoot 'scripts/check-runtime.php')
if ($LASTEXITCODE -ne 0) { throw 'Build requires PHP 8.3 with pdo_sqlite, sqlite3, mbstring, openssl, zip and fileinfo.' }
& $Dotnet --version
if ($LASTEXITCODE -ne 0) { throw 'A .NET SDK is required to build the launcher.' }
foreach ($file in @($WebViewInstaller,$InnoCompiler)) {
    if ((Get-AuthenticodeSignature -LiteralPath $file).Status -ne 'Valid') { throw "Invalid publisher signature: $file" }
}
# A development copy may have real records in dist/data. Rebuild only payload files.
if (Test-Path -LiteralPath $distribution) {
    Get-ChildItem -LiteralPath $distribution -Force | Where-Object { $_.Name -ne 'data' } | ForEach-Object {
        Remove-Item -LiteralPath $_.FullName -Recurse -Force
    }
}
New-Item -ItemType Directory -Path $distribution -Force | Out-Null
& $Dotnet publish (Join-Path $projectRoot 'launcher/LifeOS.csproj') -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -p:IncludeNativeLibrariesForSelfExtract=true -o $distribution
if ($LASTEXITCODE -ne 0) { throw 'Launcher compilation failed.' }
Copy-Item -LiteralPath (Join-Path $projectRoot 'app'),(Join-Path $projectRoot 'public') -Destination $distribution -Recurse
New-Item -ItemType Directory -Path (Join-Path $distribution 'php/ext'),(Join-Path $distribution 'prerequisites'),(Join-Path $distribution 'scripts') -Force | Out-Null
foreach ($helper in @('Apply-LifeOSUpdate.ps1','update-backup.php','Set-WindowsStartup.ps1')) { Copy-Item -LiteralPath (Join-Path $projectRoot ('scripts/'+$helper)) -Destination (Join-Path $distribution 'scripts') }
if (Test-Path -LiteralPath (Join-Path $projectRoot 'lifeos-release.json')) { Copy-Item -LiteralPath (Join-Path $projectRoot 'lifeos-release.json') -Destination $distribution }
Copy-Item -LiteralPath $phpExe,(Join-Path $PhpDirectory 'license.txt') -Destination (Join-Path $distribution 'php')
Get-ChildItem -LiteralPath $PhpDirectory -Filter '*.dll' | Copy-Item -Destination (Join-Path $distribution 'php')
foreach ($extension in @('php_pdo_sqlite.dll','php_sqlite3.dll','php_mbstring.dll','php_openssl.dll','php_zip.dll','php_fileinfo.dll','php_curl.dll','php_opcache.dll')) { $extPath = Join-Path $PhpDirectory "ext/$extension"; if (Test-Path -LiteralPath $extPath) { Copy-Item -LiteralPath $extPath -Destination (Join-Path $distribution 'php/ext') } }
if(Test-Path -LiteralPath (Join-Path $projectRoot 'integrations')) { Copy-Item -LiteralPath (Join-Path $projectRoot 'integrations') -Destination $distribution -Recurse }
# App-local Microsoft VC runtime for PHP on clean Windows installations.
foreach ($dll in @('vcruntime140.dll','vcruntime140_1.dll','msvcp140.dll')) { Copy-Item -LiteralPath (Join-Path $env:WINDIR "System32/$dll") -Destination (Join-Path $distribution 'php') }
Copy-Item -LiteralPath (Join-Path $projectRoot 'php.ini') -Destination (Join-Path $distribution 'php/php.ini')
Copy-Item -LiteralPath $WebViewInstaller -Destination (Join-Path $distribution 'prerequisites/MicrosoftEdgeWebView2RuntimeInstallerX64.exe')
Copy-Item -LiteralPath (Join-Path $projectRoot 'README.md'),(Join-Path $projectRoot 'README-si.md'),(Join-Path $projectRoot 'THIRD-PARTY.md') -Destination $distribution
if(Test-Path -LiteralPath (Join-Path $projectRoot 'qa')) { Copy-Item -LiteralPath (Join-Path $projectRoot 'qa') -Destination $distribution -Recurse }
if (Test-Path -LiteralPath (Join-Path $projectRoot 'VERIFICATION.md')) { Copy-Item -LiteralPath (Join-Path $projectRoot 'VERIFICATION.md') -Destination $distribution }
if (Test-Path -LiteralPath (Join-Path $projectRoot 'PERFORMANCE.md')) { Copy-Item -LiteralPath (Join-Path $projectRoot 'PERFORMANCE.md') -Destination $distribution }
& $InnoCompiler (Join-Path $projectRoot 'lifeos.iss')
if ($LASTEXITCODE -ne 0) { throw 'Installer compilation failed.' }
$setupPath=Join-Path $projectRoot 'Output/LifeOS-Setup.exe'
$setupHash=(Get-FileHash -LiteralPath $setupPath -Algorithm SHA256).Hash.ToLowerInvariant()
[IO.File]::WriteAllText($setupPath+'.sha256',$setupHash+'  LifeOS-Setup.exe'+[Environment]::NewLine,[Text.Encoding]::ASCII)
Write-Host "Built $(Join-Path $projectRoot 'Output/LifeOS-Setup.exe')"
