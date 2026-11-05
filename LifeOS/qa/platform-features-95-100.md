# Platform features 95–100

**1.9.6 update:** opt-in automatic full-workspace snapshot delivery and reviewed replacement are now implemented separately from the manual task merge described below. See [workspace exchange](../docs/WORKSPACE-EXCHANGE.md) for sharing scope, retained local credentials, paused jobs, recovery, tests and the distinction from concurrent field-level merging.

This slice is implemented at `/platform` (enable Devices & privacy in Modules & language). It does not publish a release or install over a personal copy.

## 95: local phone companion

`scripts/mobile_companion.py` runs an explicitly configured HTTPS listener on a specific private LAN IPv4 address. It forwards only scoped task requests to LifeOS's loopback API, keeping the main PHP server loopback-only. Set `LIFEOS_MOBILE_TOKEN` to a `tasks.read`/`tasks.write` token from Connections; enable Connections, Planner and Devices & privacy for the selected profile.

Example (replace addresses/port/certificate paths):

```powershell
python scripts/mobile_companion.py --listen 192.168.1.10 --local-url http://127.0.0.1:8787 --cert C:/LifeOS-TLS/server.pem --key C:/LifeOS-TLS/server-key.pem
```

Supply a TLS certificate trusted by the phone, with the chosen LAN address in its SAN. The listener prints a random five-minute, single-use pairing code. An authenticated Secure/HttpOnly/SameSite cookie expires after twelve hours; listener shutdown revokes sessions. Requests enforce Host, private peer addresses, same-origin POSTs, size limits and scoped tokens. The browser caches only the app shell through its service worker. Task snapshots and unsynced captures stay in phone local storage until explicitly erased. Offline retry uses a stable UUID and a transaction-bound desktop receipt to prevent duplicate writes. Server revocation cannot erase already cached phone data. The PWA is a task companion, not remote desktop access or a full copy of every LifeOS module.

## 96: encrypted device task sync

Pair public keys and compare SHA-256 fingerprints through a trusted channel. X25519/XSalsa20-Poly1305 authenticated boxes encrypt each packet for its recipient and authenticate the paired sender. Private device keys remain outside the database. Recovery downloads use Argon2id-derived keys and authenticated secretbox encryption; recovery verifies the original fingerprint. Revoked devices and replayed/tampered packets are refused.

Download a packet, transfer it using your chosen file transport, paste it on the paired device, inspect all conflicts and confirm the merge. Three-way comparisons retain local edits; conflicting changes/local deletion require an explicit local/remote decision. Missing remote rows never delete local data. Version 1 synchronizes up to 1000 standalone Planner tasks (title/date/status); financial records, credentials, documents, attachment files and private mood records are excluded. It does not copy an active SQLite file or claim whole-workspace or automatic background synchronization.

## 97: Windows Hello and auto-lock

Set a LifeOS profile PIN before enabling Hello or auto-lock. Hello consent uses the desktop UserConsentVerifier interop API on Windows 11. A process-only bridge key authenticates a short-lived, single-use challenge bound to the selected profile/session. Browser input cannot manufacture a success proof. The profile PIN remains available if Windows Hello is absent/cancelled. Enabling Hello permits the current Windows account to unlock that opted-in profile; do not enroll profiles that require separation from other people using the same Windows account.

The main UI locks on inactivity, and the server refuses expired PIN sessions even when the client timer was paused. The desktop runtime still needs human verification for actual biometrics, hidden/tray/panel/resume scenarios and visual/privacy behavior. Compilation is not biometric acceptance. The scoped mobile API remains separately authorized by its revocable token.

## 98: portable task imports and exports

CSV/Notion/Todoist task exports and first-sheet XLSX values import through a visible preview and explicit confirmation. Accepted headings are title/name/content and optional due/due date/date. Dates must be ISO, formulas must be replaced with values, and imports are bounded to 1000 tasks/2 MiB. Repeated identical imports are refused. Imported tasks start open; no completion history is invented. XLSX, CSV and JSON task exports are available. CSV neutralizes formula-leading characters and XLSX writes inline strings. These adapters do not silently import every Notion database property or every Todoist project feature.

## 99: community plugin trust

Trust an Ed25519 publisher key explicitly, upload the ZIP and its detached base64 signature, inspect the manifest/hash, then separately approve code execution. ZIP paths, symlinks, size/file-count bounds, duplicate module IDs and route collisions are checked. Payloads stage outside module discovery and activate by directory rename. The module requires a unique ID/name/route in its manifest. Publishers sign the exact ZIP bytes. Trust is full local PHP/migration trust, not a sandbox or security certification. Revoke future publisher trust and disable installed community code separately; disabling preserves records/migrations. Keep a complete backup before installing trusted code.

## 100: history, undo and privacy

Planner task title/date edits and LifePlanning contact edits have database-triggered history and conflict-checked undo. School field edits have review history; corrections use School tools to preserve references/XP. Import batches can be undone only while every imported record remains unchanged. Finances retain their explicit domain reversal workflows. Audit events cover pairing/revocation, plugin trust/install/disable, import/undo and security preferences. The privacy page explains retained local contents, transfer scope and history clearing. This is scoped cross-module history, not arbitrary rollback of all financial transactions or files.

Verification: `php tests/platform.php`, `php tests/school.php`, `php tests/school_guardian.php`, `.tools/dotnet/dotnet.exe build launcher/LifeOS.csproj -c Release`. Actual trusted-certificate phone pairing and Windows biometric confirmation require the corresponding devices and user interaction.
