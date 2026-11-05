# Desktop panel border and corners

The reported panel had a broad pale outline and mismatched cut-outs at its four corners. The running installation was 1.9.3, while the workspace already contained later native-border suppression. The fix explicitly recalculates the custom native frame, keeps the WebView flush with the client area, removes the desktop shell's CSS border, and lets the rounded HWND region alone define the native panel's outer corners. Browser-only previews retain the established 26px radius.

The native document receives its clipping/material attributes at DOM readiness as well as navigation completion. This avoids independently rounding the translucent WebView surface over an already rounded acrylic window. The existing GDI region's `Width+1` and `Height+1` dimensions are intentional: GDI excludes the last row/column of a rounded region.

## Verification

- Release launcher build: zero warnings/errors.
- Real PHP/Chromium floating-panel workflows: 593 checks, no browser errors; English/Sinhala/Tamil, light/dark and compact/wide layouts.
- Four additional browser cases verify zero outer border, 26px browser rounding and native-only corner ownership.
- Native browser/profile diagnostic: 130 checks passed on each of two launches, including client/window bounds, actual HWND regions, all four corner curves, edge coverage, desktop/assistant resizing and close/reopen. The installed app owned the global shortcut; these diagnostic runs explicitly recorded that one shortcut registration/dispatch skip.
- Direct Windows GDI region verification: 18 size/DPI combinations, 96 through 288 DPI; full straight-edge coverage and matching mirrored corner curves.
- Native light/dark screenshots inspected.

The first stricter native run caught an attempted removal of the region's `+1` dimensions. The original dimensions were restored before the successful final runs. Evidence is retained separately.

Evidence (ignored local files):

- `tests/.tmp/floating-panels-ui/results.json`
- `tests/.tmp/panel-native-clip-browser/results.json`
- `tests/.tmp/browser-profiles-506efad54d4749e7bc58d07044f9915f/browser-profile-run-1.json`
- `tests/.tmp/browser-profiles-506efad54d4749e7bc58d07044f9915f/browser-profile-run-2.json`
- `tests/.tmp/browser-profiles-506efad54d4749e7bc58d07044f9915f/panel-layout/desktop-panel-native-{light,dark}.png`

These tests used disposable records and fixture responses. No personal record was sent to an online model and no AI runtime or weights were installed. Physical cross-monitor DPI dragging and Windows 10 fallback rendering were not exercised in this session.
