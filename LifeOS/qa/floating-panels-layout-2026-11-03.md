# Floating panel layout verification

The request described a clipped header, left-side strip, awkward overlap and chat scrolling problems. The referenced screenshot attachment was not available in this conversation, so exact comparison with the orange annotations remains unverified. The implementation and newly rendered screenshots were inspected directly.

## Changes and causes

- The AI background was painted on `body`, which allows the browser canvas to paint outside a rounded child container. The same existing gradients/colors now belong to the clipped rounded shell; the WebView background is transparent from creation.
- The AI shell uses three grid rows: header, a shrinking scroll area, and composer. `min-height: 0`, explicit horizontal clipping and an inset scroll area keep the scrollbar, long messages and code within the content row. The composer and header remain outside that scrolling row.
- Header text can shrink/wrap while logo/action controls keep their size. The main titlebar uses balanced grid columns instead of absolutely positioning its brand over neighboring controls. Colors, icons, type and available actions were retained.
- Consolidated the desktop shell declarations so a later padding rule no longer defeats its narrow-width spacing. Both panel shells use the same 26px corner geometry as the native window regions. Native rounding updates on size/DPI changes.
- Native border suppression previously ran only in the acrylic branch. It now applies in fallback mode too, preventing a separate pale native edge around the WebView content.
- The floating panels are separate Windows windows; a CSS z-index cannot resolve their overlap. A shared placement helper chooses a free adjacent position within the monitor working area, clears the visible main titlebar, and uses an aligned cascade if both panels cannot fit side by side. Cascade spacing uses the companion's measured header height from a ResizeObserver. It adjusts only the window being revealed; dragging remains available.
- The standalone desktop page was missing the locale scripts its existing JavaScript calls. It now loads the same localization helper as the other pages, avoiding a rendering exception when loading schedule/notes and preferences.

## Verification

| Check | Result |
| --- | --- |
| Release .NET build | Passed, zero warnings/errors |
| PHP syntax for standalone desktop panel | Passed |
| Floating panel browser geometry/rendering | 487 checks passed |
| Existing assistant/Work Roles browser workflows | 33 checks passed |
| Desktop panel HTTP interactions | 25 checks passed |
| Native lifecycle, two launches | 60 checks passed per launch; one explicitly reported shortcut skip per launch |

Browser geometry checks use real PHP pages and disposable local records/jobs, with an inert native bridge and fixture responses. They cover English/Sinhala/Tamil, light/dark themes, AI viewports from 320x360 through 760x900, desktop viewports from 320x360 through 700x900, and main titlebars from 720x600 through 1365x900. Assertions cover horizontal overflow, header/control containment, outer-corner transparency, independent long-answer/code scrolling, an inset scrollbar and stationary header/composer. Screenshots of compact/large panels and the main titlebar were inspected.

Native checks exercise both visible panels, actual minimize/maximize/restore controls, close/hide/reopen, programmatic native resizing at three sizes, WebView/client bounds, rounded window regions, tray behavior, restart persistence, profile isolation and simulated lock handling. Deterministic placement checks also cover a narrow 800x600 working area and a monitor with a negative screen origin. These do not constitute a new physical drag/edge-resize or sleep/resume test.

The installed LifeOS instance already owned the global assistant shortcut (Win32 1409). The first strict smoke run reported this failure. The subsequent layout runs used `LIFEOS_ALLOW_HOTKEY_CONFLICT=1`, which skips only that specific registration/dispatch check and records the skip in the result JSON. The default strict behavior is retained. The existing user's app was left running.

The Computer Use native helper was unavailable on both inspection attempts (`failed to connect native pipe`, OS error 2), so native visual verification used the application's existing diagnostic captures rather than interactive computer control.

Evidence is excluded from Git:

- `tests/.tmp/floating-panels-ui/results.json` and PNGs
- `tests/.tmp/roles-ai-ui/results.json` and PNGs
- `tests/.tmp/browser-profiles-150282329f374758a3445eae20e75f34/browser-profile-run-1.json`
- `tests/.tmp/browser-profiles-150282329f374758a3445eae20e75f34/browser-profile-run-2.json`
- Native AI/desktop captures in that same native test directory

The source and Release build are updated. No personal database was used, online AI request sent, installed app replaced, installer published or Git push performed for this layout change.
