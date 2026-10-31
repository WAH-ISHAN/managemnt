param([string]$InstallDirectory = "$PSScriptRoot/../dist")
$ErrorActionPreference = 'Stop'
$testRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot ('.tmp/startup-' + [guid]::NewGuid().ToString('N'))))
$helper = Join-Path $PSScriptRoot '../scripts/Set-WindowsStartup.ps1'
New-Item -ItemType Directory -Path $testRoot -Force | Out-Null
function Check([bool]$Condition, [string]$Message) { if (-not $Condition) { throw $Message }; Write-Host "Passed: $Message" }
$arguments = @{ InstallDirectory = $InstallDirectory; StartupDirectory = (Join-Path $testRoot 'Startup folder') }
$status = & $helper @arguments -Mode Status
Check (-not $status.Configured) 'Status does not create a shortcut'
Check (-not (Test-Path -LiteralPath $arguments.StartupDirectory)) 'Status does not create a Startup folder'
$enabled = & $helper @arguments -Mode Enable
Check $enabled.Configured 'Enable registers the selected LifeOS executable'
$shell = New-Object -ComObject WScript.Shell
try {
    $link = $shell.CreateShortcut($enabled.ShortcutPath)
    Check ($link.Arguments -eq '') 'Startup launches the regular app without diagnostic arguments'
    Check ($link.WorkingDirectory -eq (Resolve-Path -LiteralPath $InstallDirectory).Path) 'Working directory matches the selected installation'
    $again = & $helper @arguments -Mode Enable
    Check $again.Configured 'Repeated enable is idempotent'
    $disabled = & $helper @arguments -Mode Disable
    Check (-not $disabled.Configured) 'Disable removes only the LifeOS startup shortcut'
    $disabledAgain = & $helper @arguments -Mode Disable
    Check (-not $disabledAgain.Configured) 'Repeated disable is idempotent'
    $link = $shell.CreateShortcut($enabled.ShortcutPath)
    $link.TargetPath = Join-Path $env:WINDIR 'notepad.exe'
    $link.Save()
    $refusedEnable = $false; $refusedDisable = $false
    try { & $helper @arguments -Mode Enable | Out-Null } catch { $refusedEnable = $_.Exception.Message -like '*another installation*' }
    try { & $helper @arguments -Mode Disable | Out-Null } catch { $refusedDisable = $_.Exception.Message -like '*another installation*' }
    Check $refusedEnable 'Enable refuses to overwrite a shortcut targeting another executable'
    Check $refusedDisable 'Disable refuses to remove a shortcut targeting another executable'
    Check ($shell.CreateShortcut($enabled.ShortcutPath).TargetPath -eq (Join-Path $env:WINDIR 'notepad.exe')) 'Unrelated shortcut remains unchanged'
} finally {
    [void][Runtime.InteropServices.Marshal]::ReleaseComObject($shell)
}
Write-Host "Startup shortcut checks passed in isolated folder: $testRoot"
