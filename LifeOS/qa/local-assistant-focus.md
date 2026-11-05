# Local assistant, focus events and morning weather

Implemented source workflows (unreleased):

- Focus can link one open Planner task and one active habit at start. Completing a study session writes its actual elapsed seconds to `focus_task_logs` once. Pause duration is excluded; time is capped at the planned duration. A configured actual-minute threshold creates a traceable `focus_habit_events` row and an idempotent daily habit check. Finishing early, taking a break and cancelling never satisfy an unmet habit threshold. Logs are visible on Focus.
- Enable **Local assistant** in Settings → Modules & language. Notes, recognized receipt text and voice transcripts are retained as editable sources. Explicit action lines/bullets produce editable task previews with due dates and exclusions. Confirmation creates Planner tasks with source links. Source deletion removes previews/links, preserving already-created tasks.
- Bounded English commands `move all overdue tasks to tomorrow` and `move task #12 to YYYY-MM-DD` produce affected-record previews. Apply checks for intervening changes; undo restores prior dates atomically and stops if a task changed later. Arbitrary/destructive commands are rejected.
- Optional Ollama chat calls only `http://127.0.0.1:11434/api/chat`, with no proxy or redirect. The question, current reporting month, open-task count and (when Money is enabled) monthly recorded spending are sent. Database-calculated figures are displayed separately from model wording. Enable this adapter and enter a model already installed in Ollama.
- Optional local Tesseract OCR accepts PNG/JPEG/TIFF receipts. Optional whisper.cpp `whisper-cli` accepts WAV notes (first three minutes) with a configured GGML model. Record a voice note directly in Text & local tools, listen, save a local WAV or explicitly transcribe it. Capture is bounded to three minutes and stops on page/window changes. Both converters require explicitly selected absolute executable paths. Invocation passes an argument array without a shell, bounds runtime/output, and deletes temporary media. Correct recognized text before creating a preview. OCR receipt previews require explicit account/category/amount/date confirmation and use the existing SalaryService expense debit. Repeated apply or another preview of the same receipt source cannot debit again.
- Optional Open-Meteo weather sends only configured coordinates to a fixed HTTPS API. Current temperature, precipitation and source timestamp appear in the morning brief. A daily cached unavailable result keeps a network failure from aborting the brief. Configure this in Automations. Weather is not historical/replayed forecast data.
- Sinhala/Tamil UI dictionary additions are in `app/Modules/Onboarding/locales/intelligence.php`; fluent human language acceptance is still pending.

## Verified in disposable data

- `php tests/focus_intelligence.php`: 47 checks passed, including real local child-process argument preservation, nonzero exit handling, a receipt status changed between preview and transactional claim, and rollback of a failed early task claim.
- `python tests/intelligence_http.py`: 25 real HTTP checks passed (Windows PHP 8.3 and Windows Python). Covers source save/edit/preview/apply, CSRF, escaped content, duplicate apply, move/undo, Ollama opt-in, focus completion idempotency, weather validation, recorded WAV upload/error cleanup and database integrity.
- `python tests/voice_recording_ui.py`: 25 real Chromium synthetic-microphone checks passed. Validates local PCM WAV conversion, playback/download, track release, pending-permission cancellation, HTMX cleanup, explicit upload, loading/error/retry, corrected text and confirmed task creation. Transcription responses are explicit fixtures; see [voice recording verification](voice-recording-1.9.6.md).
- `python tests/extensions_ui.py`: 23 headless Chromium checks passed with desktop/narrow screenshots in `tests/.tmp/extensions-ui`; capture preview/conversion, planning apply/undo, trends, Sinhala/Tamil saved locale and assistant rendering. Screenshots were visually inspected.
- Existing suites: `php tests/automation.php` (38), `php tests/planner_growth_career.php` (63) passed during implementation.

On WSL with Windows PHP, use Windows Python so both client and server use Windows loopback:

```bash
LIFEOS_QA_PHP='C:\php\php.exe' /mnt/c/Users/USER/AppData/Local/Programs/Python/Python312/python.exe tests/intelligence_http.py
```

## External acceptance still required

This workspace did not have Tesseract/Whisper models or a running Ollama service configured. Tool adapters and response contracts are implemented; successful recognition on real Sinhala/Tamil/English media and model generation have not been claimed. OCR supports configured English/Sinhala/Tamil Tesseract language selections when the matching traineddata files are installed. Voice supports direct microphone recording and uploaded WAV files. Notes extraction is conservative deterministic extraction of explicit action lines, not a model-generated summary. Uploaded media itself is not retained after conversion.

A live Open-Meteo request using synthetic coordinates 0,0 succeeded in an in-memory disposable database after enabling the Windows native certificate store. No personal coordinates were transmitted. Physical microphone acceptance, model installation, native Windows visual review and release/installer acceptance were not performed for this slice. Optional integrations remain unconfigured by default. The UI should expose this status honestly.

Contracts consulted: [Open-Meteo Forecast API](https://open-meteo.com/en/docs), [Ollama chat](https://docs.ollama.com/api/chat), [Tesseract command line](https://tesseract-ocr.github.io/tessdoc/Command-Line-Usage.html), [whisper.cpp CLI](https://github.com/ggml-org/whisper.cpp/blob/master/examples/cli/README.md).
