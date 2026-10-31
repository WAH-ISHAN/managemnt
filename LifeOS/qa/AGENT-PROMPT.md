# Copy-paste QA task for an AI agent

Attach this prompt and `qa/README.md` to the project. Fill in the bracketed fields. It is intended for any coding agent with the necessary project tools; it does not depend on a particular model or provider.

```text
Perform evidence-based QA for this project and fix defects within the authorized scope.

Project: [path / repository]
Change to validate: [specific feature or release]
Acceptance criteria: [observable user actions and expected results]
Constraints: [platforms, offline/privacy requirements, accessibility, etc.]
Release authorization: [QA only / already authorized to install this update at this location]
Real data location: [known location, or discover it read-only before any release action]

Read project instructions and qa/README.md first. Inspect the implementation and
existing tests. Identify the behavior, data invariants and failure risks affected
by this change. Do the work; do not stop after writing a test plan.

Use a fresh temporary database/profile for test writes and fictional fixtures.
Resolve and verify paths before cleanup. Preserve personal data and existing
installations. Do not run destructive lifecycle tests against the personal copy.
Do not install/publish unless that release action is already authorized above or
elsewhere in the current session.

Run the relevant checks in layers:
1. Syntax/build checks for changed code.
2. Service/database tests for calculations, validation, transactions and persistence.
3. Relevant HTTP/integration checks for routes, forms and security boundaries.
4. Actual browser/native UI flows for behavior that happens there.
5. Capture and inspect screenshots for affected states/themes/sizes.
6. If release is authorized: consistent backup, verified artifact installation,
   comparison of installed payload, and data preservation checks.

For money/data writes, cover a normal case, a rejected case and the resulting
stored state. Check exact cents, duplicate submissions, reversals, parent scoping
and rollback where relevant. Derive expected values independently.

For desktop chrome, use actual native window state assertions: minimize,
maximize/restore, working-area geometry, resize edges and clean process exit.
Include startup/unlock and open-modal states if they have separate UI shells.
Clearly distinguish a mocked DOM test from the actual rendering/native host.

Open the generated images. Inspect readability, contrast, clipping, overflow,
alignment, layering, focus, long content and responsive navigation. Record the
specific image paths reviewed. If image viewing or an actual UI environment is
unavailable, mark that check blocked/not performed. Do not claim visual approval
from file creation, console results or overflow assertions alone.

Fix concrete failures and rerun the affected checks. Keep fresh failure evidence.
Use timeouts with progress evidence; do not weaken assertions to get green.
After the required checks pass, stop optional expansion unless a concrete risk
remains. If source changes after testing, rebuild and repeat the affected checks.

Keep a new evidence directory containing commands, exit codes, useful logs,
results, screenshot paths and the tested source/artifact identity. Treat missing
prerequisites as blocked, unexplained errors as failures, and omitted checks as
not run. Do not reuse stale success output. Avoid storing secrets or real user
records in shareable logs or screenshots.

For any authorized upgrade, stop writers normally; use the database's consistent
backup API and protect uploaded files. Compare existing records/files after the
upgrade. A data migration requires explicit transformation expectations, not a
blanket hash-ignore rule. Preserve a usable recovery path.

Finish with:
- What changed and the acceptance criteria covered.
- Tests actually run, results and evidence paths.
- Images actually inspected and visual findings.
- Data protection and release verification actually performed.
- Remaining failures, blockers, untested behavior and incomplete requirements.

Never equate a successful build or a large assertion count with complete QA.
Never claim every requested feature is implemented without a feature audit.
```

## LifeOS adapter

For this repository, add the following to the prompt:

```text
Run scripts/Invoke-QA.ps1 from the repository root for console checks.
For a desktop change, run the same script with -Desktop. The desktop path
publishes the current launcher and uses an isolated data directory and diagnostic
mutex through tests/Ui-Preflight.ps1; it does not close the normal LifeOS instance.

Read the fresh tests/.tmp/qa-*/summary.json and stage logs. Locate the native
preflight's screenshots from its output and inspect affected ones yourself.
The runner does not perform installation or visual approval.

Use scripts/verify-update-data.py backup/verify only for an authorized update,
with the real data path confirmed. Read the limitations in qa/README.md.
Do not run tests/Windows-Smoke.ps1 against a registered personal installation.
```

## Suggested final evidence format

```text
Change / tested version:
Environment:
Passed checks (with logs/results):
Failed or blocked checks:
Images inspected / findings:
Data safety evidence:
Release status and installed payload identity:
Remaining acceptance gaps:
```
