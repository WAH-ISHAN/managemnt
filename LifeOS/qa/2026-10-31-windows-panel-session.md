# Windows startup, customizable panel and university session

The installer enables a per-user Windows Startup shortcut by default, with a
checkbox to opt out. Installer and Set-WindowsStartup.ps1 now use the same
shortcut. The old Run entry is removed during installation to prevent duplicate
launches. The helper recognizes and migrates a matching legacy Run entry, but
leaves unrelated installations alone. Windows Startup settings may disable it.

LifeOS.exe has an explicit LifeOS product/title for Task Manager. Closing the main
window keeps the tray process available for Win + backslash and calendar sync;
use Exit LifeOS in the tray menu to close it fully. Windows lists a hidden app
under background processes. End task can terminate LifeOS.exe; its PHP child is
already assigned to a kill-on-close Windows job.

The panel supports header dragging, corner resizing, exact width/height, reset,
text sizes from 12 to 18 pixels, light/dark/system appearance and content toggles.
Its position and dimensions are saved atomically in data/desktop-panel-layout.json;
text/appearance/content preferences live in SQLite. Saved layouts are clamped to
an available display after monitor changes. Controls and schedule text are larger,
wide panels use two content columns, and systems without native glass use an
opaque background. Browser previews are in previews/lifeos-custom-panel-*.png;
they show web content, not native Windows acrylic.

The panel keeps its existing hidden-WebView suspension, skips overlapping refresh
requests, and refreshes when visible again. The auto-hide timer stops when disabled.
The daily panel check now returns asynchronous results through the origin-checked
bridge instead of treating ExecuteScriptAsync as if it awaited a JS Promise.

University authentication stays in the persistent data/webview profile. HTTP 401
and 498 renew the access token once and retry. A saved refresh token can restore a
missing access token after restart; access-only renewal preserves the existing
refresh token. Rotated credentials are written in the university origin storage,
never sent to PHP. Temporary network/refresh failures retain credentials and saved
lectures. A visible OTP portal reports a changed session without waiting five
minutes. LifeOS cannot override university revocation or refresh-token expiry;
those cases still require OTP sign-in. Sync runs while LifeOS is open and unlocked.

## Validation in this Linux workspace

- .NET 8 Windows-targeted launcher build: passed, zero warnings/errors.
- NIBM bridge: 181 fixture checks; calendar UI lifecycle: 12 checks.
- University service: 93; calendar HTTP: 50; upgrade preservation: 33 checks.
- Panel HTTP: 24 checks, including appearance validation and notes/settings after
  restarting the actual PHP server.
- Chromium/Playwright: persisted text/theme, native size messages, no horizontal
  overflow at widths 320/440/760, manual light theme under a dark OS setting,
  no uncaught JavaScript errors, and one request for three rapid refresh clicks.
- Native Windows smoke coverage was extended to size changes, hide/reopen layout
  preservation, and restoring the saved layout in a new panel instance. These
  runtime checks have not been executed here.

The PHP 8.4 Linux panel-only baseline (empty disposable database, 60 requests)
measured median 6.63 ms, p95 9.30 ms, PHP RSS 19.14 MiB and 0% idle CPU over three
seconds. See 2026-10-31-panel-performance.json. These numbers exclude Windows,
WebView2 and large real databases; they are not a Windows laptop performance claim.
The release workflow tests the supported PHP 8.3 Windows build separately.

Before broad release, run Windows-Smoke.ps1 and windows_startup.ps1 on a disposable
Windows installation, test actual sign-in/startup, dragging/resizing across displays
and DPI settings, Task Manager termination, and a real NIBM OTP session across a
Windows reboot. Run tests/performance.py against the built Windows installer for
whole-app CPU/RAM and startup measurements. No real university login was used here.
