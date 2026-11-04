# Installed AI worker repair — 2026-11-04

The installed 1.9.3 worker had reverted to the older configuration with global/agent catch-all permissions and a one-step agent limit. Source already contained the corrected native tool-denial policy. Installed jobs from November 3 at 20:20–20:21 recorded timeout and quota failures. Comparing actual files established deployment drift; the later successful probes do not imply that real service quotas can be bypassed.

Copied the existing corrected `scripts/opencode_worker.py` to the installed application's scripts directory and `dist/scripts`. All three now have SHA-256 `ECF89F587053AE660CCC4FA6F09F2313F4BD30A423A31F259EC99E1ABCE14536`. Original installed/distribution workers are backed up under excluded `.tools/ai-worker-repair-20261104-085639`. The personal database hash was unchanged across the repair. No runtime, weights, credentials or packages were installed; no cooldowns or personal conversations were reset. Each new request starts a worker, so this repair needs no application restart.

Verification:

- 66 offline worker contracts and 24 provider checks passed.
- 33 browser workflow checks passed, covering conversation, loading/error rendering, reviewed actions, privacy, Enter/Escape and compact layouts. Loading, error and review screenshots were visually inspected.
- The installed worker returned uncached `OK` and a Sinhala greeting using two automatically selected verified zero-cost models, empty history and empty record context. Evidence: excluded `tests/.tmp/ai-repair-e722fac17e374938a4c09d32974471a4`.
- The actual native composer returned `OK` in 8.95 seconds with only the synthetic question. Screenshot inspected. Evidence: excluded `tests/.tmp/browser-profiles-82cc43b182e84bdbb9d5aa6ce2183498`. That overall run then failed because the running installed app owned the global shortcut.
- A subsequent native run hit an intermittent local-worker probe timeout. A final offline two-launch run passed 60 checks per launch, including profile isolation, panel hide/reopen/resize and simulated lock. Global shortcut registration/dispatch was explicitly skipped because the running app owns it; no new physical keyboard or sleep/resume verification is claimed. Evidence: excluded `tests/.tmp/browser-profiles-84c241ab2be44224973410a69944dd8b`.
- Release launcher compilation passed with zero warnings/errors. The launcher was compiled for verification only; its existing unrelated workspace changes were not installed.

This is an installed-file repair with a synchronized distribution script. The existing `Output/LifeOS-Setup.exe` was not rebuilt; rebuilding with `build.ps1` copies the corrected source worker into the next installer. Reinstalling an older installer can restore the obsolete worker.
