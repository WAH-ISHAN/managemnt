# Windows sign-in startup

LifeOS can open automatically after the current Windows user signs in. The installer now offers **Start LifeOS when I sign in to Windows**, selected by default for a new installation. It creates the current user's `Startup/LifeOS.lnk`, with the installed `LifeOS.exe` as its target and the installation folder as its working directory. The installer removes its shortcut when uninstalled.

For an existing installation, from the repository:

```powershell
./scripts/Set-WindowsStartup.ps1 -Mode Enable
./scripts/Set-WindowsStartup.ps1 -Mode Status
./scripts/Set-WindowsStartup.ps1 -Mode Disable
```

The helper discovers the installation from LifeOS's current-user uninstall registration. Supply `-InstallDirectory 'D:\Apps\LifeOS'` for a portable copy. It validates the launcher and required payload, and refuses to replace or delete an existing `LifeOS.lnk` that targets a different installation. It changes no application files, database records, credentials or personal documents.

Windows **Settings > Apps > Startup** can independently disable a configured startup shortcut. `Configured: True` reports that the shortcut exists and targets this installation; an actual sign-in remains the end-to-end acceptance check.

Startup opens the regular LifeOS window. Closing the window stops the local PHP server under the existing lifecycle. This is a per-user sign-in action, not a pre-login Windows service; synchronization that requires the app therefore runs while LifeOS is open.

Run the isolated shortcut checks without touching the real Startup folder:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tests/windows_startup.ps1
```

The installer smoke test still requires a disposable Windows account because a personal installation is registered in this workspace's account. The new installer option is source-validated and must also be checked in that installer acceptance run; configuring a shortcut does not prove that Windows sign-in has been exercised.
