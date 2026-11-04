# Windows close, taskbar and exit repair

Recovered the pending close/taskbar request from session `01a1a495-8f3e-7003-80cc-f6cff2c8e0cd`.

The previous tray path changed `ShowInTaskbar` while cancelling a close, recreating the main HWND and its WebView parent on each hide/reopen. The repair cancels the close first, hides the windows, and updates the hidden main window to `WS_EX_TOOLWINDOW` without recreating its HWND. Reopening restores the normal application-window style. Startup, university, desktop and assistant windows hide with the main window. This follows [Windows taskbar style guidance](https://learn.microsoft.com/en-us/windows/win32/shell/taskbar#managing-taskbar-buttons).

The university window also cancelled owner-initiated closes, which could prevent the tray's **Exit LifeOS** command from disposing the main window. It now cancels only its own user-close action. Main-window tray hiding retains its university browser; an actual application exit disposes it.

## Verification

- 29 isolated native checks passed against the current source: normal/minimized/maximized close, native visibility and taskbar styles, stable HWNDs, university-window preservation, glass close, reopen, and tray exit with the university window present. Evidence: `tests/.tmp/resident-close-after-4/result.json`.
- Two native browser runs passed 64 checks each, now exercising the real glass close action and verifying that the live WebView survives reopening. These also covered profile isolation, assistant/desktop panels, resizing and simulated lock. Evidence: `tests/.tmp/browser-profiles-9530ef9578a84b15bde1cace317891eb`. Global shortcut registration/dispatch was skipped in those isolated runs because the installed app owned the shortcuts.
- 33 conversation/loading/error/reviewed-action/privacy/keyboard browser checks passed using fixtures. Evidence: `tests/.tmp/roles-ai-ui`. No new live AI requests were needed for this repair.
- The native regression is included in `scripts/Invoke-QA.ps1 -Desktop`. It can also be run from `LifeOS` with `.tools/dotnet/dotnet.exe run --project tests/resident-lifecycle/ResidentLifecycle.csproj -c Release -- tests/.tmp/resident-close-manual`.

## Installed repair

The laptop was still running personal LifeOS 1.9.3. A separate repair binary was built from the repository's committed 1.9.3 launcher (`9d64a8e`) with only the residency, window-style and university-close fixes. This avoids installing the pending 1.9.5 account/activation work or other workspace changes into the personal installation. The matching repair build passed the same 29 native checks: `tests/.tmp/resident-installed-release/result.json`.

The installed launcher shut down gracefully, was backed up, replaced and restarted. Its file version is `1.9.3.1`; SHA-256 is `efcc5d7b6d94c95756f8f4654ad9ae4b1b3e6135249b94e014c976eea071731e`. Original executable: excluded `.tools/resident-repair-20261104/LifeOS-original-1.9.3.exe`, SHA-256 `eb8bb9c4b2acf4a6a79b893c4d95e0e794a72bb7ae085d955eb9eae0acc56bb8`.

Seven checks passed against the actual repaired installed process: opening, native Close, no remaining visible application/taskbar window, hidden tool-window style, single-instance reopening of the same HWND, restored taskbar style and hiding again. Evidence: `tests/.tmp/resident-installed-probe/installed-result.json`. Installed state reported the main window hidden, tray visible, PHP alive, and all global shortcuts registered without errors. The application was left hidden in its tray, matching its state before repair.

Only the installed `LifeOS.exe` changed. Personal records, PHP/application files, credentials, AI runtime and model files were not replaced. The online 1.9.5 installer and local distribution payload were not rebuilt or published in this repair. A future full build includes the source fixes. Native window/style checks are recorded here; no new physical taskbar, keyboard or sleep/resume inspection is claimed.
