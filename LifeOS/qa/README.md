# Evidence-based QA playbook

Use this workflow with a human tester, Claude, Codex, or another coding agent. The method is portable; commands below are adapters for this LifeOS repository. Start with [the Sinhala guide](README-si.md) for a short explanation, or give an agent [AGENT-PROMPT.md](AGENT-PROMPT.md).

The central rule is: **every claim needs evidence from the layer where that behavior actually happens.** A successful build proves compilation. A screenshot proves a page rendered at a particular moment. A repayment test proves a specified balance calculation. Each has a different scope.

## Run the LifeOS checks

From the repository root, in PowerShell:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/Invoke-QA.ps1
```

For a desktop change, run the isolated native checks:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/Invoke-QA.ps1 -Desktop
```

The runner saves a fresh evidence directory under `tests/.tmp/qa-<timestamp-id>/`, with `summary.json` and stage logs. `-Desktop` publishes the current native launcher and runs the isolated WebView2 preflight. Read the runner output for the exact screenshot directory. **Screenshots still need visual inspection.** The runner does not install an update or approve a release.

Prerequisites for console checks are PHP 8.3 with the app's required extensions, installed Composer development dependencies, Python 3 and Node.js. Desktop checks also require Windows, the .NET 8 SDK, a working WebView2 runtime, and the app's bundled PHP runtime under `dist/php/`. Read any reported prerequisite failure; a missing runtime is not a passing test. A nonzero setup result is an environment blocker, not evidence that the feature itself is broken; it still prevents QA approval until resolved. `build.ps1` prepares a full distribution when its additional build prerequisites are available. Do not download or install dependencies merely to hide a test failure.

## 1. Turn the request into observable acceptance criteria

Write a small table before testing. Use the user's requested behavior and inspect the implementation to find the affected paths.

| User action | Expected result | What must stay true | Evidence |
| --- | --- | --- | --- |
| Repay Rs. 200.10 on a Rs. 1,000.30 loan | Rs. 800.20 remains | Personal account balances do not change | Service/route test and rendered record |
| Submit Rs. 800.21 against that remainder | Validation error | No payment row or balance change | Negative test and before/after query |
| Open Quick Add, then minimize the app | Native window minimizes | Dialog remains usable after restore | Actual desktop test |
| Install a visual update | New files are installed | Existing records and uploads remain intact | Backup, row/file hashes and installed payload comparison |

Identify the important risks: financial arithmetic, writes, migrations, permissions, asynchronous requests, native window behavior, and appearance. Let those risks decide the tests. A label-only edit usually needs inspection, not a large new test suite.

## 2. Build an isolated test environment

Use a temporary directory and an explicitly selected test database. Seed only the records required by the scenario. Use unmistakable fictional values, not copied personal records.

For LifeOS, the server and launcher honor `LIFEOS_DATA_DIR`. The desktop diagnostic mode requires an explicit data directory. The existing HTTP suite creates a temporary project/database; `Ui-Preflight.ps1` creates a unique directory under `tests/.tmp/`, saves inherited environment variables, and restores them afterward.

Before a test that can write, confirm:

- The resolved database path is the temporary test database.
- The server binds to the intended local address and test port.
- Existing user processes and installations will not be overwritten or force-closed.
- Any cleanup targets are resolved and inside the test directory.

The LifeOS preflight uses a fresh test-data directory and a diagnostic mutex scoped to that directory. It can run alongside the normal LifeOS window without claiming its instance or changing personal records. Closing the normal window voluntarily can reduce resource interference during performance measurements. Keep failed test artifacts long enough to investigate them.

## 3. Check the layers in order

