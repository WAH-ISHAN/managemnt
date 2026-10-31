# Website Manager admin reference audit

Read-only source inspection on 29 October 2026. Neither reference project was edited, built, started, deployed, or contacted over the network. Credentials, runtime data, `.env` contents and production databases were not copied.

## Scope and integration implication

These are two substantial application-specific admin systems. Reusing their full concepts requires nested content editing, media operations, resource tables, reports and action-specific permissions. A generic pages/products CRUD form covers only a small subset. A domain and server address cannot reveal the application's data model or grant administrative access.

Keep LifeOS's local website registry and common glass shell. Add capability-based adapters: a CreativeFX content API adapter, a TenderHub staff API adapter, and a universal connector for other backends. Expose only capabilities the connected backend actually supports. For unavailable services show an unconfigured/error state, never invented metrics or disconnected action buttons.

## CreativeFX (`D:\OFZ\creative-_6`)

Stack: React 19, Vite 6, TypeScript, Express 4 (`server.cjs`), file-backed JSON content and uploads. Admin entry is `src/admin/AdminPanel.tsx` and `src/admin/AdminLogin.tsx`; the app opens it from an `admin=1` URL flow. Public and admin content share `src/context/ContentContext.tsx` and its `SiteContent` shape.

### Complete navigation inventory

| Admin group | Views / capabilities | Primary source |
| --- | --- | --- |
| Dashboard | Real content/storage/health summaries, recent inquiries, refresh, quick links | `src/admin/components/DashboardSection.tsx` |
| Homepage | Hero title/subtitle/body; studio introduction/image; ordered featured projects; selected project grid; CTA; partner-logo section | `HomepageSection.tsx`, `PartnerLogosAdmin.tsx` |
| Works / Projects | All Projects, Add Project, Photography, Video; project filtering/editing; covers/galleries/media; project SEO; draft/published status | `WorksSection.tsx`, `src/types.ts` |
| Weddings | Stories, timeline chapters, hero/media and headings, approach/philosophy cards | `WeddingsAdmin.tsx` |
| Services | Service records, descriptions, images, capabilities/deliverables and publish status | `ServicesAdmin.tsx` |
| About | Studio story/philosophy; team portraits/names/roles/bios/skills/social links; statistics | `AboutAdmin.tsx` |
| Creative Pool | Page header/hero/CTA; roster profiles, names, Instagram details and image collections | `CreativePoolAdmin.tsx` |
| Pages | Works catalog title/intro; Contact page heading/form copy and success-state text | `PagesSection.tsx` |
| Media Library | Images, videos, uploaded files, upload/replace; upload images/video; remote URL and Drive imports; list/delete/copy file URLs | `MediaLibrarySection.tsx`, `AdminFields.tsx` |
| Navigation & Footer | Primary/extra links, CTA, announcement strip, footer brand/copyright/social URLs, section/page visibility | `WebsiteSection.tsx` |
| Contact / Inquiries | Inbox filtering/status updates/deletion; testimonials; Google Reviews configuration/sync; studio contact and social details | `ContactSection.tsx` |
| SEO | Primary metadata, indexing/verification IDs, search-engine connections, backlink tracker, Open Graph card | `SEOSection.tsx` |
| Theme / Branding | Accent colours, global typography, section-specific colours/text scale, logos/assets | `ThemeSection.tsx` |
| Analytics | Analytics and Search Console configuration; truthful connection state | `AnalyticsSection.tsx` |
| Settings | Custom CSS/animation code, studio identity, JSON backup/export/import, reset content | `SettingsSection.tsx` |
| Admin Users | Current admin access/session information and change-password flow; this is not a general multi-user directory | `AdminUsersSection.tsx` |

Every component path in this table is relative to `D:\OFZ\creative-_6\src\admin\components`, except where a fuller path is specified. The primary menu has 16 groups; there are 18 files in the components directory including shared fields and partner logos.

### Existing API contract

Base URL is same-origin or `VITE_API_URL`; see `src/context/ContentContext.tsx` (`API_BASE`, `persistContent`) and `server.cjs` routes.

