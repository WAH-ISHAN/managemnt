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
  foreach(var (h,want) in new[]{(0,"Good evening"),(4,"Good evening"),(5,"Good morning"),(11,"Good morning"),(12,"Good afternoon"),(16,"Good afternoon"),(17,"Good evening"),(23,"Good evening")}) if(StartupWelcome.Greeting(h)!=want)throw new Exception("Greeting boundary "+h);
  var local=StartupWelcome.LocalTime(new DateTimeOffset(2026,11,3,23,0,0,TimeSpan.Zero));
  if(local.Day!=4||local.Hour!=4||local.Minute!=30)throw new Exception("Colombo date rollover");
  using(var welcome=new StartupWelcome(args[0])) {
   welcome.Show();var clock=Stopwatch.StartNew();
   while(clock.ElapsedMilliseconds<900){Application.DoEvents();Thread.Sleep(15);}
   using(var bitmap=new Bitmap(welcome.ClientSize.Width,welcome.ClientSize.Height)){welcome.DrawToBitmap(bitmap,welcome.ClientRectangle);bitmap.Save(args[1]);}
   if(welcome.IsDisposed)throw new Exception("Closed before startup was ready");
   welcome.Ready();
   while(!welcome.IsDisposed && clock.ElapsedMilliseconds<3000){Application.DoEvents();Thread.Sleep(15);}
   if(!welcome.IsDisposed)throw new Exception("Ready startup overlay did not close");
  }
  Console.WriteLine("11 startup greeting/timezone/lifecycle checks passed.");
 }
}
'@)
& $Dotnet run --project (Join-Path $folder 'Welcome.csproj') -- ([IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))) ([IO.Path]::GetFullPath((Join-Path $folder 'welcome.png')))
if($LASTEXITCODE-ne 0){throw 'Startup welcome test failed.'}
