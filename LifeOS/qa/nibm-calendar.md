# NIBM lecture calendar

LifeOS 1.6.1 adds **University → Lecture calendar**. Paste a dashboard URL from `https://aca.mynibm.com/dashboard` with its selected `batch` parameter, save it, then choose **Open NIBM sign-in**. Enter your university email and OTP directly in that NIBM window. Passwords and OTPs are never entered into the LifeOS form or sent to the local PHP server.

The Windows app keeps the university's session in its WebView profile on this laptop. While LifeOS is open and unlocked it checks immediately on connection and every five minutes thereafter. A saved session can resume on subsequent launches; an expired/revoked session needs another university sign-in. Windows startup opens LifeOS at the current user's sign-in. Closing LifeOS stops sync. A PIN-protected app must first be unlocked.

The rolling window is the first day of the previous month through the last day of the sixth upcoming month, in **Asia/Colombo**. The link's originally selected historical month does not freeze this window. The portal's `month=9` represents October because its month parameter is zero based. Older imported records remain in history; dates outside the rolling window are not continuously refreshed.

Approved/scheduled lectures become planner appointments with a 15-minute reminder. `lectureId` plus batch identifies a lecture, so later time, room and title changes update that appointment. Confirmed cancellations, pending/rejected records and absence from a successfully validated complete snapshot stop the associated reminder. An HTTP error, expired login or malformed snapshot leaves saved lectures unchanged. Unrelated appointments are not modified. Editing synced appointments locally can be overwritten by the next sync; edit the NIBM calendar upstream. Deleting a synced appointment locally allows it to be recreated on the next sync.

**Pause sync** stops future imports while preserving existing appointments and reminders. It does not sign you out of NIBM. Changing the selected batch cancels the previous batch's synced appointments and retains their history. No sample batch or university credentials are installed for new users. NIBM lecture EXAM and COURSE_WORK entries remain calendar appointments; no grades, assignment marks or university modules are invented from them.

## Protocol and boundaries

This adapter uses the university frontend's existing read API; no ICS feed was found in the public dashboard assets inspected on 2026-10-30. This is an observed implementation, not a published stable API contract. Upstream API or sign-in changes may require an adapter update.

Public references inspected:

- [NIBM calendar](https://aca.mynibm.com/)
- [Public API client](https://aca.mynibm.com/_next/static/chunks/087odk4k3wx82.js)
- [Public dashboard mapper](https://aca.mynibm.com/_next/static/chunks/16moh~jc4nvz1.js)

The fixed API base is `https://qoo15vk3ac.execute-api.ap-southeast-1.amazonaws.com/prod`. Lecture reads use `GET /schedule-service/lectures` with batchId/startAt/endAt and the university session's bearer token. A 498 response invokes the university's refresh endpoint once. Tokens remain in the NIBM origin context, including during refresh. The native bridge copies only whitelisted lecture metadata to LifeOS through its authenticated, CSRF-protected local import route. Remote navigation is restricted to the exact NIBM HTTPS origin; popups, downloads and permission requests are blocked. No university records are created or edited.

Snapshots are bounded to 2,000 lectures, 1.5 MiB of lecture JSON and 400 local calendar days. Unsupported statuses, wrong batches, duplicate IDs and invalid dates reject the entire snapshot. A JSON object is never interpreted as an empty calendar. Calendar data participates in ordinary database backups; WebView login state does not participate in encrypted complete backups.

## Verification

`tests/uni_calendar.php` covers identity, duplicate prevention, timezone conversion, date changes, cancellation, snapshot validation, atomic failure, pause and SQLite integrity. `tests/uni_calendar_http.py` checks the actual routes, CSRF/Origin/PIN guards, output escaping and the malformed-object regression. The native bridge fixture and desktop preflight cover their named layers separately. Fresh overall results are in the dated QA report; an authenticated live NIBM sync requires the user's own email/OTP and is not established by synthetic tests.