| Layer | What to test | LifeOS examples | What this does not prove |
| --- | --- | --- | --- |
| Syntax and build | PHP/JS parse; native project compiles | `php -l`, `node --check`, `.NET publish` | Correct behavior or good appearance |
| Services and database | Calculations, validation, transactions, persistence | PHPUnit; `tests/finance_planning.php`, `tests/modern_tools.php` | Real browser event handling |
| HTTP integration | Routes, sessions, forms, security boundaries, uploads/exports | `tests/http_acceptance.py` | Native window controls or layout |
| UI logic fixture | Request lifecycle, double-submit guards, loading cleanup | `tests/ui_lifecycle.cjs` | Browser rendering; it uses a small DOM fixture |
| Actual desktop app | WebView2 forms/navigation, native controls, application lifecycle | `tests/Ui-Preflight.ps1` and launcher diagnostics | Every OS/display combination or a complete accessibility audit |
| Visual review | Readability, clipping, alignment, theme consistency | Captured PNGs opened and inspected | Untested interactions or full product completeness |
| Release verification | Tested source/build/installed payload and data preservation | Installer version, SHA-256, backup/verify helper | Every future migration or a signed updater |

Stop optional expansion when the affected behavior is sufficiently verified. A failure, a new code change, or a concrete remaining risk is a reason to add or repeat checks.

## 4. Test outcomes and failure paths

For each important write, test both an accepted operation and a rejected one. Check the stored state, not only the success message.

Useful cases include:

- Normal input, empty input, invalid date, wrong type, missing parent record, and boundary amounts.
- Partial repayment, overpayment rejection, reversal, and correction of a mistaken entry.
- Exact-cent totals and allocation remainders. Use integer cents where appropriate.
- A transaction failing halfway through: the database must retain the expected earlier state.
- Duplicate clicks or repeated processing: one intended write must not create two records.
- Editing/deleting a record through the wrong parent: unrelated records remain untouched.
- Closing and reopening the test database: records persist correctly.
- User text containing HTML: it is displayed as text where required.

Choose expected values independently. A test that calculates its expected answer by calling the same implementation can repeat the same bug.

For HTTP security boundaries, test missing/invalid CSRF tokens, foreign or opaque origins where relevant, unauthorized sessions, inaccessible private files, and accepted ordinary browser form submissions. Test refusals as well as permissions. Synthetic HTTP requests and the real embedded browser can differ; LifeOS previously exposed a normal browser POST rejected because its origin policy produced `Origin: null`.

## 5. Exercise the actual interface

Use actual controls and forms when the tool permits it. A native window test should assert native window state, not just that an HTML click handler ran.

For LifeOS's glass header, the desktop diagnostic checks cover native minimize/maximize/restore, the working-area bounds, eight resize hit-test regions, controls on onboarding/unlock pages, operation while a modal is open, and closing the window through the new control. It also checks that the app's PHP process has exited after window close.

Two bugs can exist even when a page looks correct: a modal can make an HTML titlebar inert, and a child WebView can intercept native mouse behavior. Test those boundaries explicitly. State precisely what was exercised. Eight native hit-test checks do not establish that dragging was manually exercised on every display.

The LifeOS desktop preflight runs disposable forms and visits pages. Its temporary records are not shipped. Desktop diagnostics are appropriate for this app; another app may need a browser runner, mobile emulator, or its own native adapter.

## 6. Inspect screenshots, then iterate

Capture meaningful states, including an empty page, a populated page, a long form/list, a modal, and a validation error where affected. For the LifeOS refresh, captures use widths 1280, 1024 and 720 in light and dark themes. Those widths are examples, not a universal device matrix.

Open the actual images. Check:

- Text contrast and readable labels in both themes.
- Overlap, horizontal overflow, cut-off buttons, sticky headers, and focus outlines.
- Long names and amounts, empty-state layout, field spacing and alignment.
- Modal layering, background blur and controls that remain accessible.
- Navigation at a narrow width and a viewport after scrolling.

Record which image files you inspected and any issue found. Fix a visible defect, capture that state again, and inspect the replacement. Creating PNG files or passing overflow assertions is not visual approval. If the agent cannot view images, report visual review as **not performed** and hand the image paths to someone who can.

For accessibility, keyboard navigation, screen-reader behavior, high contrast and reduced motion need their own relevant checks. CSS fallback rules alone do not certify accessibility.

