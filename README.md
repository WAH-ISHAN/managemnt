# Crab

Crab is a local Windows PHP application foundation. The business modules can be added under `app/`. Each push to `main` runs GitHub Actions and publishes a Windows package as a GitHub Release.

## Development

With PHP 8.3 or newer installed:

```powershell
php -S 127.0.0.1:8751 -t app/public
```

Open `http://127.0.0.1:8751/`.

## First release and installation

1. Create a **public** GitHub repository for this project and push it to `main`.
2. Confirm the `Release Crab for Windows` GitHub Action succeeds. Its `GITHUB_TOKEN` needs `contents: write`, which the workflow requests.
3. Download and run `Crab-Setup.exe` from the latest GitHub Release. It installs Crab in `%LOCALAPPDATA%\Crab` and creates a Start menu shortcut to `Crab.exe`.

The release also contains `crab-windows.zip` for manual installation. Extract it and run `./Install-Crab.ps1` in PowerShell.

The installer also registers a per-user hourly Windows task called `Crab Update`. Each launch checks for updates as well. The updater reads the latest public GitHub Release, verifies the package against GitHub's SHA-256 asset digest, and installs it in a separate version folder. The new version becomes active on the next launch. If the computer is offline, the installed version continues to work and a later check retries. Update errors are written to `%LOCALAPPDATA%\Crab\update.log`.

Releases currently require a public repository. A private repository would need authenticated downloads without storing a shared GitHub token in every installation. This foundation does not yet provide that service.

App data should be stored outside `versions/`, under `%LOCALAPPDATA%\Crab\data`, so updates do not overwrite user data.
