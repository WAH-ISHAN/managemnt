param([string]$Setup = "$PSScriptRoot/../Output/LifeOS-Setup.exe")
$ErrorActionPreference = 'Stop'
$testRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '.tmp'))
$installation = [IO.Path]::GetFullPath((Join-Path $testRoot ('installed-' + [guid]::NewGuid().ToString('N'))))
if (-not $installation.StartsWith($testRoot + [IO.Path]::DirectorySeparatorChar,[StringComparison]::OrdinalIgnoreCase)) { throw 'Unsafe test installation directory.' }
New-Item -ItemType Directory -Path $installation -Force | Out-Null
$setupPath = (Resolve-Path -LiteralPath $Setup).Path
$uninstallKey = 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Uninstall\{E89C8D98-DC12-4820-A52B-1334E5D06A19}_is1'
$existingInstall = Get-ItemProperty -LiteralPath $uninstallKey -ErrorAction SilentlyContinue
if ($existingInstall) { throw 'LifeOS is already registered for this Windows user. Run the installer smoke test under a disposable Windows account to preserve the existing installation.' }
$startupShortcut=Join-Path ([Environment]::GetFolderPath('Startup')) 'LifeOS.lnk'
if (Test-Path -LiteralPath $startupShortcut) { throw 'A LifeOS startup shortcut already exists. Run the installer smoke test under a disposable Windows account to preserve it.' }
$script:testInstalled=$false
$script:launchCount=0
function Install-Test {
    $process=Start-Process -FilePath $setupPath -ArgumentList @('/VERYSILENT','/SUPPRESSMSGBOXES','/NORESTART','/CURRENTUSER','/NOICONS','/TASKS=startup',('/DIR="'+$installation+'"'),('/LOG="'+(Join-Path $testRoot 'install.log')+'"')) -WindowStyle Hidden -PassThru
    if (-not $process.WaitForExit(60000)) { $process.Kill(); throw 'Installer timed out.' }
    if ($process.ExitCode -ne 0) { throw "Installer failed: $($process.ExitCode)" }
    $script:testInstalled=$true
    if (-not (Test-Path -LiteralPath $startupShortcut)) { throw 'Installer did not create the selected Windows startup shortcut.' }
    $shell=New-Object -ComObject WScript.Shell
    try {
        $startup=$shell.CreateShortcut($startupShortcut)
        if ($startup.TargetPath -ne (Join-Path $installation 'LifeOS.exe') -or $startup.WorkingDirectory -ne $installation -or $startup.Arguments -ne '') { throw 'Installer startup shortcut has the wrong target, working directory or arguments.' }
    } finally { [void][Runtime.InteropServices.Marshal]::ReleaseComObject($shell) }
}
function Launch-Test {
    $previousSmoke=$env:LIFEOS_SMOKE_TEST
    $previousData=$env:LIFEOS_DATA_DIR
    $previousProfile=$env:LIFEOS_PROFILE_TEST
    $env:LIFEOS_SMOKE_TEST='1'
    $env:LIFEOS_DATA_DIR=Join-Path $installation 'data'
    Remove-Item Env:LIFEOS_PROFILE_TEST -ErrorAction SilentlyContinue
    try {
        $resultPath=Join-Path $installation 'data/launcher-smoke.json'
        Remove-Item -LiteralPath $resultPath -ErrorAction SilentlyContinue
        $process=Start-Process -FilePath (Join-Path $installation 'LifeOS.exe') -WorkingDirectory $installation -WindowStyle Hidden -PassThru
        if (-not $process.WaitForExit(60000)) { $process.Kill(); throw 'Launcher timed out.' }
        $result=Get-Content -LiteralPath $resultPath -Raw | ConvertFrom-Json
        if (-not $result.success) { throw ('Launcher failed: '+($result | ConvertTo-Json -Compress)) }
        $page=$result.page | ConvertFrom-Json | ConvertFrom-Json
        if (-not $page.main -or $page.charts -lt 1) { throw 'Dashboard or chart did not render in WebView2.' }
        if (Get-Process -Id $result.phpPid -ErrorAction SilentlyContinue) { throw 'PHP survived window close.' }
        $script:launchCount++
        Copy-Item -LiteralPath $resultPath -Destination (Join-Path $installation ('data/launcher-smoke-'+$script:launchCount+'.json'))
        Write-Host ('WebView2 launch '+$script:launchCount+': '+@($result.checks.PSObject.Properties).Count+' UI checks passed; PHP exited.')
    } finally {
        $env:LIFEOS_SMOKE_TEST=$previousSmoke
        $env:LIFEOS_DATA_DIR=$previousData
        $env:LIFEOS_PROFILE_TEST=$previousProfile
    }
}
function Uninstall-Test {
    $uninstaller=(Get-ChildItem -LiteralPath $installation -Filter 'unins*.exe' | Sort-Object LastWriteTime -Descending | Select-Object -First 1).FullName
    if (-not $uninstaller) { throw 'Uninstaller is missing.' }
    $process=Start-Process -FilePath $uninstaller -ArgumentList @('/VERYSILENT','/SUPPRESSMSGBOXES','/NORESTART') -WindowStyle Hidden -PassThru
    if (-not $process.WaitForExit(45000)) { $process.Kill(); throw 'Uninstaller timed out.' }
    if ($process.ExitCode -ne 0) { throw 'Uninstall failed.' }
    for ($i=0;$i -lt 50 -and (Test-Path -LiteralPath $uninstaller);$i++) { Start-Sleep -Milliseconds 100 }
    if (Test-Path -LiteralPath (Join-Path $installation 'LifeOS.exe')) { throw 'Uninstaller left program files behind.' }
    if (Test-Path -LiteralPath $startupShortcut) { throw 'Uninstaller left the LifeOS startup shortcut behind.' }
    $script:testInstalled=$false
}
function Verify-Data([string]$Mode='check') {
    & php.exe (Join-Path $PSScriptRoot 'windows_data.php') (Join-Path $installation 'data/lifeos.db') $Mode
    if ($LASTEXITCODE -ne 0) { throw 'Database preservation check failed.' }
}
try {
    Install-Test; Launch-Test
    Verify-Data 'seed'
    $marker=Join-Path $installation 'data/preservation-check.txt'; [IO.File]::WriteAllText($marker,'LifeOS test data')
    Install-Test; Launch-Test
    Verify-Data
    if (-not (Test-Path -LiteralPath $marker)) { throw 'Data did not survive upgrade.' }
    Uninstall-Test
    Verify-Data
    if (-not (Test-Path -LiteralPath $marker) -or -not (Test-Path -LiteralPath (Join-Path $installation 'data/lifeos.db'))) { throw 'Data did not survive uninstall.' }
    Install-Test; Launch-Test; Verify-Data; Uninstall-Test; Verify-Data
    Write-Host "Windows installer, WebView2 render/chart, PHP shutdown, upgrade and data preservation checks passed: $installation"
} finally {
    if ($script:testInstalled -and (Get-ChildItem -LiteralPath $installation -Filter 'unins*.exe' -ErrorAction SilentlyContinue)) { Uninstall-Test }
}