## 7. Keep the evidence attached to the tested version

Use a new run directory. Keep command lines, exit codes, useful logs, test results and screenshot paths. Do not reuse an old success JSON after a failed or timed-out run.

Record the source revision or source hashes and the built artifact's version/hash. If source changes after a test, rerun the affected checks and rebuild the artifact before releasing. After an authorized install, compare the installed executable/assets with the tested payload. A hash match proves byte identity of those files; it does not itself prove the app works.

Classify results consistently:

| Status | Meaning |
| --- | --- |
| Passed | The stated check ran and its assertion passed, with fresh evidence. |
| Failed | The behavior violated an assertion or the command failed. |
| Blocked | The required environment/tool/prerequisite prevented the check. |
| Not run | The check was deliberately outside this run's scope. |
| Needs visual review | Images exist but have not been inspected. |

Investigate timeouts using progress and failure artifacts. A longer timeout can be justified when extra captures or measured startup work need time; retain the assertions. Do not convert an unexplained failure into a pass by weakening the test or ignoring its exit code.

## 8. Treat installation as a separate release step

The default QA run does not authorize installation, publication or deletion of personal data. If the user has already requested a release/update, use that authorization and carry out the concrete release checks. Otherwise report the reviewable result first.

Before an authorized update, close the app normally and stop other writers. Confirm the actual installation and data directory from the installed app/shortcut, rather than guessing a path. Use the database's consistent backup facility; copying a live SQLite `.db` alone can miss WAL changes.

LifeOS example, from the repository root:

```powershell
$lifeData = Join-Path $env:LOCALAPPDATA 'Programs\LifeOS\data'
$manifest = Join-Path (Get-Location) ('.tools\data-before-update-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.json')
python scripts/verify-update-data.py backup $lifeData $manifest
# Apply the already authorized, verified update here.
python scripts/verify-update-data.py verify $lifeData $manifest
```

The helper creates a SQLite backup using SQLite's backup API and checks the live database's integrity. It records row counts/hashes and upload-file hashes; verification compares existing table and upload entries. It excludes the migrations table and the `recurrence_last_scan` setting. **It does not copy uploaded files into the backup**, so protect uploads separately if an update can affect them. Keep writers stopped while collecting the baseline; the backup and hash snapshot are separate reads. Creating the backup does not establish that a full restore has been tested.

This strict row comparison suits a UI-only release whose data representation should remain unchanged. A schema/data migration needs explicit old-to-new expectations, tests on disposable copies of supported old schemas, and verification of untouched data. Do not simply add changed business tables to an ignore list to make a migration pass. Also test restoration/recovery if rollback is part of the release promise.

For this repository, `tests/Windows-Smoke.ps1` exercises installation/reinstallation/uninstallation under a disposable Windows account. It refuses to overwrite an existing registered personal installation. Keep that guard. It is not the default QA runner.

## 9. Report what you proved

Use a short report with paths to fresh evidence:

```text
Change: [observable behavior and tested source revision/hash]
Environment: [OS/runtime/browser/native host]
Passed: [named checks and counts, plus evidence paths]
Visual review: [exact images inspected and findings, or not performed]
Data safety: [test isolation; release backup/verification if actually performed]
Release: [not installed / installed version and payload verification]
Remaining: [failed, blocked, untested or incomplete requirements]
```

Counts are useful for locating results, not a quality score. A small test that catches a real wrong balance is more useful than many assertions repeating one implementation detail. Never turn “these checks passed” into “all requested features are complete.” Compare the original request with implemented workflows separately.

## Adapting this kit to another project

Keep the method and replace the adapters: test-data location, runtime commands, important invariants, actual UI driver, screenshot matrix, release artifact, backup mechanism and installed payload checks. Read that project's instructions and existing tests first. Do not assume another app understands `LIFEOS_DATA_DIR`, contains these test files, or can be validated with LifeOS's check counts.
