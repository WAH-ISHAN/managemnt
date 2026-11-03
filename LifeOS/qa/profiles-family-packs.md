# Isolated profiles, family access, kid workflow and starter plans

Open **Settings → Modules & language → Profiles & family**. A PIN is required on the current profile before adding another profile. Each new profile starts with empty personal records and its own PIN; select it and enter that PIN to open onboarding. A locked workspace also offers **Switch profile**. PINs cannot be removed while multiple profiles exist.

The existing database, uploads, backups and sessions stay in the original installation data root as `default`. New profiles use generated opaque identifiers under `data/profiles/<id>/`, each with its own database, uploads, backups and PHP sessions. `data/profiles.sqlite` holds the installation registry, guardian consent and policy audit; it contains profile names and hashes are kept in the individual databases. Switching checks the target PIN with a persistent attempt limit, clears the source authentication and creates a fresh target session. Profile names are visible in the local selector; they are not secret identifiers.

An adult can approve a guardian link with both adult and child PINs. The guardian's report reads a separate connection with SQLite `query_only` enabled and returns only the latest 100 task titles/statuses/dates, active habit check-in totals and the latest 30 recorded focus days. It does not expose journals, receipts, transcripts, documents or financial records. Guardian policy changes require the adult PIN again. A child can revoke a guardian's report access from their profile. Consent, report access and policy changes are audited in the installation registry.

Kid mode opens **My day**, with large task-completion buttons, a task form, habits, focus and language choice. Direct requests for settings, restore/export, financial records, documents, automations, APIs, profile administration and task deletion are denied by server policy. The normal planner and capture entry redirect to My day in kid mode. This is an application workflow policy, not Windows-wide parental controls. It does not monitor other applications or restrict operating-system access.

Open **Settings → Modules & language → Preview & install starter packs** to review HND student, Grade 11 and Software engineer plans. Each explicitly installed pack applies its persona and creates three unfinished tasks, two habits, one goal with three steps, and (for HND) three clearly labelled editable subject placeholders. Credits start at zero and grades remain unset. No completed activity, financial transactions or exam dates are generated. Installation is transactional and repeat installation is a no-op. Existing persona selection remains configuration only; adding real records requires the separate preview/install action. Starter labels and inserted records support English, Sinhala and Tamil.

**Ctrl+Shift+Space** opens Quick Capture from other Windows applications while LifeOS is running, including when hidden in the tray. The message-only native hotkey owner records registration/error status in `desktop-panel-hotkey.json`; a shortcut already owned by another application can prevent registration. The route still enforces the selected workspace's PIN and kid policy. Existing panel shortcuts retain their actions.

## Verification

- `php tests/profiles_packs.php`: 40 checks passed for independent data roots, own hashed PIN, rejection of traversal/bad credentials, guardian consent/revoke/report boundaries, kid route policies, real/idempotent starter records and database integrity.
- `python tests/profiles_http.py`: 40 checks passed for actual browser-session switching, profile PIN prerequisites, cross-database task writes, guardian consent/revoke, read-only reports, kid controls, locked-screen selection, repeated pack installation and no PHP errors.
- Existing `php tests/persona.php`: 243 checks passed and `php tests/fresh_workspace.php`: 134 checks passed before concurrent School-module additions; rerun after those changes are integrated.
- Existing `python tests/persona_http.py`: 52 checks passed after profile integration.
- `powershell -File tests/QuickCapture-Hotkey.ps1`: four native checks passed for real OS hotkey registration, capture/panel callback routing, disposal and re-registration.
- Current .NET launcher builds with zero warnings and zero errors.

Tests use disposable data. No installer or release has been published.

## Remaining native acceptance

WebView2 still uses the existing shared installation browser-data directory. PHP sessions and application records are isolated, but separate persisted browser environments for university/external browser sign-in are not delivered by this change. Avoid presenting this as complete browser-profile isolation. Recreating main/panel/university WebViews and transferring profile selection needs a dedicated native lifecycle implementation. Native tray/panel/profile-switch/resume behavior and fluent Sinhala/Tamil wording still require human acceptance. The operating-system user can read the live SQLite files; PINs protect the application interface and do not encrypt live profile data.
