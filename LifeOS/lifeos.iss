[Setup]
AppId={{E89C8D98-DC12-4820-A52B-1334E5D06A19}
AppName=LifeOS
AppVersion=1.8.0
AppPublisher=Ishan
AppComments=Student life management system for study, finances, goals and personal growth.
DefaultDirName={localappdata}\Programs\LifeOS
DisableDirPage=no
DefaultGroupName=LifeOS
OutputDir=Output
OutputBaseFilename=LifeOS-Setup
Compression=lzma2
SolidCompression=yes
PrivilegesRequired=lowest
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
WizardStyle=modern
CloseApplications=yes
SetupLogging=yes
UninstallDisplayIcon={app}\LifeOS.exe
SetupIconFile=icon.ico

[Files]
Source: "dist\*"; DestDir: "{app}"; Excludes: "prerequisites\*,data\*"; Flags: recursesubdirs createallsubdirs ignoreversion
Source: "dist\prerequisites\MicrosoftEdgeWebView2RuntimeInstallerX64.exe"; DestDir: "{tmp}"; Flags: deleteafterinstall

[Dirs]
Name: "{app}\data"; Flags: uninsneveruninstall

[Tasks]
Name: "startup"; Description: "Start LifeOS when I sign in to Windows"; GroupDescription: "Windows startup:"

[Registry]
; Remove the legacy Run entry to prevent duplicate launches; use the startup shortcut.
Root: HKCU; Subkey: "Software\Microsoft\Windows\CurrentVersion\Run"; ValueName: "LifeOS"; Flags: deletevalue

[Icons]
Name: "{userstartup}\LifeOS"; Filename: "{app}\LifeOS.exe"; WorkingDir: "{app}"; Tasks: startup
Name: "{autoprograms}\LifeOS"; Filename: "{app}\LifeOS.exe"; WorkingDir: "{app}"
Name: "{autodesktop}\LifeOS"; Filename: "{app}\LifeOS.exe"; WorkingDir: "{app}"

[Run]
Filename: "{tmp}\MicrosoftEdgeWebView2RuntimeInstallerX64.exe"; Parameters: "/silent /install"; StatusMsg: "Preparing WebView2 for LifeOS..."; Flags: waituntilterminated; Check: NeedsWebView2
Filename: "{app}\LifeOS.exe"; Description: "Launch LifeOS"; Flags: nowait postinstall skipifsilent

[Code]
function NeedsWebView2: Boolean;
var Version: String;
begin
  Result := not (RegQueryStringValue(HKCU, 'Software\Microsoft\EdgeUpdate\Clients\{F3017226-FE2A-4295-8BDF-00C3A9A7E4C5}', 'pv', Version) and (Version <> '') and (Version <> '0.0.0.0'));
  if Result then Result := not (RegQueryStringValue(HKLM32, 'Software\Microsoft\EdgeUpdate\Clients\{F3017226-FE2A-4295-8BDF-00C3A9A7E4C5}', 'pv', Version) and (Version <> '') and (Version <> '0.0.0.0'));
end;

