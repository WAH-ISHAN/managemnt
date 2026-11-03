# Part 3 — Work roles and the LifeOS assistant

## Work roles

Open **Work roles** at `/roles`. **Add default roles** installs editable Developer, QA, DevOps/Support, Lead/PM, University and Own venture roles, with recurring responsibilities. Repeating installation preserves edits and existing records. Rates start at zero and no money entries or completed work are invented.

The SQLite schema lives in `app/Modules/WorkRoles/migrations/001_work_roles.sql`. `role_time_logs` avoids a generic table name; role/task composite references prevent associating a timer with another role's task. There is one running role timer across the workspace. Each entry captures its hourly rate, so later rate changes do not rewrite historical estimates. Period totals clip entries at report boundaries, including overnight work. A stopped entry can be corrected explicitly.

Today generates each daily responsibility once per date, each weekly responsibility once on its configured weekday, and each monthly responsibility on its configured day or the last day of a shorter month. It does not replay missed periods. Generated task history remains when a responsibility changes or is disabled. Open Today to generate the current day's work; LifeOS does not need a permanent additional scheduler for this workflow.

Daily logs record **Did / Blocked / Next**. **Link reflection to Lessons** creates one linked reflection without silently creating an action task. Weekly review shows hours, completion dates and estimated income for every role. **Record received income** requires the actual received amount and confirmation, uses Money's allocation service and prevents posting the same role/week twice. Estimates never become income automatically. This app uses SQLite; no MySQL runtime or migration is claimed.

## Online assistant

The Windows app uses the already-installed native AI runtime. It does not download a model, install npm packages, start a permanent server, or embed another application's terminal UI. On this laptop the installed runtime is OpenCode 1.19.0. The provider interface is `AiProvider`; `OpenCodeCliProvider` implements the job queue and reviewed actions. The existing local-model adapter remains optional.

**Refresh free models** runs discovery on demand. Metadata must report zero input, output and cache prices; a name ending in `-free` alone is insufficient. The list expires after 24 hours. The worker can try at most two additional verified zero-cost models, stops on quota exhaustion and never switches to a paid provider. Identical successful answers are cached for 15 minutes. A free tier is an external service, not a guarantee of unlimited availability.

PHP queues a profile-scoped JSON job and returns immediately. The native host starts `scripts/opencode_worker.py` without a shell. The helper invokes the installed executable with an argument list and supplies the question through stdin. It parses completed `text` events from `--format json`; errors and tool events cannot become successful answers. The UI polls the small local result while the rest of LifeOS remains usable.

For example, the PHP application queues an explicitly authorized question using the actual provider implementation:

```php
$provider = new OpenCodeCliProvider();
$id = $provider->submit($question, $selectedFreeModel, []);
return redirect('/intelligence?job='.$id);
```

The runtime uses an empty per-profile folder outside the project worktree, isolated XDG configuration/data/cache directories, disabled plugins/project instructions/LSP downloads/updates, and a dedicated agent with all tool permissions denied. Personal API credentials are not inherited. This is a restricted agent configuration, not an operating-system sandbox.

## Privacy and confirmation

The chat form requires consent to send the typed question online. No salary, savings, account names, task titles, journal or document contents are attached automatically. A separate unchecked box permits only anonymized work totals, open-task count and this month's recorded spending. Free providers may retain prompts or use them for improvement; avoid confidential text. Local jobs, answer caches and the runtime's own conversation database remain on this computer, separately scoped by profile.

Model JSON can propose `add_task`, `log_expense` or a link to the recorded weekly review. PHP validates the allowlist and shows a preview. The user edits and confirms a task or an expense, including its account/category. Transaction-bound receipts prevent repeated confirmations from duplicating a task or debit. Other action kinds, including shell/file operations, are refused. A model response itself performs no LifeOS write.

## Verified scope

See [end-to-end verification](../qa/end-to-end-2026-11-03.md). Discovery succeeded without credentials. Live synthetic chat requests encountered provider quota/unavailability; successful free-model generation is not claimed. Offline parser/privacy/permission contracts, browser action previews and native background-worker dispatch were tested separately.

Primary references checked on 2026-11-03: [CLI](https://opencode.ai/docs/cli/), [agents](https://opencode.ai/docs/agents/), [provider models and privacy](https://opencode.ai/v2/docs/console/models/), [v1.19.0 JSON event source](https://github.com/anomalyco/opencode/blob/v1.19.0/packages/opencode/src/cli/cmd/run.ts), [MIT license](https://github.com/anomalyco/opencode/blob/v1.19.0/LICENSE). The optional runtime is not redistributed in the LifeOS installer.
