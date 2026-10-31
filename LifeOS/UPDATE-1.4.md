# LifeOS 1.4.0 — 28 October 2026

## Delivered

- A new blue ribbon L with a mint leaf, used on the sidebar, welcome and unlock screens, favicon, Windows app and installer. The artwork was generated with the built-in image generation tool; the saved original and exact prompt are in `public/assets/brand/README.md`. `python scripts/create-icon.py` rebuilds resized assets and the seven Windows icon sizes from that source.
- Short page/dialog transitions, hover and pressed feedback, with reduced-motion support.
- A thin loading bar for page requests that take more than 120 ms, with cleanup after completion, cancellation, abort or failure.
- Protection against accidental duplicate htmx form submissions, preserving submitter values. Native file forms remain usable. Background salary previews leave Save available.
- Typing updates allocation totals only when needed, instead of rescanning charts and navigation for every keystroke.
- Unlock screen applies the selected theme before displaying content.

No new data schema, runtime framework, account or online service is required by these UI changes. No smart AI features are enabled in this release.

## Verification

- PHP syntax: 90 files.
- PHPUnit: 10 tests, 33 assertions.
- Service checks: 45; planner search/navigation: 20; planner/goals/career: 63; daily progress: 21.
- HTTP acceptance: 180 checks, including logo assets and welcome/unlock integration.
- `node tests/ui_lifecycle.cjs`: 73 checks covering duplicate submissions, background previews, overlapping requests, abort/error recovery, and BFCache restoration.
- WebView2 UI preflight: 91 checks, including loaded logos, reduced-motion behavior, single-task creation after repeated submit, loading cleanup and responsive layouts. Light/dark screenshots reviewed, including the 720-pixel planner.

UI tests use disposable databases. Rebuild verification compares the SHA-256 hashes of existing `dist/data` files. The personal installed application has not been upgraded automatically; run `Output/LifeOS-Setup.exe` to install the release. A fresh install/uninstall cycle was not repeated for this release.

## Suggested next additions

1. **Sinhala/English quick capture.** Turn phrases such as `heta 6ta study 45min` or `lunch 850` into a task/appointment/expense preview, allowing corrections before saving. Begin with local parsing; make generative AI optional.
2. **Time-aware weekly planning.** Use task estimates and available hours to suggest slots around classes and appointments, with overlap and overload warnings.
3. **Explainable spending comparisons.** Compare entered spending across equivalent periods and link to the supporting entries. Avoid claims when records are insufficient.
4. **A weekly briefing with evidence.** Combine unfinished commitments, upcoming dates, completed work and money changes into a short review linked to saved records.
5. **Backup health.** Show the latest complete encrypted backup, document coverage and the last successful restore verification.

These are proposed additions, not features delivered in 1.4. Most can work with local PHP/SQLite. Optional local language models depend on the laptop's hardware; see [Microsoft's local LLM overview](https://learn.microsoft.com/en-us/windows/ai/apis/local-llms).