| Method / route | Behavior |
| --- | --- |
| `GET /api/health` | Health, uptime, timestamp |
| `GET /api/content` | Full published nested site JSON |
| `POST /api/content` | Replace validated whole content document; server writes atomically; response `{ok:true,_updatedAt}` |
| `POST /api/content/reset` | Remove persisted content/reset; destructive |
| `POST /api/admin/login` | Password body; returns short-lived session `{token}` |
| `POST /api/admin/logout` | Revoke caller's session |
| `GET /api/admin/session` | Session validation |
| `POST /api/admin/password` | Current/new password; revokes prior sessions and returns replacement token |
| `GET /api/inquiries` | Authenticated inbox |
| `POST /api/inquiries` | Public contact submission |
| `PATCH /api/inquiries/:id` | Status change |
| `DELETE /api/inquiries/:id` | Remove inquiry |
| `GET /api/uploads` | List stored uploaded files |
| `DELETE /api/uploads/:name` | Delete uploaded file |
| `GET /api/stats` | Storage statistics |
| `POST /api/upload` | Multipart upload |
| `POST /api/import-drive` | Authenticated remote media import |
| `POST /api/import-url` | Authenticated public-URL import |
| `GET /api/google-reviews` | Review configuration/results |
| `POST /api/google-reviews/sync` | Authenticated review configuration/sync |

Authenticated CreativeFX requests use **`x-admin-token`**, not the universal connector's Bearer token. Its content endpoint is a nested whole-document save, not record CRUD with connector version fields. The existing autosave/draft and publish controls share a server-authoritative persistence flow. Preserve those semantics if adapting; do not map arbitrary small form fields to an empty replacement document. The content server validates prototype-pollution keys, maximum nodes/depth and rejects empty content. No direct multi-user staff-management API was found.

### Best reuse path

Create a CreativeFX adapter that reads the entire content document, edits only selected sections while preserving unknown keys, previews the complete outgoing diff and saves a full document. Map media/inquiries to their distinct APIs. Use blank configuration templates rather than copying public business/team data or default content into LifeOS as if entered by its user. Dangerous capabilities (reset, code injection, media delete) need clear scoped controls and action previews.

## TenderHub (`D:\OFZ\tender`)

Stack: Next 16.4.2 / React 19.2.8 frontend in `web/`; PHP CodeIgniter API in `apps/api/`; Python ingestion pipeline and separate marketing/SEO engines. Entry `/admin`, protected console `/console`. Sources are `web/src/app/(portal)/console/layout.tsx`, `web/src/components/ds/admin-shell.tsx`, `web/src/lib/session.ts`, `web/src/lib/admin-access.ts` and `apps/api/app/Config/Routes.php`.

### Complete main console navigation inventory

| Group | Page paths | Main capability |
| --- | --- | --- |
| Overview | `/console`, `/console/infrastructure`, `/console/automation` | System health/coverage/events, infrastructure/storage/maintenance, automation analysis |
| Content & ingestion | `/console/ingestion`, `/console/data-quality`, `/console/notices`, `/console/blog`, `/console/seo` | Source schedule and live runs, quality checks, notice moderation/publishing, blog CRUD/preview, SEO service |
| Marketing | `/console/acquisition`, `/console/marketing`, `/console/campaigns`, `/console/email`, `/console/email-analytics` | Acquisition reports, social engine, campaigns, SMTP gateway/config/test/retry, delivery analytics/detail |
| Customers | `/console/organisations`, `/console/organisation-portal`, `/console/users`, `/console/trials`, `/console/kyc`, `/console/support` | Organisation verification/portal switch, staff/customer records and exports, trials, verification documents/reviews, support mailboxes/messages |
| Billing & integrations | `/console/payments`, `/console/worksuite` | Payment confirmation/rejection/gateways/receipts, WorkSuite connection health/config |
| Security & compliance | `/console/risk`, `/console/security`, `/console/legal-holds` | Risk signals, security events, retention/legal-hold management |

Additional nested views: `/console/blog/[id]/preview`, `/console/email-analytics/[id]`, `/console/seo/dashboard`, `/console/seo/backlinks`.

The shell supports a searchable setting rail, foldable groups, collapse-to-icons, active navigation, persisted rail preferences and mobile drawer. This is a reusable UI concept, separate from its business-specific services.

### Actual API surface

The PHP admin route prefix is **`/api/v1/admin`**. The Next proxy at `web/src/app/api/admin/[...path]/route.ts` accepts `/api/admin/<path>` (and legacy `/api/admin/admin/<path>`) and normalizes to that prefix. It supports GET/POST/PUT/PATCH/DELETE. Server-rendered pages call `authed(...)` using the PHP prefix.

