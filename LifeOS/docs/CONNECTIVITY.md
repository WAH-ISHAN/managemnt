# Connections & automation

This module adds opt-in local adapters. Open **Settings → Modules & language** and enable **Connections & automation** if your persona does not show it. Configure it at `/connectivity`. All scheduled connections begin disabled. The resident desktop process must be running; suspended or stopped computers cannot execute jobs. One connection job or one pending macro runs per tick, with a per-profile lock, recorded attempts and visible failures. No historical missed days are replayed.

## Encrypted external backups

Choose an existing writable folder outside the current profile data directory, such as a USB drive or a locally synced OneDrive/Google Drive folder. Enter a recovery passphrase of at least 12 characters and keep a separate copy of it. The existing authenticated complete-backup format includes the SQLite snapshot and uploaded documents. The destination copy is written to a temporary file, compared by SHA-256 and then renamed. Retention only removes files created for this profile; the minimum retained count is two. A missing drive produces a visible failed run, and can be retried manually or at the next scheduled day.

Saved credentials and the recovery passphrase use AES-256-GCM with a random per-profile device key in `.connectivity-key`. The key is not included in database/document backup payloads. A backup restored to another device therefore requires credentials to be entered again. The device key is a local operating-system file, not a Windows Credential Manager vault; anyone with full access to the data directory can also read its key. The app PIN is an interface lock and does not encrypt local records. The backup recovery passphrase still unlocks the archive independently of the device key.

The module writes to a local folder. It does not perform a cloud-provider upload or confirm cloud replication. Configure the provider's own sync client for cloud folders.

## IMAP email → task

Install Python 3 and configure the absolute executable path, verified-TLS IMAP host/port, mailbox, username and app password. A nonempty subject filter is required; an optional sender filter further narrows results. The Python standard library handles TLS certificate verification, Unicode email headers and text decoding. The adapter reads the latest 50 matching UIDs and caps each fetched body at 128 KiB. UIDVALIDITY, server, username, mailbox and UID form the deduplication identity.

Polling creates previews. Accepting a preview creates one linked Planner task; repeated acceptance returns that existing task. Ignoring a message retains its identity so future polls do not resurrect it. Messages are not deleted or marked read. This is generic task email capture, with no bank-alert parser or bank connection.

## Calendar exchange and providers

ICS import has a preview/confirm workflow, UID-based updates, UTC/TZID conversion to the LifeOS Asia/Colombo timezone and UTF-8-safe 75-octet export folding. Individual timed events are supported. Import rejects recurrence properties and all-day values explicitly; expand them to timed instances first. Imports are limited to 500 events and 2 MiB. Export contains up to 5,000 saved appointments.

Google and Outlook adapters use the providers' calendar APIs with bounded pagination. Calendar sync imports timed instances from 30 days ago to 365 days ahead. All-day events are counted and skipped. Enable publishing explicitly to export new scheduled local appointments and local edits. New events contain no attendee invitations. Conditional `If-Match` updates detect concurrent remote changes. When both sides changed, neither record is overwritten; the run reports a conflict. Remote cancellations retain a cancelled local appointment. Local deletion/cancellation is not propagated automatically. Reconcile conflicts in the source calendar/Planner before trying again.

Register your own OAuth desktop/public client with the provider. The application does not ship shared credentials or silently authorize an account. Use a separate personal calendar first:

```powershell
python scripts/authorize_calendar.py --provider google --client-id YOUR_DESKTOP_CLIENT_ID
# or
python scripts/authorize_calendar.py --provider outlook --client-id YOUR_PUBLIC_CLIENT_ID
```

The helper opens the system browser and binds only `127.0.0.1:8766/callback`; register that loopback redirect in your provider app. A different `--port` can be supplied when needed. Authorization uses one-time state and PKCE S256, expires after four minutes, and refuses token redirects. Google desktop credentials may require their client secret, which is requested interactively. The helper writes the resulting refresh token to the terminal once and does not create a token file. Save the client ID, refresh token, and Google's desktop client secret in Connections; then clear the terminal. Microsoft organizational accounts may require `--tenant YOUR_TENANT_ID` and the matching tenant setting in Connections. Provider consent/admin policies still apply.

Access tokens refresh automatically, and rotated refresh tokens are saved encrypted. Replacing the calendar provider or client ID clears old calendar credentials. A manually supplied short-lived access token is also supported until expiry. Revoked/expired refresh authorization requires running the helper again. The local API and capture clients do not expose provider tokens.

