# Part 3 — Work roles and the LifeOS assistant

## Work roles

Open **Work roles** at `/roles`. **Add default roles** installs editable Developer, QA, DevOps/Support, Lead/PM, University and Own venture roles, with recurring responsibilities. Repeating installation preserves edits and existing records. Rates start at zero and no money entries or completed work are invented.

The SQLite schema lives in `app/Modules/WorkRoles/migrations/001_work_roles.sql`. `role_time_logs` avoids a generic table name; role/task composite references prevent associating a timer with another role's task. There is one running role timer across the workspace. Each entry captures its hourly rate, so later rate changes do not rewrite historical estimates. Period totals clip entries at report boundaries, including overnight work. A stopped entry can be corrected explicitly.

Today generates each daily responsibility once per date, each weekly responsibility once on its configured weekday, and each monthly responsibility on its configured day or the last day of a shorter month. It does not replay missed periods. Generated task history remains when a responsibility changes or is disabled. Open Today to generate the current day's work; LifeOS does not need a permanent additional scheduler for this workflow.

Daily logs record **Did / Blocked / Next**. **Link reflection to Lessons** creates one linked reflection without silently creating an action task. Weekly review shows hours, completion dates and estimated income for every role. **Record received income** requires the actual received amount and confirmation, uses Money's allocation service and prevents posting the same role/week twice. Estimates never become income automatically. This app uses SQLite; no MySQL runtime or migration is claimed.

## Online assistant

The Windows app uses the already-installed native AI runtime. It does not download a model, install npm packages, start a permanent server, or embed another application's terminal UI. On this laptop LifeOS selects the explicitly approved OpenCode v2.2.5 CLI at `%LOCALAPPDATA%/LifeOS/ai-runtime/opencode-2.2.5/opencode.exe` before checking npm/PATH installations. Each request uses a private standalone process with isolated configuration and tools denied. No CLI or model is downloaded automatically. The provider interface is `AiProvider`; `OpenCodeCliProvider` implements the job queue and reviewed actions. The existing local-model adapter remains optional.

**LifeOS AI** opens as its own compact chat window from the toolbar, assistant navigation link, tray menu, or **Win + Shift + \\**. Escape or the close button hides it; the same shortcut reopens the conversation. The existing Win + \\ desktop panel stays available. There are no provider selectors or model-refresh chores in the normal conversation.

Model discovery happens automatically. Metadata must report zero input, output and cache prices in every pricing tier; a name ending in `-free` alone is insufficient. The list expires after five minutes and is refreshed once more during fallback. A failed refresh invalidates old pricing metadata. All verified zero-cost text models are eligible: successive questions rotate across the pool, and temporary model failures fall through to another eligible model with a five-minute cooldown for the failed model. There is no fixed three-model limit. Each attempt has at most 20 seconds, within a 90-second overall discovery/response budget, so a large pool of slow services cannot keep the conversation pending indefinitely. Provider quota exhaustion stops immediately with a cooldown shared by the pool; changing models never bypasses that quota. Existing cooldowns remain valid after this routing update. The worker never switches to a paid provider. Identical successful answers are cached per model for 15 minutes. A free tier is an external service, not a guarantee of unlimited availability.

PHP queues a profile-scoped JSON job and returns immediately. The native host starts `scripts/opencode_worker.py` without a shell. The helper invokes the installed executable with an argument list and supplies the question through stdin. It parses completed `text` events from `--format json`; errors and tool events cannot become successful answers. The UI polls the small local result while the rest of LifeOS remains usable.

For example, the PHP application queues an explicitly authorized question using the actual provider implementation:

```php
$provider = new OpenCodeCliProvider();
$id = $provider->submitAutomatic($question);
return json_encode($provider->panelState());
```

The runtime uses an empty per-profile folder outside the project worktree, isolated XDG configuration/data/cache directories, disabled plugins/project instructions/LSP downloads/updates, and a dedicated agent. A native `tool.use` policy denies every tool execution. OpenCode retains its native tool definitions; the redundant catch-all `permissions` rules that removed those definitions caused the service to reject the configuration and have been removed. A real shell/read/glob probe confirmed `Blocked by configuration policy` and `executed: false`, with no test file created. Personal API credentials and CLI session history are not inherited or copied. This is a restricted agent configuration, not an operating-system sandbox.

Normal conversation also leaves the runtime's step count at its default. The former `steps: 1` configuration inserted a maximum-steps summary instruction into the first response. Agent revisions have their own answer-cache namespace so old responses are not reused after this correction; provider quota cooldowns are preserved.

## Privacy and confirmation

Send submits the typed message online, with a short data notice in the composer. No salary, savings, account names, task titles, journal or document contents are attached automatically. Follow-ups include a bounded history from this conversation; New chat starts a separate thread. Free providers may retain prompts or use them for improvement; avoid confidential text. Local jobs, answer caches and the runtime's own conversation database remain on this computer, separately scoped by profile. Account/category options for expense confirmation stay local and are never included in the model request.

Model JSON can propose `add_task`, `log_expense` or a link to the recorded weekly review. PHP validates the allowlist and shows a preview. The user edits and confirms a task or an expense, including its account/category. Transaction-bound receipts prevent repeated confirmations from duplicating a task or debit. Other action kinds, including shell/file operations, are refused. A model response itself performs no LifeOS write.

## Verified scope

See [end-to-end verification](../qa/end-to-end-2026-11-03.md). OpenCode v2.2.5 discovery verified eight zero-cost text models without credentials. After correcting the redundant permissions, the real native composer returned `OK` for the synthetic test question. The patched installed worker also returned `OK` and a Sinhala planning reply using two verified free models. Offline contracts verify fallback through eight models, fair rotation, pricing changes, model cooldowns, provider quota stopping and the overall time budget. Browser action previews and native background-worker dispatch were tested separately. Future provider quotas remain external limits and still stop further attempts.

Primary references checked on 2026-11-03: [v2 CLI](https://opencode.ai/v2/docs/cli/), [v2 API](https://opencode.ai/v2/docs/api/), [v2 agents](https://opencode.ai/v2/docs/agents/), [provider models and privacy](https://opencode.ai/v2/docs/console/models/), [native policy enforcement](https://github.com/anomalyco/opencode/blob/v2.2.5/packages/core/src/config/plugin/policy.ts), [v2.2.5 JSON event source](https://github.com/anomalyco/opencode/blob/v2.2.5/packages/cli/src/run/noninteractive.ts), [MIT license](https://github.com/anomalyco/opencode/blob/v2.2.5/LICENSE). The optional runtime is not redistributed in the LifeOS installer.
