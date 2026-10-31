<#
.SYNOPSIS
Configure the current Windows user's LifeOS sign-in shortcut.
.DESCRIPTION
Finds the registered per-user installation unless -InstallDirectory is supplied.
Does not launch, update or access LifeOS personal data. Windows Settings > Apps >
Startup can independently disable a configured shortcut.
.EXAMPLE
./scripts/Set-WindowsStartup.ps1 -Mode Enable
.EXAMPLE
./scripts/Set-WindowsStartup.ps1 -Mode Disable
#>
[CmdletBinding()]
param(
    [ValidateSet('Enable','Disable','Status')][string]$Mode = 'Status',
    [string]$InstallDirectory,
    # Override only for isolated checks; defaults to this user's Startup folder.
    [string]$StartupDirectory = [Environment]::GetFolderPath('Startup')
)
$ErrorActionPreference = 'Stop'
if ([string]::IsNullOrWhiteSpace($InstallDirectory)) {
    $registration = Get-ItemProperty -LiteralPath 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Uninstall\{E89C8D98-DC12-4820-A52B-1334E5D06A19}_is1' -ErrorAction SilentlyContinue
    if (-not $registration -or [string]::IsNullOrWhiteSpace($registration.InstallLocation)) {
        throw 'No per-user LifeOS installation is registered. Supply -InstallDirectory for a portable installation.'
    }
    $InstallDirectory = $registration.InstallLocation
}
$installRoot = (Resolve-Path -LiteralPath $InstallDirectory).Path
$target = Join-Path $installRoot 'LifeOS.exe'
foreach ($required in @($target, (Join-Path $installRoot 'php/php.exe'), (Join-Path $installRoot 'public/index.php'))) {
    if (-not (Test-Path -LiteralPath $required -PathType Leaf)) { throw "LifeOS installation is incomplete: $required" }
}
if ((Get-Item -LiteralPath $target).VersionInfo.ProductName -ne 'LifeOS') { throw 'The selected executable is not a LifeOS launcher.' }
if ([string]::IsNullOrWhiteSpace($StartupDirectory)) { throw 'The Windows Startup folder is unavailable.' }
$startupRoot = [IO.Path]::GetFullPath($StartupDirectory)
$shortcutPath = Join-Path $startupRoot 'LifeOS.lnk'
$shell = New-Object -ComObject WScript.Shell
try {
    $exists = Test-Path -LiteralPath $shortcutPath -PathType Leaf
    $shortcut = $null
    if ($exists) {
        $shortcut = $shell.CreateShortcut($shortcutPath)
        $matches = [string]::Equals($shortcut.TargetPath, $target, [StringComparison]::OrdinalIgnoreCase)
        if (-not $matches -and $Mode -ne 'Status') {
            throw "The existing LifeOS startup shortcut targets another installation. It was left unchanged: $($shortcut.TargetPath)"
        }
    }
    if ($Mode -eq 'Enable') {
        New-Item -ItemType Directory -Path $startupRoot -Force | Out-Null
        if (-not $shortcut) { $shortcut = $shell.CreateShortcut($shortcutPath) }
        $shortcut.TargetPath = $target
        $shortcut.WorkingDirectory = $installRoot
        $shortcut.Arguments = ''
        $shortcut.Description = 'Open LifeOS when you sign in to Windows'
        $shortcut.Save()
    } elseif ($Mode -eq 'Disable' -and $exists) {
        Remove-Item -LiteralPath $shortcutPath
    }
    $configured = Test-Path -LiteralPath $shortcutPath -PathType Leaf
    $actualTarget = if ($configured) { $shell.CreateShortcut($shortcutPath).TargetPath } else { $null }
    [pscustomobject]@{
        Configured = $configured -and [string]::Equals($actualTarget, $target, [StringComparison]::OrdinalIgnoreCase)
        ShortcutPath = $shortcutPath
        TargetPath = $actualTarget
        InstallDirectory = $installRoot
        Note = 'Opens at Windows sign-in. Windows Startup settings can independently disable this shortcut.'
    }
} finally {
    [void][Runtime.InteropServices.Marshal]::ReleaseComObject($shell)
}
