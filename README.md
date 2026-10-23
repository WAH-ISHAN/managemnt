# Crab

Crab is a local Windows management app built with PHP and SQLite. It includes daily tasks, a calendar, exams, targets, points, salary and expense tracking, and a recovery view for missed or failed work. Every item and money entry supports create, view, edit, and delete. The interface bundles Anime.js and Three.js effects and remains usable without WebGL or an internet connection.

The first registered account becomes the administrator. Later accounts start with modules locked. The administrator can unlock or lock each module for each user in **Admin center**. Records are scoped to their owner. User data is stored in `%LOCALAPPDATA%\Crab\data\crab.sqlite` and is kept outside version folders.

## Development

With PHP 8.3 or newer installed:

```powershell
php -S 127.0.0.1:8751 -t app/public
```

Open `http://127.0.0.1:8751/`.

## First release and installation

1. Push this project to the **public** `WAH-ISHAN/managemnt` GitHub repository on `main`.
2. Confirm the `Release Crab for Windows` GitHub Action succeeds. Its `GITHUB_TOKEN` needs `contents: write`, which the workflow requests.
3. Download and run `Crab-Setup.exe` from the latest GitHub Release. It installs Crab in `%LOCALAPPDATA%\Crab` and creates a Start menu shortcut to `Crab.exe`.

The release also contains `crab-windows.zip` for manual installation. Extract it and run `./Install-Crab.ps1` in PowerShell.

The installer also registers a per-user hourly Windows task called `Crab Update`. Each launch checks for updates as well. The updater reads the latest public GitHub Release, verifies the package against GitHub's SHA-256 asset digest, and installs it in a separate version folder. The new version becomes active on the next launch. If the computer is offline, the installed version continues to work and a later check retries. Update errors are written to `%LOCALAPPDATA%\Crab\update.log`. Back up `%LOCALAPPDATA%\Crab\data` to preserve accounts and records.

Releases currently require a public repository. A private repository would need authenticated downloads without storing a shared GitHub token in every installation. This foundation does not yet provide that service.

## Local build and checks

```powershell
npm ci
npm run build:visuals
python tests/smoke.py
./scripts/Build-Local.ps1
./tests/Install-Smoke.ps1
python tests/update_smoke.py
```

The local build produces `dist/Crab-Setup.exe` and `dist/crab-windows.zip`. Local packages use version `v0-0000000`; the next GitHub release replaces it automatically. The executable is unsigned, so Windows may show its normal publisher warning until a code-signing certificate is configured.