| API suffix / family | Supported actions |
| --- | --- |
| `session`, `reports/health`, `reports/coverage`, `reports/automation` | Live staff session and reports |
| `infrastructure/status`, `infrastructure/maintenance`, `.../safe-cleanup` | Status/readiness and bounded cleanup queue |
| `notices`, `notices/:id/publish`, `notices/:id/merge` | Moderation queue, publish, merge |
| `ingest/sources`, `ingest/quality`, `ingest/schedule`, `ingest/live-run`, `ingest/telemetry`, `ingest/runs` | Source inventory, quality, schedule edit, queue/status, telemetry/history |
| `ingest/rest-api-key`, `ingest/rest-sources`, `ingest/website-sources` | Source/API integration provisioning |
| `ingest/sources/:id/{run,pause,resume,toggle,credentials,collect}` | Source operations; credentials remain server-side |
| `organisations`, `organisations/:id/verify`, `organisations/:id/suspend` | Organisation governance |
| `kyc`, `kyc/:id`, `kyc/documents/:id`, `kyc/:id/start-review`, `kyc/:id/review` | Verification workflow/documents |
| `risk-signals`, `security/events`, `legal-holds`, `legal-holds/:id/release` | Compliance/security |
| `payments`, `payments/gateways`, `payments/gateways/:key`, `.../disable`, `payments/:id/receipt`, `.../confirm`, `.../reject` | Payment and gateway operations |
| `api-keys`, `api-keys/:id/review`, `api-keys/documentation` | API key request/review/provisioning |
| `users`, `users/:id`, `users/export.{xlsx,csv,pdf}`, `users/:id/{verify,suspend,reactivate,convert-to-bidder,delete}` | User CRUD/status/export; DELETE `/users/:id` also exists |
| `trials` | Trial monitoring |
| `email/status`, `email/analytics`, `email/analytics/:id`, `email/{toggle,test,retry-failed,settings,trace,suppressions}` | Gateway configuration, reports and operations |
| `publishing`, `publishing/release-held`, `social/today-tenders` | Publishing controls and social source selection |
| `integrations/worksuite`, `portals/organisation` | Connection/config and audited portal switch |
| `support`, `support/messages/:id`, `support/mailboxes` | Inbox status updates and mailbox provisioning |
| `blog`, `blog/:id` | List/detail/create/update/delete |
| `seo/{status,audit,recommendations,dashboard,report,indexnow}`, `seo/platforms/sync` | SEO service/config/report/actions |
| `seo/backlinks`, `seo/backlinks/{import,provider-sync,export.csv,outreach}`, `seo/backlinks/:id/verify`, `seo/backlinks/outreach/:id` | Backlink inventory/import/verification/outreach |
| `acquisition` | Acquisition analytics |

Marketing-specific proxy routes additionally live under `web/src/app/api/admin/marketing/[...path]/route.ts`, `social-oauth/[platform]/[action]`, and `marketing-storage/[...file]`; these depend on the separate Marketing Engine and cannot be replaced with a local fake content table.

### Authentication and permission boundaries

The PHP route group applies **`auth-jwt`, `tenant`, `group:staff`, `staff-role`, `entitlement:admin`**. Next's console requires the signed session to be staff. Access/refresh cookies are `th_at` / `th_rt`; `th_prof` is display-only signed profile data. Privileged integration services re-check `/api/v1/admin/session` and require live `owner`/`admin` role, rather than trusting stale frontend role labels. User export, role changes, payments, KYC and infrastructure actions are not equivalent to generic CRUD.

Do not copy existing production JWTs, secrets, cookies, organisation data, owner identity or API keys into LifeOS. A safe adapter needs a site-provided, scoped authorization mechanism and explicit mapping of each action. UI clones alone do not supply those permissions.

### Repository governance relevant to cloning

`D:\OFZ\tender\AGENTS.md` requires real-data-only UI, protected owner access, server-side provider credentials, source/brand restrictions and strict separation of TenderHub's production routing from CreativeFX. `web/AGENTS.md` directs implementers to that installed Next version's local guides before modifying Next code. This audit modified no Next or production code. Those deployment instructions are not authorization to deploy while cloning into LifeOS.

## Concrete LifeOS integration plan

1. Keep the registered website/domain/server metadata local. A connector session or verified site API determines actual available capabilities.
2. Reuse the searchable grouped admin navigation, overview, content/media/inquiry editors, SEO/settings, customer tables and operation histories as module concepts.
3. Use schema-driven standard fields for ordinary collection editing. Add custom adapters for nested site documents, uploads, role/governance actions, and multi-service reports.
4. For CreativeFX, map its native `x-admin-token` endpoints; preserve complete JSON documents and maintain separate media/inquiry flows.
5. For TenderHub, map the authenticated staff API and service-specific proxies. Preserve server-side authorization and don't infer capabilities from a guessed route.
6. Store no copied production records in a default LifeOS workspace. Empty/unconfigured states must remain honest.
7. Test adapters with isolated fixtures before any authorized connection. No action against either existing project or live service occurred in this audit.

This inventory is the basis for a feature-completeness comparison. It must not be presented as evidence that a new generic connector already implements a full clone of both panels.
