#requires -Version 5.1
param([string]$Dotnet='.tools/dotnet/dotnet.exe',[switch]$BundledAiRuntime)
$ErrorActionPreference='Stop'
$root=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$data=Join-Path $root ('tests/.tmp/browser-profiles-'+[guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $data | Out-Null
& php (Join-Path $PSScriptRoot 'browser_profiles_fixture.php') $data
if($LASTEXITCODE -ne 0){throw 'Fixture creation failed.'}
$app=Join-Path $data 'host';New-Item -ItemType Directory -Path $app | Out-Null
Copy-Item -Path (Join-Path $root 'launcher/bin/Release/net8.0-windows10.0.22621.0/*') -Destination $app -Recurse
foreach($name in @('app','public')) {New-Item -ItemType Junction -Path (Join-Path $app $name) -Target (Join-Path $root $name) | Out-Null}
if($BundledAiRuntime){
    $helpers=Join-Path $app 'scripts';New-Item -ItemType Directory -Path $helpers|Out-Null
    Get-ChildItem -LiteralPath (Join-Path $root 'scripts') -File|Copy-Item -Destination $helpers
    New-Item -ItemType Junction -Path (Join-Path $helpers 'ai-runtime') -Target (Join-Path $root 'dist/scripts/ai-runtime')|Out-Null
}else{New-Item -ItemType Junction -Path (Join-Path $app 'scripts') -Target (Join-Path $root 'scripts')|Out-Null}
New-Item -ItemType Junction -Path (Join-Path $app 'php') -Target (Join-Path $root 'dist/php') | Out-Null
$previousMode=$env:LIFEOS_BROWSER_TEST;$previousData=$env:LIFEOS_DATA_DIR;$previousBundled=$env:LIFEOS_AI_BUNDLED_TEST
try {
    $env:LIFEOS_BROWSER_TEST='1';$env:LIFEOS_DATA_DIR=$data
    if($BundledAiRuntime){$env:LIFEOS_AI_BUNDLED_TEST='1'}
    foreach($run in 1..2){
    $process=Start-Process -FilePath (Join-Path $root $Dotnet) -ArgumentList ('"'+(Join-Path $app 'LifeOS.dll')+'"') -WorkingDirectory $app -WindowStyle Hidden -PassThru
    $processTimeout=if($env:LIFEOS_AI_LIVE_TEST -eq '1'){150000}else{90000}
    if(-not $process.WaitForExit($processTimeout)){$process.Kill();throw 'Browser profile smoke timed out.'}
    $result=Get-Content -LiteralPath (Join-Path $data 'browser-profile-result.json') -Raw | ConvertFrom-Json
    $result | ConvertTo-Json -Depth 5
    if(-not $result.success){throw 'Browser profile smoke failed.'}
    Copy-Item -LiteralPath (Join-Path $data 'browser-profile-result.json') -Destination (Join-Path $data ('browser-profile-run-'+$run+'.json'))
    }
    Write-Host ('Evidence: '+$data)
} finally {$env:LIFEOS_BROWSER_TEST=$previousMode;$env:LIFEOS_DATA_DIR=$previousData;$env:LIFEOS_AI_BUNDLED_TEST=$previousBundled;if($process -and -not $process.HasExited){$process.Kill()}}
