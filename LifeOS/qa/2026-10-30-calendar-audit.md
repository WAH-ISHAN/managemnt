# NIBM calendar audit — 2026-10-30

## Acceptance and results

| Action | Expected behavior | Evidence |
| --- | --- | --- |
| Import a confirmed lecture | One planner appointment using Sri Lanka time and a reminder | Service and HTTP suites passed |
| Import the same snapshot again | Preserve identity without duplicate appointment or notification | Service, HTTP and native bridge fixture suites passed |
| Import a changed or cancelled lecture | Update the linked appointment or stop its reminder | Service and HTTP suites passed |
| Reject an invalid snapshot or failed connection | Preserve saved lectures and unrelated appointments | Service and HTTP suites passed |
| Select another batch, then return and sync unchanged lectures | Restore the original appointment to scheduled | New service regression failed before the fix and passed after it |
| Start from the old schema | Preserve all pre-existing records and create empty calendar tables | Upgrade preservation suite passed |

The batch-switch regression found that selecting another batch cancelled local appointments but retained the remote snapshot hash. Returning to the original batch then skipped unchanged lectures, leaving their reminders cancelled. Import now also checks the linked appointment's local status and restores a confirmed lecture to scheduled. It preserves the appointment ID and generates one update notification; repeating that sync generates no further notification.

## Fresh verification

- `php -l app/Modules/Uni/src/UniCalendarService.php`: passed.
- `php tests/uni_calendar.php`: **93 checks passed**.
- `php tests/uni_calendar_upgrade.php`: **33 checks passed**.
- `python tests/uni_calendar_http.py`: **50 checks passed**.
- `node tests/nibm_bridge.cjs`: **176 checks passed** using fictional credentials and no real network.

Tests use in-memory or disposable databases. The upgrade suite uses a disposable old-schema fixture. These checks do not establish that the user's university login succeeds or that the upstream API currently accepts their session.

## Installed instance observation

Read-only inspection of the running installed **1.7.0** instance at `%LOCALAPPDATA%/Programs/LifeOS` found:

- Calendar enabled and a batch already configured.
- No successful sync timestamp.
- Stored status: **Sign in to NIBM to continue syncing.**
- **0 imported lectures** and **0 linked scheduled lectures**.

The user's actual calendar therefore had not imported at the time of inspection. They need to open **University → Lecture calendar → Open NIBM sign-in**, complete their email/OTP login in the NIBM window, and select **Sync now**. While the Windows app is open and unlocked, subsequent checks run every five minutes. Credentials and OTP were not accessed, and no personal records were modified by this audit.

The source fix described above is separate from the installed executable/payload observation. Installation of the changed service is not established by this report. Authenticated live NIBM sync and calendar-page visual review were not performed in this audit.
