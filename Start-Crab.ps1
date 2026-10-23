$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot
$configPath = Join-Path $root 'config.json'
if (-not (Test-Path -LiteralPath $configPath)) { throw 'Crab is not installed. Run Install-Crab.ps1 first.' }
$version = (Get-Content -LiteralPath (Join-Path $root 'current.txt') -Raw).Trim()
$releaseDir = Join-Path (Join-Path $root 'versions') $version
$php = Join-Path $releaseDir 'runtime\php.exe'
$public = Join-Path $releaseDir 'app\public'
if (-not (Test-Path -LiteralPath $php) -or -not (Test-Path -LiteralPath (Join-Path $public 'index.php'))) { throw 'Crab installation is incomplete.' }
$env:CRAB_DATA_DIR = Join-Path $root 'data'

# Updating runs separately, so a network outage never prevents Crab from opening.
$updater = Join-Path $root 'Update-Crab.ps1'
Start-Process -FilePath 'powershell.exe' -WindowStyle Hidden -ArgumentList @('-NoProfile','-ExecutionPolicy','Bypass','-File',('"' + $updater + '"')) | Out-Null

$listener = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, 0)
$listener.Start()
$port = $listener.LocalEndpoint.Port
$listener.Stop()
$url = "http://127.0.0.1:$port/"
$ini = Join-Path $releaseDir 'runtime\php.ini'
$server = Start-Process -FilePath $php -ArgumentList @('-c', ('"' + $ini + '"'), '-S', "127.0.0.1:$port", '-t', ('"' + $public + '"')) -WindowStyle Hidden -PassThru
try {
    $ready = $false
    for ($attempt = 0; $attempt -lt 40; $attempt++) {
        if ($server.HasExited) { throw 'PHP server stopped before Crab opened.' }
        try {
            $connection = [System.Net.Sockets.TcpClient]::new()
            $connection.Connect('127.0.0.1', $port)
            $connection.Dispose()
            $ready = $true
            break
        } catch { Start-Sleep -Milliseconds 100 }
    }
    if (-not $ready) { throw 'Crab server did not start.' }
    Start-Process $url | Out-Null
    Wait-Process -Id $server.Id
} finally {
    if (-not $server.HasExited) { Stop-Process -Id $server.Id }
}
