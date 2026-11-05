# Workspaces that match a person's roles

First start asks **Which roles fit your life?** Users select all that apply before entering their name and preferences. University and Employee can be selected independently or together. Freelancer, Business owner and Job seeker remain available too.

The selection previews the user's tasks and tools rather than implementation details. It creates an empty workspace; no coursework, employment history, income or completed tasks are invented. Users can change roles later in **Settings → Modules & language → Your roles**.

## Workflow research and product decisions

The following are design choices for LifeOS, informed by the primary product documentation linked here:

| Role | Main workflows | LifeOS tools |
| --- | --- | --- |
| University student | Modules/credits, assignments, lecture planning, grades, study resources and student budget | University, University study tools, Planner, Focus, Money, Documents and career preparation |
| Employee | Work priorities/projects, hours, meetings, responsibilities, salary and career growth | Employee workspace, Work management, Work roles, Money, Career and personal essentials |
| Multiple roles | Retain each role's workflows in one daily plan | Union of the selected defaults, without duplicate tools |

[MyStudyLife's feature guide](https://mystudylife.com/tour/) puts school/university classes, homework, exams and activities together. [Notion's semester guide](https://www.notion.com/nb/help/guides/get-organized-for-a-new-semester-with-notion) describes class notes, assignments, resources and everyday student planning. [Microsoft's To Do vs Planner guide](https://support.microsoft.com/en-us/todo/to-do-vs-planner) distinguishes individual tasks from project/team planning; [Microsoft's task organization guide](https://support.microsoft.com/en-us/outlook/calendar/manage-tasks-with-to-do-in-outlook) also supports organizing work and school tasks separately. These informed the role-specific areas and shared Planner, rather than requiring one exclusive persona.

Existing LifeOS timesheets, payslips, GPA and student budget tools were mapped to these workflows; their financial/academic formulas were not changed by this update.

## Behavior

- Selected roles are stored in `profile_roles` within the selected local profile's own database. The migration retains the previous single persona and leaves saved profile fields/module switches untouched. Legacy/custom installations without a selection remain compatible until configured.
- The first selected role remains in the older `local_profiles.persona` field for compatibility. Multiple selections use the balanced dashboard.
- `PersonaService` combines defaults and centrally checks module eligibility. University/StudyTools require University, Employee requires Employee, and business/client tools require their matching roles. Work management and Work roles belong to the work-related roles. Work roles are no longer enabled automatically for a university-only or job-seeker-only workspace.
- Sidebar groups, dashboard shortcuts, quick actions, settings cards and module switches use the same enabled/eligible state. Direct module URLs and forged enable requests cannot override role eligibility.
- Shared tools can be switched off; module ordering is retained within each sidebar group. Saving roles explicitly reapplies recommended defaults and sidebar order. Removing a role hides its tools without deleting its records, so re-adding it restores access.
- Starter setup can match any selected role. Only matching starter plans are offered; a forged install request cannot add an unselected role. Installing a separate starter pack retains the user's other selected roles.
- Roles personalize one workspace. They do not replace the independent local profiles, PINs or guardian/kid restrictions.

## Verification

Tests use disposable databases and browser sessions, without changing the user's installed workspace. The first-start UI exercises the supported University/Employee combinations, matching sidebars/settings, narrow layouts, missing selections, removal/re-addition, preserved course records and Sinhala/Tamil controls. HTTP/service tests cover invalid payloads, role eligibility, aliases, transaction rollback, migration preservation and restart persistence.

Screenshots/results remain under excluded `tests/.tmp/workspace-roles-ui` and `tests/.tmp/persona-ui`. The source and Release build are updated; this work does not replace the running installed app or publish an installer.

Checks completed on 2026-11-03:

| Check | Result |
| --- | --- |
| Role/persona service, validation, rollback and migration | 331 passed |
| All seven first-start combinations, settings, records and matching starter plans | 101 browser checks passed |
| Existing persona UI workflows | 25 passed |
| Persona/resident HTTP validation | 57 passed |
| Local profile and starter-pack services | 44 passed |
| Profile/guardian/pack HTTP flows | 40 passed |
| Fresh-workspace preservation | 179 passed |
| Localization contracts | 4,785 passed |
| Floating-panel layout regression | 487 passed |
| Release .NET build | Passed, zero warnings/errors |

Two native lifecycle launches passed 60 checks each, covering isolated browser profiles, window controls, floating panels and reopen/resize behavior. Global shortcut registration/dispatch was explicitly skipped because the already-running installed LifeOS owns it. Evidence: `tests/.tmp/browser-profiles-cfa51dfd1d4c4f8da7f2875ebf58e81a` (excluded). An earlier native run hit a test-reader file-sharing race while the AI probe atomically replaced its result file. The probe reader now permits file replacement; both final native launches passed. No online AI requests were made by these checks.

The combined-role screenshots were inspected at desktop and narrow widths. An initial browser assertion raced with navigation completion; the test now waits for the new sidebar before checking its links.
