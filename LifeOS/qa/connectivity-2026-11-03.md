# Connections verification — 2026-11-03

Implemented an opt-in Connections module with encrypted external backup scheduling, generic filtered IMAP task previews/acceptance, reviewed ICS exchange, Google/Outlook timed-event adapters, scoped loopback REST/CLI capture, approved macros/scripts and optional Windows foreground-process time capture. Setup and limitations are in [Connections guide](../docs/CONNECTIVITY.md).

Checks run with disposable data only:

| Check | Result |
| --- | --- |
| `php tests/connectivity.php` | 65 assertions passed: device-key secret protection/tamper rejection, bearer-token scopes/revocation, tracking consent/exclusions/deduplication, ICS timezone/text/UID/UTF-8 bounds, one-time email acceptance, daily macros, approved script execution/hash invalidation, calendar conflict/cancellation handling, actual verified external encrypted copy and database integrity. |
| `python tests/connectivity_http.py` | 35 assertions passed using native Windows Python/PHP: browser forms/CSRF, cookie-less API, independent PIN/token authentication, profile and kid isolation, resident bridge/daily macro deduplication, reviewed ICS persistence/download and an actual CLI request. |
| `python tests/connectivity_worker.py` | 6 offline transport tests passed: read-only IMAP contract and identity, timezone conversion, provider URL credential boundary, calendar recurrence-instance fetch/all-day skips/cancellations, conditional writes and repeatable creation IDs. |
| `python tests/calendar_authorization.py` | Real loopback HTTP callback passed: invalid state rejected, valid state exchanged once, PKCE S256 verified and refresh-token result handled without writing credential files. The provider exchange is fake. |
| PHP syntax | Service, ICS, calendar, secrets, routes, views and locale files linted. |
| Python syntax | Optional worker, OAuth helper and CLI compiled. |
| PowerShell syntax | Native Windows PowerShell parser accepted `Track-ActiveWindow.ps1`. |

Source changes are reversible local development work. No installer or release was published. The checks do not sign in to personal accounts, read real mail, modify a real cloud calendar, capture the user's foreground windows or access external USB/cloud devices.

Live Google/Microsoft consent and refresh, provider-specific IMAP app-password/TLS, native Windows capture and USB/cloud-client lifecycle acceptance remain external acceptance. The calendar implementation supports timed events in a bounded period; all-day events are counted and skipped, ICS recurrence requires prior expansion, and local cancellation/deletion is deliberately retained for review instead of sent to a provider. Translation covers this module's main headings/forms/actions; explanatory paragraphs and some validation text still have English fallback. Broader application translation is tracked separately.
