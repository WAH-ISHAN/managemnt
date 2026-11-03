#requires -Version 5.1
[CmdletBinding()]
param([string]$Dotnet='.tools/dotnet/dotnet.exe')
$ErrorActionPreference='Stop'
$root=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$dotnetPath=[IO.Path]::GetFullPath((Join-Path $root $Dotnet))
$build=Join-Path $root 'launcher/bin/Release/net8.0-windows10.0.22621.0'
$php=Join-Path $root 'dist/php'
if(-not(Test-Path (Join-Path $build 'LifeOS.dll')) -or -not(Test-Path (Join-Path $php 'php.exe'))) { throw 'Build the launcher and stage the bundled PHP runtime before running activation smoke.' }
$evidence=Join-Path $root ('tests/.tmp/activation-'+[guid]::NewGuid().ToString('N'))
$app=Join-Path $evidence 'app-root'; $data=Join-Path $evidence 'data'
New-Item -ItemType Directory -Path $app,$data -Force | Out-Null
Copy-Item -Path (Join-Path $build '*') -Destination $app -Force
foreach($name in @('app','public','scripts')) { New-Item -ItemType Junction -Path (Join-Path $app $name) -Target (Join-Path $root $name) | Out-Null }
New-Item -ItemType Junction -Path (Join-Path $app 'php') -Target $php | Out-Null
$previousMode=$env:LIFEOS_ACTIVATION_TEST; $previousData=$env:LIFEOS_DATA_DIR
$first=$null; $second=$null
try {
    $env:LIFEOS_ACTIVATION_TEST='1'; $env:LIFEOS_DATA_DIR=$data
    $dll=Join-Path $app 'LifeOS.dll'
    $arguments='"'+$dll+'"'
    $first=Start-Process -FilePath $dotnetPath -ArgumentList $arguments -WorkingDirectory $app -WindowStyle Hidden -PassThru
    $ready=$false
    for($i=0;$i -lt 100;$i++) { if(Test-Path (Join-Path $data 'activation-ready')) { $ready=$true; break }; if($first.HasExited) { break }; Start-Sleep -Milliseconds 100 }
    if(-not $ready) { throw 'The isolated resident did not reach the hidden ready state.' }
    $second=Start-Process -FilePath $dotnetPath -ArgumentList $arguments -WorkingDirectory $app -WindowStyle Hidden -PassThru
    $received=$false
    for($i=0;$i -lt 100;$i++) { if(Test-Path (Join-Path $data 'activation-received')) { $received=$true; break }; Start-Sleep -Milliseconds 100 }
    if(-not $received) { throw 'The hidden resident did not receive the second launch signal.' }
    if(-not $first.WaitForExit(5000)) { throw 'The isolated resident did not close after the test activation.' }
    if(-not $second.WaitForExit(5000)) { throw 'The second launcher did not exit.' }
    Write-Host ('Activation smoke passed. Evidence: '+$evidence)
} finally {
    if($second -and -not $second.HasExited) { $second.Kill() }
    if($first -and -not $first.HasExited) { $first.Kill() }
    if($null -eq $previousMode) { Remove-Item Env:LIFEOS_ACTIVATION_TEST -ErrorAction SilentlyContinue } else { $env:LIFEOS_ACTIVATION_TEST=$previousMode }
    if($null -eq $previousData) { Remove-Item Env:LIFEOS_DATA_DIR -ErrorAction SilentlyContinue } else { $env:LIFEOS_DATA_DIR=$previousData }
}
