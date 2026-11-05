# LifeOS 1.9.6 local completion

The 1.9.6 source completes the local workflows identified in the unfinished-task audit: all-day calendar exchange, separately approved cancellation/deletion publishing, microphone recording with local playback and reviewed transcription, and automatic encrypted workspace delivery with reviewed replacement. Knowledge Hub and the native close/reopen repairs are included in the distribution build. The version is updated consistently in the launcher, installer, Settings and development plugin compatibility checks.

## Implemented workflows

- [Calendar completion](calendar-completion-1.9.6.md): inclusive all-day date controls, date-only ICS round trips and Google/Outlook exchange. Deletion publishing is a separate default-off permission, uses provider version preconditions, preserves conflicts and refuses attendee-bearing meeting deletion.
- [Local recording](voice-recording-1.9.6.md): microphone start/stop/discard, mono PCM WAV playback/download, local transcription and edited source/task review. No speech models, weights, codecs or new AI runtimes were installed. Unconfigured recognition is visibly unavailable.
- [Workspace handoff](workspace-exchange-1.9.6.md): automatic authenticated encrypted snapshots through an existing shared folder, reviewed additions/changes/removals and document replacement, a safety backup, paused background jobs, expired old API authority and invalidated old browser forms. Session generation and replay receipt activate with the incoming database, including across an interrupted replacement. This is snapshot handoff rather than concurrent field-level merging.
- Release safeguards compare the built distribution with current application/assets/docs and required helpers, verify the selected installed AI executable's hash and isolated Python imports, and record installer version/checksum/signature status. These checks do not extract the installer or substitute for clean-machine installation.

## Verification and release evidence

Focused service, transport, HTTP and actual Chromium checks are recorded in the feature reports above. Their screenshots were opened and inspected in light/dark and narrow English/Sinhala/Tamil states. The new Sinhala/Tamil wording also received an AI meaning review, with device identity, API-client and voice-model terminology clarified; independent fluent human approval is separate.

An initial broad run at `tests/.tmp/qa-20261104-112252-049fa885/summary.json` passed its functional stages but failed native edge resizing and source stability while the final workspace crash-safety fix was being applied. That run is retained as failed evidence and must not be represented as final QA approval. Final stable-source/native and installer results will be recorded here after completion.

Native diagnosis established a foreground/input timing issue in the test: another application could remain above the isolated LifeOS window, and the diagnostic released the mouse on a fixed deadline before the WebView/native bridge entered resizing. The diagnostic now verifies its foreground/edge target, establishes focus only for the test, waits for sizing entry and still requires the exact 32-pixel width change with unchanged height. Its geometry evidence is retained. A later Kanban test race dispatched drag before deferred Work handlers loaded after full navigation; the readiness wait now requires the complete document, preserving the original saved-state assertion. These corrections do not skip either behavior.

The received-workspace review also uses full navigation so its heading begins below the sticky toolbar; actual desktop/narrow viewport and horizontally scrolled change-count screenshots were inspected after the fix. Whole-workspace replacement now pauses the independent University calendar importer as well as connection-calendar jobs.

The stable-source run `tests/.tmp/qa-20261104-114601-f7df3a63/summary.json` passed all source/service/HTTP/browser stages and source stability. Its native stage reached Quick Add but failed the historical fixed count of eight actions after the full-role and Knowledge additions. The diagnostic now checks the exact twelve expected action destinations, including both Knowledge actions, rather than the obsolete count. That failed run is retained separately from the final rerun.

The update-delivery/rollback suite passed 51 checks in `tests/.tmp/update-delivery-49d2ffc7e5514f38b3137664e32fd070`. It used disposable installations and verified original database/upload preservation. The machine's system `dotnet` had no SDK; the successful run used the project's already-installed SDK.

## Deferred account and physical-device acceptance

The user chose to handle account/device checks later. Real customer AI authorization/generation/disconnect; registered Google/Outlook and email-provider sign-in/refresh; physical microphone and speech/OCR recognition quality; trusted-TLS phone pairing and real second-computer shared-folder transport; Windows Hello; physical keyboard, sleep/resume and clean-machine installation remain unverified. Existing native event simulation and synthetic-media browser checks are labelled accordingly.

The Windows executable and installer remain unsigned because a trusted signing certificate is not configured. No personal installation, personal records, external account or published release is changed by this local completion run. Historical 100-feature and Jira-parity wish lists remain reference scope; this update does not claim every possible extension is implemented.
