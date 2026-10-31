<#
.SYNOPSIS
Configure LifeOS for the current user's Windows sign-in.
.DESCRIPTION
Maintains one quoted HKCU Run command. An owned legacy Startup-folder shortcut
is removed after registration. Entries targeting another executable are kept.
The RunKeyPath and StartupDirectory overrides are for isolated QA.
#>
[CmdletBinding()]
param(
    [ValidateSet('Enable','Disable','Status')][string]$Mode='Status',
    [string]$InstallDirectory,
    [string]$RunKeyPath='HKCU:\Software\Microsoft\Windows\CurrentVersion\Run',
    [string]$StartupDirectory=[Environment]::GetFolderPath('Startup')
)
$ErrorActionPreference='Stop'
$valueName='LifeOS'
if(-not $RunKeyPath.StartsWith('HKCU:\Software\',[StringComparison]::OrdinalIgnoreCase)){throw 'Use a current-user Software registry key.'}
if([string]::IsNullOrWhiteSpace($InstallDirectory)){
    $registration=Get-ItemProperty -LiteralPath 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Uninstall\{E89C8D98-DC12-4820-A52B-1334E5D06A19}_is1' -ErrorAction SilentlyContinue
    if(-not $registration -or [string]::IsNullOrWhiteSpace($registration.InstallLocation)){throw 'No per-user LifeOS installation is registered. Supply -InstallDirectory.'}
    $InstallDirectory=$registration.InstallLocation
}
$installRoot=(Resolve-Path -LiteralPath $InstallDirectory).Path
$target=Join-Path $installRoot 'LifeOS.exe'
foreach($file in @($target,(Join-Path $installRoot 'php\php.exe'),(Join-Path $installRoot 'public\index.php'))){if(-not(Test-Path -LiteralPath $file -PathType Leaf)){throw "LifeOS installation is incomplete: $file"}}
if((Get-Item -LiteralPath $target).VersionInfo.ProductName -ne 'LifeOS'){throw 'The selected executable is not a LifeOS launcher.'}
if([string]::IsNullOrWhiteSpace($StartupDirectory)){throw 'The Startup folder is unavailable.'}
$shortcutPath=Join-Path ([IO.Path]::GetFullPath($StartupDirectory)) 'LifeOS.lnk'
$expectedCommand='"'+$target+'" --startup'

function Get-RunState {
    $key=Get-Item -LiteralPath $RunKeyPath -ErrorAction SilentlyContinue
    if(-not $key){return [pscustomobject]@{Present=$false;Command=$null;Owned=$false}}
    try{
        if($key.GetValueNames() -notcontains $valueName){return [pscustomobject]@{Present=$false;Command=$null;Owned=$false}}
        $command=$key.GetValue($valueName,$null,[Microsoft.Win32.RegistryValueOptions]::DoNotExpandEnvironmentNames)
    }finally{$key.Close()}
    $executable=$null
    if($command -is [string]){
        $trimmed=$command.Trim()
        if($trimmed -match '^"([^"]+)"(?:\s|$)'){$executable=$matches[1]}
        elseif($trimmed -match '^(.*?\.exe)(?:\s|$)'){$executable=$matches[1]}
    }
    $owned=$false
    if($executable){try{$expanded=[Environment]::ExpandEnvironmentVariables($executable);$owned=[string]::Equals([IO.Path]::GetFullPath($expanded),$target,[StringComparison]::OrdinalIgnoreCase)}catch{}}
    return [pscustomobject]@{Present=$true;Command=$command;Owned=$owned}
}

$state=Get-RunState
if($state.Present -and -not $state.Owned -and $Mode -ne 'Status'){throw 'The LifeOS Run value targets another executable and was left unchanged.'}
$shell=New-Object -ComObject WScript.Shell
try{
    $legacyPresent=Test-Path -LiteralPath $shortcutPath -PathType Leaf
    $legacyOwned=$false
    if($legacyPresent){$shortcut=$shell.CreateShortcut($shortcutPath);$legacyOwned=[string]::Equals($shortcut.TargetPath,$target,[StringComparison]::OrdinalIgnoreCase)}
    $legacyRemoved=$false
    if($Mode -eq 'Enable'){
        if(-not(Test-Path -LiteralPath $RunKeyPath)){New-Item -Path $RunKeyPath | Out-Null}
        New-ItemProperty -LiteralPath $RunKeyPath -Name $valueName -PropertyType String -Value $expectedCommand -Force | Out-Null
        if($legacyOwned){Remove-Item -LiteralPath $shortcutPath;$legacyRemoved=$true}
    }elseif($Mode -eq 'Disable'){
        if($state.Present -and $state.Owned){Remove-ItemProperty -LiteralPath $RunKeyPath -Name $valueName}
        if($legacyOwned){Remove-Item -LiteralPath $shortcutPath;$legacyRemoved=$true}
    }
    $actual=Get-RunState
    [pscustomobject]@{
        Configured=$actual.Owned -and [string]::Equals([string]$actual.Command,$expectedCommand,[StringComparison]::Ordinal)
        RunKeyPath=$RunKeyPath
        RunValueName=$valueName
        Command=$actual.Command
        Owned=$actual.Owned
        OwnershipConflict=$actual.Present -and -not $actual.Owned
        InstallDirectory=$installRoot
        LegacyShortcutPath=$shortcutPath
        LegacyShortcutPresent=(Test-Path -LiteralPath $shortcutPath -PathType Leaf)
        LegacyShortcutOwned=$legacyOwned
        LegacyShortcutRemoved=$legacyRemoved
        Note='Opens at this user''s Windows sign-in. Windows Startup settings may independently disable the Run entry.'
    }
}finally{[void][Runtime.InteropServices.Marshal]::ReleaseComObject($shell)}
