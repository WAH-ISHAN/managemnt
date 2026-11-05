# Local voice recording — 1.9.6

Open **LifeOS assistant → Text & local tools → Record a voice note**. Start recording asks for the microphone, Stop prepares a local playback, and Discard releases the recording. Save WAV works even without a configured recognizer. Transcribe locally requires an existing configured voice executable/model and sends the recording only to the app's loopback endpoint. Correct the returned text, preview tasks, and confirm before any task is created.

The browser encodes mono 16 kHz 16-bit PCM WAV using installed Web Audio APIs. Capture stops at three minutes. Stop, cancellation, page navigation, hidden-page/window state and native deactivation release microphone tracks. Pending permission requests are invalidated on cancellation; a later granted stream is immediately stopped. Recording blobs are held in page memory and object URLs are revoked on discard/navigation. Temporary server audio and converter outputs are removed after conversion or failure. No media, codec, speech model or AI runtime is downloaded.

The native main WebView grants microphone access only for a user-initiated request on the current local `/intelligence` page, with the exact running app origin and a visible, non-minimized application. The decision is not saved in the browser profile. Other permissions and secondary browser windows keep their denied policy. Native hide/deactivate notifications stop recording. The online conversation panel is unchanged.

Verification used disposable records and Chromium's synthetic microphone, never physical/personal audio:

- `php tests/focus_intelligence.php`: 47 checks passed.
- `python tests/intelligence_http.py`: 25 checks passed, including CSRF, a real WAV upload, missing-tool JSON error, no source creation on failure and temporary audio deletion.
- `python tests/voice_recording_ui.py`: 25 checks passed, including real audio capture/decoding and independently reading the downloaded WAV with Python's `wave` module; keyboard recording; duplicate-action protection; track release on Stop/blur/HTMX navigation; permission cancellation and denial; explicit upload; loading/error/retry; corrected source preview; and confirmed task creation. Sinhala/Tamil controls and narrow layout passed.
- Eight microphone origin/gesture policy assertions were added to `tests/resident-lifecycle/Program.cs` for the native release run. Their result belongs to that run, not the Chromium result above.

Latest focused browser evidence: `tests/.tmp/voice-ui-1793790967505578800` (excluded). Recording, playback, permission error, transcription loading/error, dark playback and Sinhala/Tamil narrow screenshots were visually inspected; controls and text were readable without overlap. Read the latest release report for any later full-run evidence.

The successful transcript and failed-recognizer browser responses are explicit HTTP fixtures. Existing-file readiness fixtures are never executed and are not real models. These checks do not establish real speech recognition quality, physical microphone compatibility or independent fluent language approval. Those account/device checks are deferred to the user; no speech model was installed. The native permission API follows [Microsoft's permission request contract](https://learn.microsoft.com/en-us/microsoft-edge/webview2/reference/winrt/microsoft_web_webview2_core/corewebview2permissionrequestedeventargs?view=webview2-winrt-1.0.2210.55).
