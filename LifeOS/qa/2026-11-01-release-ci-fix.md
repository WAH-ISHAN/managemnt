# Release CI: timezone and missing QA artifacts

Reproduced the reported HTTP acceptance failure before changing the test:
`AssertionError: Daily review rejects invalid and invented future activity dates`.
PHP explicitly uses Asia/Colombo, while Python's naive datetime.now() used the
runner timezone (UTC on GitHub-hosted Windows). Between 18:30 and 23:59 UTC,
UTC tomorrow is already today in Colombo, so a valid date was incorrectly
expected to return HTTP 422. The same mismatch affected current-day fixtures and
crossed month boundaries.

HTTP acceptance dates now use UTC+05:30 explicitly, matching the app without
requiring the optional Windows Python tzdata package. The separate desktop-panel
HTTP suite uses the same timezone for its current-day task fixture. No app
validation was relaxed and no tests were skipped.

QA artifacts were missed because actions/upload-artifact excludes hidden paths
by default; the evidence lives under tests/.tmp. The workflow now explicitly
includes hidden files, scoped to the QA evidence glob under github.workspace.
The runner also prints the last 60 lines of a failed stage log, so the actual
traceback is visible even if artifact upload fails.

Validation on 1 November 2026 (Colombo):

- Confirmed the original HTTP failure under a UTC host clock before the fix.
- All 228 HTTP acceptance checks passed with PHP 8.3.6 and TZ=UTC after the fix.
- Full scripts/Invoke-QA.ps1 run passed with PHP 8.3.6 under UTC, including
  PHPUnit, every service/HTTP/Node stage and source-stability verification.
- All 24 desktop-panel HTTP checks passed under PHP 8.3 and UTC.
- Intentionally missing PHP prerequisite returned exit code 1 and printed the
  actual FAIL log line, verifying the diagnostic failure path.
- PowerShell syntax validation and git whitespace checks passed.

Full passing evidence is under tests/.tmp/qa-20261031-184248-bed0c25e in this
workspace. It is generated data, not committed application content. Tests used
only disposable databases. This was Linux verification with the supported PHP
8.3 series; Windows installer execution and the next hosted Actions run still
need to complete on Windows. The branch also retains the updater payload and
release identity packaging corrections needed by the Windows release job.