Provider contracts: [Google events list](https://developers.google.com/workspace/calendar/api/v3/reference/events/list), [Google calendar API](https://developers.google.com/workspace/calendar/api/v3/reference), [Google OAuth desktop loopback guidance](https://developers.google.com/identity/protocols/oauth2/resources/loopback-migration), [Microsoft authorization code flow](https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow), [Microsoft calendar view](https://learn.microsoft.com/en-us/graph/api/user-list-calendarview?view=graph-rest-1.0).

## Scoped local REST API, webhooks and CLI

Create a token in Connections. The full bearer token appears once; only its SHA-256 is stored. It expires after 90 days and can be revoked immediately. Permissions are `tasks.read`, `tasks.write` and `time.write`. Tokens are independent of the app PIN and scoped to one profile database. Kid profiles cannot use the API. Browser-origin requests are rejected, even if they contain a valid token. The server remains bound to loopback; no LAN listener is added.

| Method | Path | Permission | JSON payload/result |
| --- | --- | --- | --- |
| GET | `/api/v1/tasks` | `tasks.read` | `{ "tasks": [...] }`, newest 200 saved tasks |
| POST | `/api/v1/tasks` | `tasks.write` | `{ "title": "Call amma", "due": "2026-12-05" }` → `{ "id": 123 }` |
| POST | `/api/v1/tasks/123/complete` | `tasks.write` | `{}` → `{ "ok": true }` |
| POST | `/api/v1/window-time` | `time.write` | `{ "process": "Code", "category": "Work", "seconds": 15, "identity": "unique-event-id" }` |

Send `Authorization: Bearer TOKEN` and `Content-Type: application/json`. Payloads are capped at 64 KiB. For an isolated profile, include `X-LifeOS-Profile: GENERATED_PROFILE_ID`; omitting the header selects the legacy `default` profile independently of browser cookies or the resident active profile. The profile ID and actual running API base URL are shown in Connections. An n8n HTTP Request node or local script can POST to the task endpoint as a webhook. No generic remote-command or unrestricted database API is provided.

```powershell
$env:LIFEOS_API_TOKEN = 'TOKEN_FROM_CONNECTIONS'
$env:LIFEOS_API_URL = 'http://127.0.0.1:8787' # use the actual running desktop port
python scripts/lifeos_cli.py tasks
python scripts/lifeos_cli.py add "Call amma" --due 2026-12-05
python scripts/lifeos_cli.py complete 123
# Optional: --profile GENERATED_PROFILE_ID or LIFEOS_PROFILE environment variable.
```

## Macros and scheduled scripts

Actions are a JSON list of up to ten steps. New macros are paused unless their daily schedule is selected. Manual runs are intentional new executions. Scheduled runs have one claimed slot per date and are never retried automatically after failure:

```json
[{"type":"task","title":"Review today's priorities","due_days":0}]
```

An advanced script action is `{"type":"script","path":"C:/my-scripts/review.py"}`. Only existing `.py` and `.php` files are supported. Explicit script approval is required when the macro is saved. Approval stores the file's SHA-256; edits invalidate it. Remove/recreate the macro to approve a changed script. Process execution uses an argument array with shell bypass. The entire macro has a 20-second run budget; each child process has a 128 KiB output limit. Python scripts use the configured Python executable; PHP scripts use the app's PHP runtime.

Scripts execute with the current OS user's permissions. They are not sandboxed. A script failure may follow successful earlier steps or irreversible actions performed by the script itself. History records completion/failure, and rerunning is a deliberate action. Do not put arbitrary scripts in ordinary reminder actions.

## Active-window application time

Enable application time capture and create a `time.write` token, then run:

```powershell
$env:LIFEOS_API_TOKEN = 'SCOPED_TOKEN'
./scripts/Track-ActiveWindow.ps1 -BaseUrl http://127.0.0.1:8787 -Category Work
```

The terminal clearly indicates capture is on; Ctrl+C stops it. The script samples the foreground process, never window titles or keystrokes. It skips intervals where the window changed, where the computer was idle for more than a minute, or where sleep extended an interval beyond five minutes. Server-side exclusions apply before saving. Unique event IDs prevent duplicate time ingestion. Configured retention is applied when saving settings and ingesting events. Connections provides pause/configuration and delete-all controls. This is application/category time, not a claim of precise billable project time or an OS screen-time restriction.

## Verification and remaining acceptance

Automated disposable checks are in `tests/connectivity.php`, `tests/connectivity_http.py`, `tests/connectivity_worker.py` and `tests/calendar_authorization.py`. The OAuth callback test uses a real loopback HTTP listener and a fake token provider; calendar/IMAP transport contracts are mocked. No tests sign in to a real account or use personal mail/calendar data.

Real-provider login/refresh/permission acceptance, live IMAP TLS/app-password acceptance, native Windows active-window capture and USB/cloud-client lifecycle acceptance still require configured external accounts and devices. The module is not a claim of those external acceptance results. No installer or release was published by this work.
