param([string]$InstallDirectory="$PSScriptRoot/../dist")
$ErrorActionPreference='Stop'
$helper=Join-Path $PSScriptRoot '../scripts/Set-WindowsLogin.ps1'
$identity=[guid]::NewGuid().ToString('N')
$temporaryRoot=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '.tmp'))
$testRoot=[IO.Path]::GetFullPath((Join-Path $temporaryRoot ('login-'+$identity)))
if(-not $testRoot.StartsWith($temporaryRoot+[IO.Path]::DirectorySeparatorChar,[StringComparison]::OrdinalIgnoreCase)){throw 'Unsafe test directory.'}
$registryRoot='HKCU:\Software\LifeOS\Tests\Login-'+$identity
if(-not $registryRoot.StartsWith('HKCU:\Software\LifeOS\Tests\Login-',[StringComparison]::Ordinal)){throw 'Unsafe test registry key.'}
$startup=Join-Path $testRoot 'Startup folder'
$arguments=@{InstallDirectory=$InstallDirectory;RunKeyPath=$registryRoot;StartupDirectory=$startup}
$target=Join-Path (Resolve-Path -LiteralPath $InstallDirectory).Path 'LifeOS.exe'
$expected='"'+$target+'" --startup'
$script:checks=0
function Check([bool]$Condition,[string]$Message){if(-not $Condition){throw $Message};$script:checks++;Write-Host "Passed: $Message"}
$shell=New-Object -ComObject WScript.Shell
try{
    $status=& $helper @arguments -Mode Status
    Check (-not $status.Configured) 'Status reports an absent registration'
    Check (-not(Test-Path -LiteralPath $registryRoot)) 'Status creates no registry key'
    Check (-not(Test-Path -LiteralPath $startup)) 'Status creates no Startup folder'

    New-Item -ItemType Directory -Path $startup -Force | Out-Null
    $legacyPath=Join-Path $startup 'LifeOS.lnk'
    $link=$shell.CreateShortcut($legacyPath);$link.TargetPath=$target;$link.WorkingDirectory=Split-Path $target;$link.Save()
    New-Item -Path $registryRoot -Force | Out-Null
    New-ItemProperty -LiteralPath $registryRoot -Name OtherApplication -PropertyType String -Value 'keep-this-entry' -Force | Out-Null
    $enabled=& $helper @arguments -Mode Enable
    Check $enabled.Configured 'Enable registers LifeOS for Windows sign-in'
    Check ($enabled.Command -ceq $expected) 'Run command quotes the executable and adds only --startup'
    Check ($enabled.LegacyShortcutRemoved -and -not(Test-Path -LiteralPath $legacyPath)) 'Enable removes the owned legacy Startup shortcut'
    Check ((Get-ItemPropertyValue -LiteralPath $registryRoot -Name LifeOS) -ceq $expected) 'Expected command is stored in the isolated Run value'
    Check ((Get-ItemPropertyValue -LiteralPath $registryRoot -Name OtherApplication) -ceq 'keep-this-entry') 'Enable preserves neighboring Run values'
    $again=& $helper @arguments -Mode Enable
    Check ($again.Configured -and $again.Command -ceq $expected) 'Repeated enable is idempotent'
    $before=(Get-ItemPropertyValue -LiteralPath $registryRoot -Name LifeOS)
    $read=& $helper @arguments -Mode Status
    Check ($read.Configured -and (Get-ItemPropertyValue -LiteralPath $registryRoot -Name LifeOS) -ceq $before) 'Status leaves an existing registration unchanged'

    Set-ItemProperty -LiteralPath $registryRoot -Name LifeOS -Value ('"'+$target+'" --old-argument')
    $old=& $helper @arguments -Mode Status
    Check ($old.Owned -and -not $old.Configured) 'Status distinguishes an owned outdated command'
    $fixed=& $helper @arguments -Mode Enable
    Check ($fixed.Command -ceq $expected) 'Enable normalizes owned arguments'
    $disabled=& $helper @arguments -Mode Disable
    Check (-not $disabled.Configured -and (Get-Item -LiteralPath $registryRoot).GetValueNames() -notcontains 'LifeOS') 'Disable removes the owned Run value'
    Check ((Get-ItemPropertyValue -LiteralPath $registryRoot -Name OtherApplication) -ceq 'keep-this-entry') 'Disable preserves neighboring Run values'
    $disabledAgain=& $helper @arguments -Mode Disable
    Check (-not $disabledAgain.Configured) 'Repeated disable is idempotent'

    $unrelated=Join-Path $env:WINDIR 'notepad.exe'
    $otherLink=$shell.CreateShortcut($legacyPath);$otherLink.TargetPath=$unrelated;$otherLink.Save()
    $withOtherLink=& $helper @arguments -Mode Enable
    Check ($withOtherLink.Configured -and (Test-Path -LiteralPath $legacyPath)) 'Enable preserves a legacy shortcut targeting another executable'
    [void](& $helper @arguments -Mode Disable)
    Check ([string]::Equals($shell.CreateShortcut($legacyPath).TargetPath,$unrelated,[StringComparison]::OrdinalIgnoreCase)) 'Disable also preserves the unrelated shortcut'

    $unrelatedCommand='"'+$unrelated+'" --unrelated'
    New-ItemProperty -LiteralPath $registryRoot -Name LifeOS -Value $unrelatedCommand -PropertyType String -Force | Out-Null
    $conflict=& $helper @arguments -Mode Status
    Check ($conflict.OwnershipConflict -and -not $conflict.Configured) 'Status reports another executable without changing it'
    foreach($mode in @('Enable','Disable')){
        $refused=$false
        try{& $helper @arguments -Mode $mode | Out-Null}catch{$refused=$_.Exception.Message -like '*another executable*'}
        Check $refused "$mode refuses an unrelated Run command"
        Check ((Get-ItemPropertyValue -LiteralPath $registryRoot -Name LifeOS) -ceq $unrelatedCommand) "$mode leaves the unrelated Run value intact"
    }
    Check ([string]::Equals($shell.CreateShortcut($legacyPath).TargetPath,$unrelated,[StringComparison]::OrdinalIgnoreCase)) 'Ownership failures leave the unrelated legacy shortcut intact'
    Write-Host "$script:checks Windows login checks passed in isolated registry and filesystem paths."
}finally{
    [void][Runtime.InteropServices.Marshal]::ReleaseComObject($shell)
    if(Test-Path -LiteralPath $registryRoot){Remove-Item -LiteralPath $registryRoot -Recurse -Force}
    if(Test-Path -LiteralPath $testRoot){$resolved=(Resolve-Path -LiteralPath $testRoot).Path;if(-not $resolved.StartsWith($temporaryRoot+[IO.Path]::DirectorySeparatorChar,[StringComparison]::OrdinalIgnoreCase)){throw 'Unsafe cleanup path.'};Remove-Item -LiteralPath $resolved -Recurse -Force}
}
