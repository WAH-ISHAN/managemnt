# LifeOS 1.5.2 — Glass window header

The desktop window now has a glass header that matches the workspace. It includes Apple-inspired close, minimize and maximize/restore controls on the left, the LifeOS logo in the centre, and a local-workspace indicator.

The header supports dragging, double-click maximize/restore, a native system menu, and light/dark themes. Native resize edges scale with display DPI. Maximizing uses the current monitor's working area. The header is available on onboarding and PIN screens, and its controls remain usable while Quick Add or command search is open. If a page cannot load its custom header, the host restores the standard Windows caption.

Verification: 116 native WebView2 checks passed, including eight native resize hit tests, actual minimize/maximize/restore, modal interaction, unlock controls, responsive light/dark screenshots, and closing through the new close control. The test also confirmed that the PHP process exits when the window closes.

The app's data schema is unchanged in this release. The installed database was backed up before the update; user-row hashes are verified again after installation.
