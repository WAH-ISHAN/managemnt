param([string]$Dotnet=(Join-Path $PSScriptRoot '..\.tools\dotnet\dotnet.exe'))
$ErrorActionPreference='Stop'
$folder=Join-Path $PSScriptRoot '.tmp\startup-welcome'
New-Item -ItemType Directory -Path $folder -Force|Out-Null
$source=[Security.SecurityElement]::Escape([IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\launcher\StartupWelcome.cs')))
[IO.File]::WriteAllText((Join-Path $folder 'Welcome.csproj'),@"
<Project Sdk="Microsoft.NET.Sdk"><PropertyGroup><OutputType>Exe</OutputType><TargetFramework>net8.0-windows</TargetFramework><UseWindowsForms>true</UseWindowsForms><ImplicitUsings>enable</ImplicitUsings><Nullable>enable</Nullable></PropertyGroup><ItemGroup><Compile Include="$source" Link="StartupWelcome.cs" /></ItemGroup></Project>
"@)
[IO.File]::WriteAllText((Join-Path $folder 'Program.cs'),@'
using System.Diagnostics;
using System.Windows.Forms;
internal static class Program {
 [STAThread] private static void Main(string[] args) {
  ApplicationConfiguration.Initialize();
  using(var owner=new Form { Bounds=new System.Drawing.Rectangle(100,100,900,650) })
  using(var welcome=new StartupWelcome(args[0])) {
   owner.Show();welcome.Show(owner);var clock=Stopwatch.StartNew();
   while(clock.ElapsedMilliseconds<500){Application.DoEvents();Thread.Sleep(15);}
   if(welcome.Bounds!=owner.Bounds)throw new Exception("Splash does not cover its owner");
   if(welcome.Stage!=StartupStage.LogoReady)throw new Exception("Logo never became ready");
   using(var bitmap=new Bitmap(welcome.ClientSize.Width,welcome.ClientSize.Height)){welcome.DrawToBitmap(bitmap,welcome.ClientRectangle);bitmap.Save(args[1]);}
   if(welcome.IsDisposed)throw new Exception("Closed before startup was ready");
   welcome.Ready();
   while(!welcome.IsDisposed && clock.ElapsedMilliseconds<1500){Application.DoEvents();Thread.Sleep(15);}
   if(!welcome.IsDisposed)throw new Exception("Ready startup overlay did not close");
   if(welcome.Stage!=StartupStage.DashboardVisible)throw new Exception("Splash exit did not complete");
  }
  using(var fallback=new StartupWelcome("missing-logo-fixture")) {
   fallback.Show();fallback.Ready();
   var clock=Stopwatch.StartNew();
   while(!fallback.IsDisposed && clock.ElapsedMilliseconds<1500){Application.DoEvents();Thread.Sleep(15);}
   if(!fallback.IsDisposed)throw new Exception("Missing asset fallback did not exit");
  }
  using(var timeout=new StartupWelcome(args[0])) {
   bool notified=false;
   timeout.TimedOut+=()=>{notified=true;timeout.Close();};
   timeout.Show();var clock=Stopwatch.StartNew();
   while(!timeout.IsDisposed && clock.ElapsedMilliseconds<32000){Application.DoEvents();Thread.Sleep(15);}
   if(!notified||!timeout.IsDisposed)throw new Exception("Unready splash remained stuck");
  }
  Console.WriteLine("7 startup logo/readiness/fallback/lifecycle checks passed.");
 }
}
'@)
& $Dotnet run --project (Join-Path $folder 'Welcome.csproj') -- ([IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))) ([IO.Path]::GetFullPath((Join-Path $folder 'welcome.png')))
if($LASTEXITCODE-ne 0){throw 'Startup welcome test failed.'}
