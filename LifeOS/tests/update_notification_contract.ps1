#requires -Version 5.1
[CmdletBinding()]
param()
$ErrorActionPreference='Stop'
$project=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$source=Get-Content -LiteralPath (Join-Path $project 'launcher/AppUpdates.cs') -Raw
$checks=0
function Check([bool]$Condition,[string]$Label){if(-not $Condition){throw $Label};$script:checks++}

$interval=[regex]::Match($source,'UpdatePollIntervalMilliseconds\s*=\s*([0-9_]+)')
Check $interval.Success 'Update polling interval is explicit'
$milliseconds=[int]($interval.Groups[1].Value.Replace('_',''))
Check ($milliseconds -gt 0 -and $milliseconds -le 60000) 'Installed clients poll within one minute'
Check ($source.Contains('new(){Interval=UpdatePollIntervalMilliseconds}')) 'The timer uses the bounded polling interval'
Check ($source.Contains('SystemSounds.Asterisk.Play()')) 'A native Windows sound announces a new release'
Check ($source.Contains('release.Version+"-build."+release.BuildNumber')) 'Distinct builds under the same version each have their own alert'
Check ($source.Contains('availableBuild=release.BuildNumber')) 'Staged updates record the precise published build'
Check (-not $source.Contains('updateChecking || updateReady || IsDisposed')) 'Pending downloads do not permanently block later release checks'
Check ($source.Contains('reminders.ShowBalloonTip(10_000)')) 'A Windows tray notification announces a new release'
Check ($source.IndexOf('AnnounceUpdate(release)',[StringComparison]::Ordinal) -lt $source.IndexOf('client.GetAsync(release.Package.Url',[StringComparison]::Ordinal)) 'Notification occurs before the package download'
Check ($source.Contains('Cache-Control","no-cache, no-store')) 'Release checks bypass stale HTTP caches'

[xml]$projectFile=Get-Content -LiteralPath (Join-Path $project 'launcher/LifeOS.csproj')
$version=[string]$projectFile.Project.PropertyGroup.Version
$installer=Get-Content -LiteralPath (Join-Path $project 'lifeos.iss') -Raw
$settings=Get-Content -LiteralPath (Join-Path $project 'app/Modules/Settings/views/index.php') -Raw
Check ($installer -match ('(?m)^AppVersion='+[regex]::Escape($version)+'\r?$')) 'Installer version matches the launcher'
Check ($settings.Contains('LifeOS '+$version)) 'Settings version matches the launcher'

Write-Host "$checks update notification contract checks passed."
