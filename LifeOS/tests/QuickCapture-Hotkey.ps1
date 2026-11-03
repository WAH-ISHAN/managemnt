param([string]$Dotnet=(Join-Path $PSScriptRoot '..\.tools\dotnet\dotnet.exe'))
$ErrorActionPreference='Stop'
$project=Join-Path $PSScriptRoot '.tmp\quick-capture-hotkey'
[void][IO.Directory]::CreateDirectory($project)
$source=[Security.SecurityElement]::Escape([IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\launcher\PanelHotkeyWindow.cs')))
[IO.File]::WriteAllText((Join-Path $project 'CaptureHotkey.csproj'),@"
<Project Sdk="Microsoft.NET.Sdk"><PropertyGroup><OutputType>Exe</OutputType><TargetFramework>net8.0-windows</TargetFramework><UseWindowsForms>true</UseWindowsForms><ImplicitUsings>enable</ImplicitUsings><Nullable>enable</Nullable></PropertyGroup><ItemGroup><Compile Include="$source" Link="PanelHotkeyWindow.cs" /></ItemGroup></Project>
"@)
[IO.File]::WriteAllText((Join-Path $project 'Program.cs'),@'
using System.Reflection;
using System.Windows.Forms;
internal static class Program {
 [STAThread] private static void Main() {
  ApplicationConfiguration.Initialize(); int captures=0,toggles=0;
  using(var hotkeys=new PanelHotkeyWindow(()=>toggles++,()=>captures++)) {
   if(!hotkeys.CaptureRegistered)throw new InvalidOperationException("Ctrl+Shift+Space registration failed: "+hotkeys.CaptureError);
   var dispatch=typeof(PanelHotkeyWindow).GetMethod("WndProc",BindingFlags.Instance|BindingFlags.NonPublic)!;
   object[] args={Message.Create(hotkeys.OwnerHandle,0x0312,new IntPtr(0x1A65),IntPtr.Zero)};dispatch.Invoke(hotkeys,args);
   if(captures!=1||toggles!=0)throw new Exception("Capture shortcut dispatched the wrong action.");
   args[0]=Message.Create(hotkeys.OwnerHandle,0x0312,new IntPtr(0x1A62),IntPtr.Zero);dispatch.Invoke(hotkeys,args);
   if(captures!=1||toggles!=1)throw new Exception("Existing panel shortcut changed.");
   hotkeys.Dispose(); if(hotkeys.CaptureRegistered||hotkeys.OwnerHandle!=IntPtr.Zero)throw new Exception("Hotkeys were not released.");
  }
  using(var reopened=new PanelHotkeyWindow(()=>{},()=>{})) {if(!reopened.CaptureRegistered)throw new Exception("Capture shortcut leaked after disposal.");}
  Console.WriteLine("4 native global capture hotkey checks passed.");
 }
}
'@)
& $Dotnet build (Join-Path $project 'CaptureHotkey.csproj') --nologo -v:q
if($LASTEXITCODE-ne 0){throw 'Capture hotkey harness did not build.'}
& $Dotnet (Join-Path $project 'bin\Debug\net8.0-windows\CaptureHotkey.dll')
if($LASTEXITCODE-ne 0){throw 'Capture hotkey harness failed.'}
