"use client";

import { useCallback, useEffect, useMemo, useState, type ReactNode } from "react";
import Image from "next/image";
import Link from "next/link";
import { ExternalLink, RefreshCw } from "lucide-react";
import { Toast } from "@/components/ds/overlay";
import { Alert, Badge, Card, CardBody, CardHeader, EmptyState, Table, TBody, TD, TH, THead, TR, buttonClass, type Tone } from "@/components/console/ui";
import { Button, ConfirmDialog, Field as FormField, Modal, Select, inputClass } from "@/components/console/interactive";
import { publicSourceLabel } from "@/lib/source-label";
import { MarketingConsoleClient } from "@/components/portal/marketing-client";
import { PostPreviewModal } from "@/components/portal/social-post-preview";

/**
 * Social Marketing panel: today's real TenderHub tenders, and an independent
 * Facebook / TikTok automation per tender. Every value shown comes from the
 * TenderHub API (tenders) or the marketing engine (posts, connections, worker);
 * an unreachable service shows its error, never placeholder data.
 */

type Platform = "facebook" | "instagram" | "tiktok";
const PLATFORMS: Platform[] = ["facebook", "instagram", "tiktok"];
const PLATFORM_LABEL: Record<Platform, string> = { facebook: "Facebook", instagram: "Instagram", tiktok: "TikTok" };
const APP_ENV: Record<Platform, string> = {
  facebook: "FACEBOOK_APP_ID / FACEBOOK_APP_SECRET",
  instagram: "FACEBOOK_APP_ID / FACEBOOK_APP_SECRET (or INSTAGRAM_APP_ID / INSTAGRAM_APP_SECRET)",
  tiktok: "TIKTOK_CLIENT_KEY / TIKTOK_CLIENT_SECRET",
};
const ZONE = "Asia/Colombo";

type TargetStatus = "disabled" | "skipped" | "pending" | "approved" | "scheduled" | "publishing" | "published" | "failed" | "cancelled";

interface Tender {
  id: number; reference: string | null; title: string; summary: string | null; organization: string | null; category: string | null;
  location: { district: string | null; province: string | null; detail: string | null };
  sector: string | null; estimated_value: number | null; currency: string; document_fee: number | null; bid_security: number | null;
  documents_count: number; published_at: string | null; closing_at: string | null; opening_at: string | null; collected_at: string | null;
  public_since: string | null; status: string; live_status: string; source_label: string; tender_url: string | null;
  has_image: boolean; promotable: boolean; blocker: string | null;
}

interface Target {
  id: number; source_id: string; platform: Platform; status: TargetStatus; post_id: number | null; tender_id: number | null;
  scheduled_at: string | null; next_attempt_at: string | null; attempt_count: number; published_at: string | null;
  external_post_id: string | null; post_url: string | null; remote_publish_id: string | null; last_error: string | null; last_error_at: string | null;
  updated_at: string | null; updated_by: string | null; title: string | null; reference: string | null; buyer: string | null; category: string | null;
  district: string | null; closing_at: string | null; tender_url: string | null; headline: string | null; caption: string | null; hashtags: string | null;
  poster_url: string | null; version_number: number | null;
}

interface Account {
  platform: Platform; label: string; app_configured: boolean; direct_login_available?: boolean; connected: boolean; source: "oauth" | "environment" | null; status: string;
  oauth_status?: string; fallback_active?: boolean;
  account_id: string | null; account_name: string | null; username: string | null; token_expires_at: string | null;
  refresh_expires_at: string | null; last_verified_at: string | null; last_error: string | null; connected_at: string | null;
  last_publish_at?: string | null; publishing_enabled?: boolean; capabilities?: { can_publish: boolean; can_publish_images: boolean; can_publish_video: boolean; can_schedule: boolean; publishing_available: boolean };
  temporarily_paused?: boolean; provider_pause_until?: string | null; provider_pause_reason?: string | null; provider_pause_requires_manual_resume?: boolean;
  pages: Array<{ id: string; name: string; can_publish: boolean }>;
  instagram_accounts?: Array<{ id: string; username: string | null; name: string | null; account_type: string | null; page_name: string }>;
  privacy_level?: string | null; privacy_level_options?: string[];
}

interface FacebookDiagnostic {
  authenticated_user_name: string | null; authenticated_user_id: string | null;
  token_type: string | null; token_valid: boolean | null; app_matches: boolean | null; identity_matches: boolean | null;
  granted_permissions: string[]; pages_returned: number | null; connectable_pages: number | null;
  expected_page_name: string; expected_page_found: boolean | null; outcome: string; error?: string | null;
  discovery_method?: "user_accounts" | "business_portfolio"; businesses_returned?: number | null; business_matched?: boolean | null;
  meta_error?: { code: number | null; type: string | null; message: string } | null; updated_at?: string | null;
}

interface InstagramDiagnostic {
  authenticated_user_name?: string | null; authenticated_user_id?: string | null; token_valid?: boolean | null;
  granted_permissions?: string[]; instagram_accounts_returned?: number | null; outcome?: string; error?: string | null;
  meta_error?: { code?: number | null; message?: string } | null; updated_at?: string | null;
  graph_endpoint?: string | null; meta_object_type?: string | null; requested_fields?: string | null;
  publishing_eligible?: boolean | null; verification_status?: string | null;
}

interface Summary {
  tabs: Record<string, number>;
  overdue: number;
  lastDailyRun?: { checked_at: string; discovered?: number; failed?: number; error?: string; platformErrors?: Partial<Record<Platform, string>>; blocked?: Array<{ source_id: number; reason: string }> } | null;
  lastWorkerRun: { startedAt: string; finishedAt?: string; claimed: number; published: number; failed: number; retrying: number; awaitingConfirmation: number } | null;
}

interface IntegrityStatus {
  configured: boolean;
  verified: boolean;
  reason: string | null;
  latest: { sequence: number; commit: string; tree_hash: string; file_count: number; sealed_at: string; verified_at: string } | null;
  chain: Array<{ sequence: number; commit: string; tree_hash: string; file_count: number; sealed_at: string }>;
}

const STATUS_BADGE: Record<TargetStatus, [Tone, string]> = {
  disabled: ["gray", "Automation off"], skipped: ["gray", "Skipped"], pending: ["yellow", "Pending"],
  approved: ["primary", "Approved · publishing now"], scheduled: ["primary", "Scheduled"], publishing: ["yellow", "Publishing…"],
  published: ["green", "Published"], failed: ["red", "Failed"], cancelled: ["gray", "Cancelled"],
};

const TABS = [
  { key: "today", label: "Today's Tenders" },
  { key: "pending", label: "Pending" },
  { key: "approved", label: "Approved" },
  { key: "scheduled", label: "Scheduled" },
  { key: "published", label: "Published" },
  { key: "failed", label: "Failed" },
  { key: "skipped", label: "Skipped" },
  { key: "connections", label: "Connections" },
  { key: "integrity", label: "Integrity Chain" },
  { key: "drafts", label: "Draft library" },
] as const;
type TabKey = (typeof TABS)[number]["key"];

// ---------------------------------------------------------------- helpers

/** API timestamps are UTC ("YYYY-MM-DD HH:MM:SS" or ISO); shown in Sri Lanka time. */
function parseUtc(value: string | null | undefined): Date | null {
  if (!value) return null;
  const d = new Date(/[zZ]|[+-]\d{2}:\d{2}$/.test(value) ? value : value.replace(" ", "T") + "Z");
  return Number.isNaN(d.getTime()) ? null : d;
}
const fmt = new Intl.DateTimeFormat("en-GB", { timeZone: ZONE, dateStyle: "medium", timeStyle: "short" });
const when = (value: string | null | undefined) => { const d = parseUtc(value); return d ? fmt.format(d) : "—"; };
const colomboToday = () => new Intl.DateTimeFormat("en-CA", { timeZone: ZONE }).format(new Date());
/** datetime-local value (Sri Lanka wall time, no DST) -> ISO with offset. */
const toColomboIso = (local: string) => `${local.length === 16 ? `${local}:00` : local}+05:30`;
/** Default schedule suggestion: next full hour, Sri Lanka time. */
function nextHourLocal(): string {
  const d = new Date(Date.now() + 5.5 * 3600 * 1000 + 3600 * 1000);
  return `${d.toISOString().slice(0, 13)}:00`;
}
const money = (value: number | null, currency = "LKR") => (value == null ? null : `${currency} ${value.toLocaleString("en-LK", { maximumFractionDigits: 2 })}`);

async function call<T = unknown>(url: string, init?: { method?: string; body?: unknown }): Promise<{ ok: boolean; status: number; data?: T; meta?: Record<string, unknown>; error?: string; raw?: Record<string, unknown> }> {
  try {
    const res = await fetch(url, {
      method: init?.method ?? "GET",
      headers: init?.body !== undefined ? { "Content-Type": "application/json" } : undefined,
      body: init?.body !== undefined ? JSON.stringify(init.body) : undefined,
      cache: "no-store",
    });
    const json = await res.json().catch(() => ({}));
    const ok = res.ok && json.success !== false;
    return { ok, status: res.status, data: json.data, meta: json.meta, error: ok ? undefined : (json.error || json.detail || `Request failed (HTTP ${res.status}).`), raw: json };
  } catch (e) {
    return { ok: false, status: 0, error: e instanceof Error ? e.message : "Network error." };
  }
}

function TargetBadge({ status }: { status: TargetStatus }) {
  const [tone, label] = STATUS_BADGE[status] ?? ["gray", status];
  return <Badge tone={tone} dot>{label}</Badge>;
}

function Field({ label, children }: { label: string; children: ReactNode }) {
  return <div className="min-w-0"><dt className="text-xs font-medium text-gray-500">{label}</dt><dd className="mt-0.5 break-words text-gray-900">{children || "—"}</dd></div>;
}

function ErrorBox({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <Alert tone="error" title={message} actions={onRetry ? <Button variant="secondary" size="sm" onClick={onRetry}>Retry</Button> : undefined} />
  );
}

// ------------------------------------------------------------- component

export function SocialMarketingClient({ initialTab, flash }: { initialTab?: string; flash?: { tone: "ok" | "bad"; message: string } | null }) {
  const [tab, setTab] = useState<TabKey>(TABS.some((t) => t.key === initialTab) ? (initialTab as TabKey) : "today");
  const [toast, setToast] = useState<{ m: string; t: "ok" | "bad" } | null>(flash ? { m: flash.message, t: flash.tone } : null);
  const [summary, setSummary] = useState<Summary | null>(null);
  const [summaryError, setSummaryError] = useState<string | null>(null);
  const [accounts, setAccounts] = useState<Record<Platform, Account> | null>(null);
  const [accountsError, setAccountsError] = useState<string | null>(null);
  const [version, setVersion] = useState(0);
  const refreshAll = useCallback(() => setVersion((v) => v + 1), []);

  useEffect(() => {
    let live = true;
    (async () => {
      const [s, a] = await Promise.all([call<Summary>("/api/admin/marketing/social/summary"), call<Record<Platform, Account>>("/api/admin/marketing/social/accounts")]);
      if (!live) return;
      if (s.ok && s.data) { setSummary(s.data); setSummaryError(null); } else setSummaryError(s.error ?? "Unavailable.");
      if (a.ok && a.data) { setAccounts(a.data); setAccountsError(null); } else setAccountsError(a.error ?? "Unavailable.");
    })();
    return () => { live = false; };
  }, [version]);

  const notify = useCallback((m: string, t: "ok" | "bad" = "ok") => setToast({ m, t }), []);

  return (
    <div className="space-y-5">
      <StatusStrip summary={summary} summaryError={summaryError} accounts={accounts} accountsError={accountsError} onRefresh={refreshAll} />

      <nav className="-mx-1 flex gap-x-6 overflow-x-auto border-b border-gray-200 px-1" aria-label="Social marketing sections">
        {TABS.map((t) => {
          const count = summary?.tabs?.[t.key];
          return (
            <button key={t.key} type="button" onClick={() => setTab(t.key)} aria-current={tab === t.key ? "page" : undefined}
              className={`flex shrink-0 items-center whitespace-nowrap border-b-2 px-1 py-3 text-sm font-medium transition-colors ${tab === t.key ? "border-primary-500 text-primary-600" : "border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700"}`}>
              {t.label}{typeof count === "number" ? <span className={`ml-2 rounded-full px-2 py-0.5 text-xs font-medium ${tab === t.key ? "bg-primary-100 text-primary-600" : "bg-gray-100 text-gray-900"}`}>{count}</span> : null}
            </button>
          );
        })}
      </nav>

      {tab === "today" && <TodayTenders key={version} accounts={accounts} notify={notify} onChanged={refreshAll} />}
      {["pending", "approved", "scheduled", "published", "failed", "skipped"].includes(tab) && (
        <PostsTab key={`${tab}-${version}`} tab={tab} accounts={accounts} notify={notify} onChanged={refreshAll} />
      )}
      {tab === "connections" && <Connections accounts={accounts} error={accountsError} notify={notify} onChanged={refreshAll} />}
      {tab === "integrity" && <IntegrityPanel />}
      {tab === "drafts" && (
        <div className="space-y-3">
          <p className="text-xs text-gray-500">All generated post drafts (artwork and captions). &ldquo;Approve &amp; Publish&rdquo; here posts to Facebook immediately through the same queue as the tabs above.</p>
          <MarketingConsoleClient />
        </div>
      )}

      {toast && <Toast message={toast.m} tone={toast.t} onDone={() => setToast(null)} />}
    </div>
  );
}

function IntegrityPanel() {
  const [integrity, setIntegrity] = useState<IntegrityStatus | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const load = useCallback(async () => {
    const response = await call<IntegrityStatus>("/api/admin/marketing/social/integrity");
    if (response.ok && response.data) { setIntegrity(response.data); setError(null); }
    else setError(response.error ?? "Integrity status is unavailable.");
    setLoading(false);
  }, []);
  useEffect(() => {
    let live = true;
    void call<IntegrityStatus>("/api/admin/marketing/social/integrity").then((response) => {
      if (!live) return;
      if (response.ok && response.data) { setIntegrity(response.data); setError(null); }
      else setError(response.error ?? "Integrity status is unavailable.");
      setLoading(false);
    });
    return () => { live = false; };
  }, []);

  if (loading) return <Card><CardBody><p role="status" className="text-sm text-gray-500">Loading signed deployment chain…</p></CardBody></Card>;
  if (error) return <ErrorBox message={error} onRetry={load} />;
  if (!integrity) return null;
  return (
    <section className="space-y-4">
      <div className={`rounded-lg p-5 ring-1 ${integrity.verified ? "bg-green-50 ring-green-600/20" : "bg-red-50 ring-red-600/20"}`}>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <p className="text-sm font-medium text-gray-500">Automation release integrity</p>
            <h2 className="mt-1 text-lg font-semibold text-gray-900">{integrity.verified ? "Signed chain verified" : "Verification required"}</h2>
          </div>
          <Badge tone={integrity.verified ? "green" : "red"} dot>{integrity.verified ? "Verified" : "Fail closed"}</Badge>
        </div>
        {integrity.reason && <p className="mt-3 text-sm text-red-800">{integrity.reason}</p>}
        {integrity.latest && <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
          <Field label="Chain block">#{integrity.latest.sequence}</Field>
          <Field label="Protected files">{integrity.latest.file_count}</Field>
          <Field label="Commit"><code>{integrity.latest.commit.slice(0, 12)}</code></Field>
          <Field label="Last verified">{when(integrity.latest.verified_at)}</Field>
          <div className="sm:col-span-2 lg:col-span-4"><Field label="SHA-256 tree hash"><code className="break-all text-xs">{integrity.latest.tree_hash}</code></Field></div>
        </dl>}
      </div>
      <Card>
        <CardHeader title="Signed deployment history" description="Latest 20 real HMAC-chained attestations. Secrets and signatures are never sent to the browser."
          actions={<Button variant="secondary" size="sm" onClick={load}><RefreshCw className="size-3.5" aria-hidden="true" /> Refresh</Button>} />
        {integrity.chain.length === 0 ? <EmptyState title="No signed production deployment exists yet." /> : (
          <Table>
            <THead><TH>Block</TH><TH>Sealed</TH><TH>Commit</TH><TH align="right">Files</TH><TH>Tree hash</TH></THead>
            <TBody>
              {integrity.chain.map((entry) => <TR key={entry.sequence}><TD strong>#{entry.sequence}</TD><TD className="whitespace-nowrap">{when(entry.sealed_at)}</TD><TD className="font-mono text-xs">{entry.commit.slice(0, 12)}</TD><TD align="right" className="tabular-nums">{entry.file_count}</TD><TD className="max-w-64 truncate font-mono text-xs"><span title={entry.tree_hash}>{entry.tree_hash}</span></TD></TR>)}
            </TBody>
          </Table>
        )}
      </Card>
      <p className="text-xs text-gray-500">Enforcement is independent of README files: CI/CD refuses dirty or unsigned protected automation paths. Repository branch protection and Code Owner review must remain enabled at GitHub account level.</p>
    </section>
  );
}

// ------------------------------------------------------------ status strip

function StatusStrip({ summary, summaryError, accounts, accountsError, onRefresh }: {
  summary: Summary | null; summaryError: string | null; accounts: Record<Platform, Account> | null; accountsError: string | null; onRefresh: () => void;
}) {
  const run = summary?.lastWorkerRun;
  return (
    <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      {PLATFORMS.map((p) => {
        const a = accounts?.[p];
        return (
          <div key={p} className="min-w-0 rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-900/5">
            <p className="text-sm font-medium text-gray-500">{PLATFORM_LABEL[p]}</p>
            {accountsError ? <p className="mt-1 text-sm text-red-700">{accountsError}</p>
              : !a ? <p className="mt-1 text-sm text-gray-400">Loading…</p>
              : a.connected ? <p className={`mt-1 text-sm font-semibold ${a.temporarily_paused ? "text-yellow-700" : "text-green-700"}`}>{a.temporarily_paused ? "Connected · publishing paused" : p === "facebook" ? `Facebook Connected${a.account_name ? ` — ${a.account_name}` : ""}` : `Connected${a.account_name ? ` · ${a.account_name}` : ""}${a.username ? ` (@${a.username})` : ""}`}</p>
              : <p className="mt-1 text-sm font-semibold text-yellow-700">{a.status === "needs_page" ? "Choose a Page" : p === "instagram" && /2207051|restrict certain activity/i.test(a.last_error ?? "") ? "Publishing paused by Meta" : a.status === "error" ? "Reconnect required" : "Not connected"}</p>}
            {a?.last_error && <p className={`mt-1 line-clamp-2 text-xs ${a.temporarily_paused ? "text-yellow-800" : "text-red-700"}`}>{a.last_error}</p>}
          </div>
        );
      })}
      <div className="min-w-0 rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-900/5">
        <div className="flex items-start justify-between gap-2">
          <p className="text-sm font-medium text-gray-500">Publishing worker</p>
          <button type="button" onClick={onRefresh} className="rounded-md p-1 text-gray-400 hover:text-gray-700" aria-label="Refresh"><RefreshCw className="size-3.5" /></button>
        </div>
        {summaryError ? <p className="mt-1 text-sm text-red-700">{summaryError}</p>
          : !summary ? <p className="mt-1 text-sm text-gray-400">Loading…</p>
          : <>
            <p className="mt-1 text-sm text-gray-800">{run ? <>Last run {when(run.finishedAt ?? run.startedAt)} · {run.published} published, {run.failed} failed{run.awaitingConfirmation ? `, ${run.awaitingConfirmation} awaiting TikTok` : ""}</> : "Has not run yet."}</p>
            {summary.lastDailyRun?.error && <p role="alert" className="mt-1 text-xs text-red-700">Daily scheduling: {summary.lastDailyRun.error}</p>}
            {Object.entries(summary.lastDailyRun?.platformErrors ?? {}).map(([platform, message]) => <p role="alert" key={platform} className="mt-1 text-xs text-red-700">{PLATFORM_LABEL[platform as Platform]} scheduling paused: {message}</p>)}
            {!!summary.lastDailyRun?.failed && <p role="alert" className="mt-1 text-xs text-red-700">{summary.lastDailyRun.failed} daily target(s) could not be prepared. Check the tender artwork and connection errors.</p>}
            {!!summary.lastDailyRun?.blocked?.length && <p className="mt-1 text-xs text-yellow-700">{summary.lastDailyRun.blocked.length} tender(s) cannot be promoted; see the blockers in Today&apos;s Tenders.</p>}
            {summary.overdue > 0 && <p className="mt-1 text-xs font-semibold text-red-700">{summary.overdue} post(s) overdue by more than 5 minutes — check worker and platform errors.</p>}
          </>}
      </div>
    </section>
  );
}

// ---------------------------------------------------------- today's tenders

function TodayTenders({ accounts, notify, onChanged }: { accounts: Record<Platform, Account> | null; notify: (m: string, t?: "ok" | "bad") => void; onChanged: () => void }) {
  const [date, setDate] = useState(colomboToday());
  const [tenders, setTenders] = useState<Tender[] | null>(null);
  const [meta, setMeta] = useState<{ total?: number; returned?: number; limit?: number } | null>(null);
  const [targets, setTargets] = useState<Target[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [targetError, setTargetError] = useState<string | null>(null);
  const [filter, setFilter] = useState<"all" | "undecided" | "decided">("all");
  const [automate, setAutomate] = useState<Tender | null>(null);
  const [skip, setSkip] = useState<Tender | null>(null);
  const [preview, setPreview] = useState<Tender | null>(null);
  const [busy, setBusy] = useState<number | null>(null);
  const [reload, setReload] = useState(0);

  useEffect(() => {
    let live = true;
    (async () => {
      setError(null);
      const res = await call<Tender[]>(`/api/admin/social/today-tenders?date=${encodeURIComponent(date)}`);
      if (!live) return;
      if (!res.ok || !res.data) { setError(res.error ?? "Today's tenders could not be loaded."); setTenders(null); return; }
      setTenders(res.data);
      setMeta((res.meta as typeof meta) ?? null);
      const ids = res.data.map((t) => t.id);
      if (ids.length === 0) { setTargets([]); return; }
      const tg = await call<Target[]>(`/api/admin/marketing/social/targets?source_ids=${ids.join(",")}&limit=500`);
      if (!live) return;
      if (tg.ok && tg.data) { setTargets(tg.data); setTargetError(null); } else setTargetError(tg.error ?? "Post status unavailable.");
    })();
    return () => { live = false; };
  }, [date, reload]);

  // While something is being published, follow it to the real result.
  const inFlight = targets.some((t) => t.status === "approved" || t.status === "publishing");
  useEffect(() => {
    if (!inFlight) return;
    const timer = setInterval(() => setReload((r) => r + 1), 5000);
    return () => clearInterval(timer);
  }, [inFlight]);

  const byTender = useMemo(() => {
    const map = new Map<string, Partial<Record<Platform, Target>>>();
    for (const t of targets) map.set(t.source_id, { ...(map.get(t.source_id) ?? {}), [t.platform]: t });
    return map;
  }, [targets]);

  const visible = (tenders ?? []).filter((t) => {
    const decided = byTender.has(String(t.id));
    return filter === "all" || (filter === "decided" ? decided : !decided);
  });

  const changed = () => { setReload((r) => r + 1); onChanged(); };

  const [busyBulk, setBusyBulk] = useState<string | null>(null);

  async function scheduleBulk(targetPlatform: "all" | Platform) {
    if (date !== colomboToday() || !tenders?.length) return;
    const promotable = tenders.filter((t) => t.promotable).map((t) => t.id);
    if (!promotable.length) { notify("There are no promotable TenderHub posts collected today.", "bad"); return; }
    setBusyBulk(targetPlatform);

    if (targetPlatform === "all") {
      const activePlatforms = PLATFORMS.filter((p) => accounts?.[p]?.connected);
      if (!activePlatforms.length) {
        notify("No social accounts are connected. Connect Facebook or Instagram in Connections first.", "bad");
        setBusyBulk(null);
        return;
      }
      const res = await call<{
        scheduled: number;
        alreadyPublished: number;
        failed: number;
        platforms: Record<string, { scheduled: number; poster_count: number; interval_minutes: number }>;
      }>("/api/admin/marketing/social/bulk/all", {
        method: "POST",
        body: { date, source_ids: promotable, platforms: activePlatforms },
      });
      setBusyBulk(null);
      if (!res.ok) { notify(res.error ?? "Bulk automation could not be enabled.", "bad"); return; }
      const labels = activePlatforms.map((p) => PLATFORM_LABEL[p]).join(" & ");
      notify(`${res.data?.scheduled ?? 0} post(s) across ${labels} scheduled before midnight in Sri Lanka. ${res.data?.alreadyPublished ?? 0} already published post(s) protected.`);
    } else {
      const res = await call<{ scheduled: number; poster_count: number; interval_minutes: number; alreadyPublished: number; failed: number }>(`/api/admin/marketing/social/bulk/${targetPlatform}`, {
        method: "POST", body: { date, source_ids: promotable },
      });
      setBusyBulk(null);
      if (!res.ok) { notify(res.error ?? `${PLATFORM_LABEL[targetPlatform]} bulk automation could not be enabled.`, "bad"); return; }
      notify(`${res.data?.scheduled ?? 0} ${PLATFORM_LABEL[targetPlatform]} poster(s) scheduled before midnight in Sri Lanka, every ${res.data?.interval_minutes ?? 0} minute(s). ${res.data?.alreadyPublished ?? 0} already published post(s) protected.`);
    }
    changed();
  }

  async function quickSchedulePlatform(t: Tender, p: Platform, targetTimeIso?: string | null) {
    setBusy(t.id);
    const scheduledAt = targetTimeIso || toColomboIso(nextHourLocal());
    const res = await call<Target[]>(`/api/admin/marketing/social/tenders/${t.id}/decision`, {
      method: "POST",
      body: { action: "enable", platforms: { [p]: { enabled: true, mode: "schedule", scheduled_at: scheduledAt } } },
    });
    setBusy(null);
    if (!res.ok) { notify(res.error ?? `Could not schedule ${PLATFORM_LABEL[p]}.`, "bad"); return; }
    notify(`${PLATFORM_LABEL[p]} scheduled for ${when(scheduledAt)}.`);
    changed();
  }

  async function doSkip(t: Tender) {
    setBusy(t.id);
    const res = await call(`/api/admin/marketing/social/tenders/${t.id}/decision`, { method: "POST", body: { action: "skip" } });
    setBusy(null);
    if (res.ok) { notify(`Skipped: no social posts will be made for ${t.reference ?? `notice #${t.id}`}.`); changed(); }
    else notify(res.error ?? "Could not skip.", "bad");
  }

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 className="text-base font-semibold text-gray-900">Tenders collected by TenderHub</h2>
          <p className="text-xs text-gray-500">Every tender first collected on the chosen day (Sri Lanka time). Automation never runs for a tender until you enable it here.</p>
        </div>
        <div className="flex flex-wrap items-end gap-2">
          <FormField label="Day" htmlFor="social-day">
            <input id="social-day" type="date" value={date} max={colomboToday()} onChange={(e) => e.target.value && setDate(e.target.value)} className={inputClass} />
          </FormField>
          <Select label="Show" className="w-48" value={filter} onChange={setFilter}
            options={[{ value: "all", label: "All" }, { value: "undecided", label: "Awaiting decision" }, { value: "decided", label: "Decided" }]} />
          <Button
            onClick={() => scheduleBulk("all")}
            busy={busyBulk === "all"}
            disabled={date !== colomboToday() || !tenders?.length}
            title={date !== colomboToday() ? "Bulk scheduling is restricted to today's posts; yesterday's posts are never reposted." : "Schedule today's tenders before midnight in Sri Lanka for all connected social platforms (Facebook & Instagram)"}
          >
            Schedule all before midnight
          </Button>
          <Button
            variant="secondary"
            onClick={() => scheduleBulk("facebook")}
            busy={busyBulk === "facebook"}
            disabled={date !== colomboToday() || !tenders?.length || !accounts?.facebook?.connected}
            title={!accounts?.facebook?.connected ? "Connect Facebook in Connections" : "Schedule today's tenders to Facebook before midnight"}
          >
            Schedule Facebook
          </Button>
          <Button
            variant="secondary"
            onClick={() => scheduleBulk("instagram")}
            busy={busyBulk === "instagram"}
            disabled={date !== colomboToday() || !tenders?.length || !accounts?.instagram?.connected}
            title={!accounts?.instagram?.connected ? "Connect Instagram in Connections" : "Schedule today's tenders to Instagram before midnight"}
          >
            Schedule Instagram
          </Button>
          {accounts?.tiktok?.connected && (
            <Button
              variant="secondary"
              onClick={() => scheduleBulk("tiktok")}
              busy={busyBulk === "tiktok"}
              disabled={date !== colomboToday() || !tenders?.length}
            >
              Schedule TikTok
            </Button>
          )}
          <Button variant="secondary" onClick={() => setReload((r) => r + 1)}><RefreshCw className="size-3.5" /> Refresh</Button>
        </div>
      </div>

      {error ? <ErrorBox message={error} onRetry={() => setReload((r) => r + 1)} />
        : tenders === null ? <p role="status" className="py-6 text-sm text-gray-500">Loading tenders…</p>
        : <>
          <p className="text-xs text-gray-500">
            {meta?.total ?? tenders.length} tender(s) collected on {date}{meta && meta.total !== undefined && meta.returned !== undefined && meta.total > meta.returned ? ` — showing the latest ${meta.returned}` : ""}.
          </p>
          {targetError && <ErrorBox message={`Post status could not be loaded: ${targetError}`} />}
          {visible.length === 0 && <Card><EmptyState title={tenders.length === 0 ? "TenderHub has not collected any tenders on this day yet." : "No tenders match this filter."} /></Card>}
          <ul className="space-y-3">
            {visible.map((t) => {
              const decided = byTender.get(String(t.id));
              return (
                <li key={t.id} className="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-900/5 sm:p-5">
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <Badge tone={t.live_status === "closed" ? "gray" : t.status === "published" ? "green" : "yellow"}>{t.status === "published" ? t.live_status.replace("_", " ") : t.status}</Badge>
                        {t.reference && <span className="font-mono text-xs text-gray-500">{t.reference}</span>}
                        <span className="text-xs text-gray-400">{publicSourceLabel(t)}</span>
                      </div>
                      <h3 className="mt-1 text-sm font-semibold leading-snug break-words text-gray-900">{t.title}</h3>
                    </div>
                    <div className="flex flex-wrap gap-2">
                      <Button size="sm" variant="ghost" onClick={() => setPreview(t)}>Preview post</Button>
                      {!decided || Object.values(decided).every((x) => x && ["skipped", "disabled", "cancelled"].includes(x.status)) ? <>
                        <Button size="sm" onClick={() => setAutomate(t)} disabled={!t.promotable} title={t.blocker ?? undefined}>Enable Automation</Button>
                        {!(decided && Object.values(decided).every((x) => x?.status === "skipped")) && <Button size="sm" variant="secondary" busy={busy === t.id} onClick={() => setSkip(t)}>Skip</Button>}
                      </> : <>
                        <Button size="sm" variant="secondary" onClick={() => setAutomate(t)} disabled={!t.promotable}>Change automation</Button>
                        {accounts?.instagram?.connected && !decided?.instagram && t.promotable && (
                          <Button size="sm" variant="secondary" busy={busy === t.id} onClick={() => quickSchedulePlatform(t, "instagram", decided?.facebook?.scheduled_at)}>
                            + Schedule Instagram
                          </Button>
                        )}
                        {accounts?.facebook?.connected && !decided?.facebook && t.promotable && (
                          <Button size="sm" variant="secondary" busy={busy === t.id} onClick={() => quickSchedulePlatform(t, "facebook", decided?.instagram?.scheduled_at)}>
                            + Schedule Facebook
                          </Button>
                        )}
                      </>}
                    </div>
                  </div>
                  <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-xs sm:grid-cols-4">
                    <Field label="Organization">{t.organization}</Field>
                    <Field label="Category">{t.category}</Field>
                    <Field label="Location">{[t.location.district, t.location.province].filter(Boolean).join(", ") || t.location.detail}</Field>
                    <Field label="Collected">{when(t.collected_at)}</Field>
                    <Field label="Published">{when(t.published_at)}</Field>
                    <Field label="Closing">{when(t.closing_at)}</Field>
                    <Field label="Estimated value">{money(t.estimated_value, t.currency)}</Field>
                    <Field label="Tender URL">{t.tender_url ? <a href={t.tender_url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-primary-600 hover:underline">tenderhub.lk <ExternalLink className="size-3" /></a> : "Not public yet"}</Field>
                    {t.document_fee != null && <Field label="Document fee">{money(t.document_fee, t.currency)}</Field>}
                    {t.bid_security != null && <Field label="Bid security">{money(t.bid_security, t.currency)}</Field>}
                    {t.opening_at && <Field label="Opening">{when(t.opening_at)}</Field>}
                    <Field label="Documents">{t.documents_count}</Field>
                  </dl>
                  {t.blocker && <p className="mt-3 rounded-md bg-yellow-50 px-3 py-2 text-xs text-yellow-800">Cannot be promoted: {t.blocker}</p>}
                  {decided && (
                    <div className="mt-3 grid gap-2 sm:grid-cols-2">
                      {PLATFORMS.map((p) => decided[p] ? <TargetCard key={p} target={decided[p]!} accounts={accounts} notify={notify} onChanged={changed} compact /> : null)}
                    </div>
                  )}
                </li>
              );
            })}
          </ul>
        </>}

      {automate && <AutomateModal tender={automate} existing={byTender.get(String(automate.id))} accounts={accounts} onClose={() => setAutomate(null)}
        onDone={(m) => { setAutomate(null); notify(m); changed(); }} onError={(m) => notify(m, "bad")} />}
      {preview && <PostPreviewModal sourceId={preview.id} title={preview.title} canGenerate={preview.promotable} onClose={() => setPreview(null)} />}
      <ConfirmDialog open={!!skip} onClose={() => setSkip(null)} onConfirm={() => { if (skip) void doSkip(skip); setSkip(null); }} title="Skip this tender?" destructive={false}
        message={<p>No Facebook or TikTok post will be created for <strong>{skip?.title}</strong>. You can still enable it later.</p>} confirmLabel="Skip tender" />
    </section>
  );
}

// -------------------------------------------------------- automate modal

type Choice = { enabled: boolean; mode: "now" | "schedule" | "hold"; local: string };

function AutomateModal({ tender, existing, accounts, onClose, onDone, onError }: {
  tender: Tender; existing?: Partial<Record<Platform, Target>>; accounts: Record<Platform, Account> | null;
  onClose: () => void; onDone: (m: string) => void; onError: (m: string) => void;
}) {
  const [choices, setChoices] = useState<Record<Platform, Choice>>(() => {
    const init: Record<Platform, Choice> = {
      facebook: { enabled: accounts?.facebook?.connected ?? true, mode: "schedule", local: nextHourLocal() },
      instagram: { enabled: accounts?.instagram?.connected ?? false, mode: "schedule", local: nextHourLocal() },
      tiktok: { enabled: accounts?.tiktok?.connected ?? false, mode: "schedule", local: nextHourLocal() },
    };
    if (existing) {
      for (const p of PLATFORMS) {
        const ex = existing[p];
        if (ex) {
          const active = !["disabled", "skipped", "cancelled"].includes(ex.status);
          init[p] = {
            enabled: active,
            mode: ex.status === "approved" ? "now" : ex.status === "pending" ? "hold" : "schedule",
            local: ex.scheduled_at ? new Date(parseUtc(ex.scheduled_at)!.getTime() + 5.5 * 3600 * 1000).toISOString().slice(0, 16) : nextHourLocal(),
          };
        }
      }
    }
    return init;
  });
  const [saving, setSaving] = useState(false);
  const set = (p: Platform, patch: Partial<Choice>) => setChoices((c) => ({ ...c, [p]: { ...c[p], ...patch } }));
  const locked = (p: Platform) => existing?.[p] && ["published", "publishing"].includes(existing[p]!.status);

  async function save() {
    const platforms: Record<string, unknown> = {};
    for (const p of PLATFORMS) {
      if (locked(p)) continue;
      const c = choices[p];
      platforms[p] = c.enabled ? { enabled: true, mode: c.mode, ...(c.mode === "schedule" ? { scheduled_at: toColomboIso(c.local) } : {}) } : { enabled: false };
    }
    setSaving(true);
    const res = await call<Target[]>(`/api/admin/marketing/social/tenders/${tender.id}/decision`, { method: "POST", body: { action: "enable", platforms } });
    setSaving(false);
    if (!res.ok) { onError(res.error ?? "Automation could not be saved."); return; }
    const parts = (res.data ?? []).map((t) => `${PLATFORM_LABEL[t.platform]}: ${STATUS_BADGE[t.status]?.[1] ?? t.status}${t.status === "scheduled" ? ` ${when(t.scheduled_at)}` : ""}`);
    onDone(`Automation saved — ${parts.join(" · ")}`);
  }

  return (
    <Modal open onClose={onClose} title="Enable automation" size="lg"
      footer={<><Button variant="secondary" onClick={onClose}>Cancel</Button><Button onClick={save} busy={saving}>Save automation</Button></>}>
      <p className="font-semibold text-gray-900">{tender.title}</p>
      <p className="mt-0.5 text-xs text-gray-500">{tender.reference} · closes {when(tender.closing_at)}</p>
      <p className="mt-3 text-xs text-gray-600">Each platform is independent. The post artwork and caption are generated from this tender when you save; edit them afterwards from any post row. Times are Sri Lanka time (UTC+05:30).</p>
      <div className="mt-4 space-y-3">
        {PLATFORMS.map((p) => {
          const c = choices[p];
          const account = accounts?.[p];
          if (locked(p)) return <div key={p} className="flex flex-wrap items-center gap-1 rounded-lg border border-gray-200 p-3 text-sm text-gray-700"><strong>{PLATFORM_LABEL[p]}</strong>: <TargetBadge status={existing![p]!.status} /> — already {existing![p]!.status}, not changed.</div>;
          return (
            <fieldset key={p} className="rounded-lg border border-gray-200 p-3">
              <legend className="px-1 text-sm font-semibold text-gray-900">{PLATFORM_LABEL[p]}</legend>
              <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="size-4 rounded-sm border-gray-300 text-primary-600 focus:ring-primary-600" checked={c.enabled} onChange={(e) => set(p, { enabled: e.target.checked })} /> Automate a {PLATFORM_LABEL[p]} post</label>
              {account && !account.connected && <p className="mt-1 text-xs text-yellow-700">{PLATFORM_LABEL[p]} is not connected. You can schedule now and connect before the time; Publish Now needs a connection.</p>}
              {c.enabled && (
                <div className="mt-2 space-y-2 pl-6 text-sm">
                  <label className="flex items-center gap-2"><input type="radio" className="size-4 border-gray-300 text-primary-600 focus:ring-primary-600" name={`${p}-mode`} checked={c.mode === "now"} onChange={() => set(p, { mode: "now" })} /> Publish now</label>
                  <label className="flex flex-wrap items-center gap-2"><input type="radio" className="size-4 border-gray-300 text-primary-600 focus:ring-primary-600" name={`${p}-mode`} checked={c.mode === "schedule"} onChange={() => set(p, { mode: "schedule" })} /> Schedule at
                    <input type="datetime-local" value={c.local} disabled={c.mode !== "schedule"} onChange={(e) => set(p, { local: e.target.value })} className={`${inputClass} w-auto`} />
                  </label>
                  <label className="flex items-center gap-2"><input type="radio" className="size-4 border-gray-300 text-primary-600 focus:ring-primary-600" name={`${p}-mode`} checked={c.mode === "hold"} onChange={() => set(p, { mode: "hold" })} /> Enable now, decide the time later (Pending)</label>
                </div>
              )}
            </fieldset>
          );
        })}
      </div>
    </Modal>
  );
}

// ---------------------------------------------------------------- posts tab

function PostsTab({ tab, accounts, notify, onChanged }: { tab: string; accounts: Record<Platform, Account> | null; notify: (m: string, t?: "ok" | "bad") => void; onChanged: () => void }) {
  const [rows, setRows] = useState<Target[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [platform, setPlatform] = useState<"" | Platform>("");
  const [reload, setReload] = useState(0);

  useEffect(() => {
    let live = true;
    (async () => {
      const res = await call<Target[]>(`/api/admin/marketing/social/targets?tab=${tab}${platform ? `&platform=${platform}` : ""}&limit=500`);
      if (!live) return;
      if (res.ok && res.data) { setRows(res.data); setError(null); } else setError(res.error ?? "Posts could not be loaded.");
    })();
    return () => { live = false; };
  }, [tab, platform, reload]);

  const inFlight = rows?.some((t) => t.status === "approved" || t.status === "publishing");
  useEffect(() => {
    if (!inFlight) return;
    const timer = setInterval(() => setReload((r) => r + 1), 5000);
    return () => clearInterval(timer);
  }, [inFlight]);

  const help: Record<string, string> = {
    pending: "Automation enabled, waiting for you to choose Publish Now or a time.",
    approved: "Approved for immediate publishing. The worker picks these up within a minute (immediately after Publish Now).",
    scheduled: "Each post publishes at its own time through the backend worker — no browser needs to be open.",
    published: "Posts the platform accepted, with the platform's own post id and link.",
    failed: "The platform's exact error is shown. Fix the cause, then Retry.",
    skipped: "Skipped tenders, cancelled posts and platforms with automation switched off.",
  };

  return (
    <section className="space-y-3">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <p className="text-xs text-gray-500">{help[tab]}</p>
        <div className="flex flex-wrap items-end gap-2">
          <Select<"" | Platform> label="Platform" className="w-40" value={platform} onChange={setPlatform}
            options={[{ value: "", label: "Both" }, { value: "facebook", label: "Facebook" }, { value: "tiktok", label: "TikTok" }]} />
          <Button variant="secondary" onClick={() => setReload((r) => r + 1)}><RefreshCw className="size-3.5" /> Refresh</Button>
        </div>
      </div>
      {error ? <ErrorBox message={error} onRetry={() => setReload((r) => r + 1)} />
        : rows === null ? <p role="status" className="py-6 text-sm text-gray-500">Loading posts…</p>
        : rows.length === 0 ? <Card><EmptyState title="No posts in this list." /></Card>
        : <ul className="space-y-3">{rows.map((t) => <li key={t.id}><TargetCard target={t} accounts={accounts} notify={notify} onChanged={() => { setReload((r) => r + 1); onChanged(); }} /></li>)}</ul>}
    </section>
  );
}

// ------------------------------------------------------------- target card

function TargetCard({ target: t, accounts, notify, onChanged, compact }: {
  target: Target; accounts: Record<Platform, Account> | null; notify: (m: string, t?: "ok" | "bad") => void; onChanged: () => void; compact?: boolean;
}) {
  const [busy, setBusy] = useState<string | null>(null);
  const [schedule, setSchedule] = useState(false);
  const [edit, setEdit] = useState(false);
  const [confirmNow, setConfirmNow] = useState(false);
  const locked = t.status === "published" || t.status === "publishing";
  const label = PLATFORM_LABEL[t.platform];

  async function act(action: string, body?: unknown) {
    setBusy(action);
    const res = await call<Target>(`/api/admin/marketing/social/targets/${t.id}/${action}`, { method: "POST", body: body ?? {} });
    setBusy(null);
    if (!res.ok) { notify(res.error ?? "Action failed.", "bad"); return false; }
    const s = res.data?.status;
    notify(`${label}: ${s ? STATUS_BADGE[s]?.[1] ?? s : "updated"}${s === "scheduled" ? ` for ${when(res.data?.scheduled_at)}` : ""}.`);
    onChanged();
    return true;
  }

  const canEnable = ["disabled", "skipped", "cancelled"].includes(t.status);
  const canDisable = !locked && t.status !== "disabled";
  const canNow = !locked && t.status !== "approved" && t.status !== "failed";
  const canCancel = ["pending", "approved", "scheduled"].includes(t.status);

  return (
    <div className={`min-w-0 rounded-lg bg-white ring-1 ${compact ? "p-3 ring-gray-200" : "p-4 shadow-sm ring-gray-900/5 sm:p-5"}`}>
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-sm font-semibold text-gray-900">{label}</span>
            <TargetBadge status={t.status} />
            {t.attempt_count > 0 && t.status !== "published" && <span className="text-xs text-gray-500">attempts: {t.attempt_count}</span>}
          </div>
          {!compact && (
            <p className="mt-1 text-sm font-semibold break-words text-gray-900">
              {t.title ?? `TenderHub notice #${t.source_id}`}{t.reference ? <span className="ml-2 font-mono text-xs font-normal text-gray-500">{t.reference}</span> : null}
            </p>
          )}
        </div>
        {!compact && t.tender_url && <a href={t.tender_url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-xs text-primary-600 hover:underline">Tender <ExternalLink className="size-3" /></a>}
      </div>

      <dl className="mt-2 grid grid-cols-2 gap-x-4 gap-y-1.5 text-xs sm:grid-cols-4">
        <Field label="Scheduled">{t.status === "approved" ? "Now" : when(t.next_attempt_at ?? t.scheduled_at)}</Field>
        <Field label="Published">{when(t.published_at)}</Field>
        <Field label="Post">{t.post_url ? <a href={t.post_url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 break-all text-primary-600 hover:underline">{t.external_post_id ?? "View"} <ExternalLink className="size-3 shrink-0" /></a>
          : t.external_post_id ?? (t.remote_publish_id ? `TikTok publish ${t.remote_publish_id}` : null)}</Field>
        {!compact && <Field label="Closing">{when(t.closing_at)}</Field>}
      </dl>
      {t.last_error && <p className={`mt-2 rounded-md px-3 py-2 text-xs break-words ${t.status === "failed" ? "bg-red-50 text-red-800" : "bg-yellow-50 text-yellow-800"}`}><strong>{t.status === "failed" ? "Error" : "Last error"}:</strong> {t.last_error}{t.last_error_at ? ` (${when(t.last_error_at)})` : ""}</p>}
      {!compact && t.caption && <p className="mt-2 line-clamp-2 text-xs text-gray-600">{t.caption}</p>}

      <div className="mt-3 flex flex-wrap gap-1.5">
        {t.post_id && t.status !== "publishing" && <Button size="sm" variant="secondary" onClick={() => setEdit(true)}>{t.status === "published" ? "View post" : "Edit post"}</Button>}
        {canEnable && <Button size="sm" variant="secondary" busy={busy === "enable"} onClick={() => act("enable")}>Enable automation</Button>}
        {canNow && <Button size="sm" busy={busy === "publish-now"} onClick={() => setConfirmNow(true)} disabled={accounts ? !accounts[t.platform]?.connected : false}>Publish now</Button>}
        {!locked && t.status !== "approved" && <Button size="sm" variant="secondary" onClick={() => setSchedule(true)}>{t.status === "scheduled" || t.status === "failed" ? "Reschedule" : "Schedule"}</Button>}
        {t.status === "failed" && <Button size="sm" busy={busy === "retry"} onClick={() => act("retry")}>Retry</Button>}
        {canCancel && <Button size="sm" variant="ghost" busy={busy === "cancel"} onClick={() => act("cancel")}>Cancel</Button>}
        {canDisable && <Button size="sm" variant="ghost" busy={busy === "disable"} onClick={() => act("disable")}>Disable automation</Button>}
      </div>

      {schedule && <ScheduleModal target={t} onClose={() => setSchedule(false)} onSave={async (iso) => { if (await act("schedule", { scheduled_at: iso })) setSchedule(false); }} busy={busy === "schedule"} />}
      {edit && t.post_id && <EditPostModal postId={t.post_id} readOnly={t.status === "published"} onClose={() => setEdit(false)} notify={notify} onSaved={() => { setEdit(false); onChanged(); }} />}
      <ConfirmDialog open={confirmNow} onClose={() => setConfirmNow(false)} onConfirm={() => { void act("publish-now"); setConfirmNow(false); }} title={`Publish to ${label} now?`} destructive={false}
        message={<p>The post for <strong>{t.title ?? `notice #${t.source_id}`}</strong> is sent to {label} immediately. A published post cannot be withdrawn from here.</p>} confirmLabel="Publish now" />
    </div>
  );
}

function ScheduleModal({ target, onClose, onSave, busy }: { target: Target; onClose: () => void; onSave: (iso: string) => void; busy: boolean }) {
  const initial = target.scheduled_at && target.status === "scheduled"
    ? new Date(parseUtc(target.scheduled_at)!.getTime() + 5.5 * 3600 * 1000).toISOString().slice(0, 16) : nextHourLocal();
  const [local, setLocal] = useState(initial);
  return (
    <Modal open onClose={onClose} title={`Schedule ${PLATFORM_LABEL[target.platform]} post`} size="sm"
      footer={<><Button variant="secondary" onClick={onClose}>Cancel</Button><Button busy={busy} onClick={() => onSave(toColomboIso(local))}>Save schedule</Button></>}>
      <FormField label="Date and time (Sri Lanka, UTC+05:30)" htmlFor="social-schedule-at">
        <input id="social-schedule-at" type="datetime-local" value={local} onChange={(e) => setLocal(e.target.value)} className={inputClass} />
      </FormField>
      <p className="mt-2 text-xs text-gray-500">Must be in the future and before the tender closes ({when(target.closing_at)}). The backend worker publishes it at this time.</p>
    </Modal>
  );
}

interface PostDetail { id: number; active_version_id: number; versions: Array<{ id: number; version_number: number; headline: string; caption: string; hashtags: string; poster_url: string | null }> }

function EditPostModal({ postId, readOnly, onClose, onSaved, notify }: { postId: number; readOnly: boolean; onClose: () => void; onSaved: () => void; notify: (m: string, t?: "ok" | "bad") => void }) {
  const [post, setPost] = useState<PostDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [form, setForm] = useState({ headline: "", caption: "", hashtags: "" });
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    (async () => {
      const res = await call<PostDetail>(`/api/admin/marketing/marketing-posts/${postId}`);
      if (!res.ok || !res.data) { setError(res.error ?? "Post could not be loaded."); return; }
      setPost(res.data);
      const v = res.data.versions.find((x) => x.id === res.data!.active_version_id) ?? res.data.versions[0];
      if (v) setForm({ headline: v.headline, caption: v.caption, hashtags: v.hashtags });
    })();
  }, [postId]);

  const active = post?.versions.find((x) => x.id === post.active_version_id);
  const poster = active?.poster_url?.startsWith("/storage/") ? `/api/admin/marketing-storage/${active.poster_url.replace("/storage/", "")}` : null;

  async function save() {
    setSaving(true);
    const res = await call(`/api/admin/marketing/marketing-posts/${postId}`, { method: "PUT", body: { ...form, change_summary: "Edited in Social Marketing" } });
    setSaving(false);
    if (res.ok) { notify("Post saved as a new version. Platforms not yet published will use it."); onSaved(); }
    else notify(res.error ?? "Could not save.", "bad");
  }

  return (
    <Modal open onClose={onClose} title={readOnly ? "Published post" : "Edit post"} size="lg"
      footer={readOnly ? <Button variant="secondary" onClick={onClose}>Close</Button> : <><Button variant="secondary" onClick={onClose}>Cancel</Button><Button busy={saving} disabled={!post} onClick={save}>Save new version</Button></>}>
      {error ? <ErrorBox message={error} /> : !post ? <p role="status" className="text-sm text-gray-500">Loading post…</p> : (
        <div className="grid gap-4 sm:grid-cols-[220px_1fr]">
          <div>{poster ? <Image src={poster} alt="Post artwork" width={640} height={360} className="h-auto w-full rounded-lg border border-gray-200" /> : <p className="text-xs text-gray-500">Artwork not available for this version.</p>}
            <p className="mt-1 text-xs text-gray-500">Version {active?.version_number}. One post is shared by both platforms; the artwork is regenerated from the Draft library.</p></div>
          <div className="space-y-3">
            <FormField label="Headline (TikTok title, max 90 characters used)" htmlFor="social-edit-headline">
              <input id="social-edit-headline" value={form.headline} readOnly={readOnly} onChange={(e) => setForm({ ...form, headline: e.target.value })} className={inputClass} /></FormField>
            <FormField label="Caption" htmlFor="social-edit-caption">
              <textarea id="social-edit-caption" value={form.caption} readOnly={readOnly} rows={8} onChange={(e) => setForm({ ...form, caption: e.target.value })} className={inputClass} /></FormField>
            <FormField label="Hashtags" htmlFor="social-edit-hashtags">
              <input id="social-edit-hashtags" value={form.hashtags} readOnly={readOnly} onChange={(e) => setForm({ ...form, hashtags: e.target.value })} className={inputClass} /></FormField>
            {!readOnly && <p className="text-xs text-gray-500">Upstream source and competitor names are replaced with TenderHub automatically before anything is posted.</p>}
          </div>
        </div>
      )}
    </Modal>
  );
}

// ------------------------------------------------------------- connections

function Connections({ accounts, error, notify, onChanged }: { accounts: Record<Platform, Account> | null; error: string | null; notify: (m: string, t?: "ok" | "bad") => void; onChanged: () => void }) {
  const [busy, setBusy] = useState<string | null>(null);
  const [disconnect, setDisconnect] = useState<Platform | null>(null);
  const [selectedFacebookPage, setSelectedFacebookPage] = useState("");
  const [selectedInstagramAccount, setSelectedInstagramAccount] = useState("");
  const [facebookDiagnostic, setFacebookDiagnostic] = useState<FacebookDiagnostic | null>(null);
  const [instagramDiagnostic, setInstagramDiagnostic] = useState<InstagramDiagnostic | null>(null);

  useEffect(() => {
    let active = true;
    call<FacebookDiagnostic | null>("/api/admin/marketing/social/accounts/facebook/diagnostic")
      .then((res) => { if (active && res.ok) setFacebookDiagnostic(res.data ?? null); });
    call<InstagramDiagnostic | null>("/api/admin/marketing/social/accounts/instagram/diagnostic")
      .then((res) => { if (active && res.ok) setInstagramDiagnostic(res.data ?? null); });
    return () => { active = false; };
  }, [accounts]);

  async function run(key: string, url: string, method: string, body?: unknown, ok?: string) {
    setBusy(key);
    const res = await call(url, { method, body });
    setBusy(null);
    if (res.ok) notify(ok ?? "Saved."); else notify(res.error ?? "Failed.", "bad");
    onChanged();
  }

  if (error) return <ErrorBox message={`Connections could not be loaded: ${error}`} onRetry={onChanged} />;
  if (!accounts) return <p role="status" className="py-6 text-sm text-gray-500">Loading connections…</p>;

  return (
    <section className="grid gap-4 lg:grid-cols-3">
      <div className="rounded-lg bg-primary-50 p-4 text-sm text-primary-900 ring-1 ring-primary-700/10 lg:col-span-3">
        <div className="mb-2 flex flex-wrap items-center justify-between gap-2"><strong className="font-semibold">Secure account sign-in</strong><Badge tone="primary">Password never stored here</Badge></div>
        Connect opens the platform&apos;s own login page. Sign in there with that account&apos;s username and password
        (and two-factor code), then approve TenderHub. TenderHub never sees or stores your password — it keeps only a revocable access token,
        encrypted. TenderHub&apos;s Facebook and Instagram production connections stay protected; use Reconnect to rotate authorization without removing either connection.
      </div>
      <div className="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-900/5 lg:col-span-3">
        <div className="flex flex-wrap items-end justify-between gap-3">
          <div>
            <h2 className="text-base font-semibold text-gray-900">Add a social account</h2>
            <p className="mt-1 text-xs text-gray-600">Choose a platform to connect its account for TenderHub publishing.</p>
          </div>
          <span className="text-xs font-medium text-gray-500">Official login only</span>
        </div>
        <div className="mt-4 grid gap-3 sm:grid-cols-3">
          {PLATFORMS.map((p) => {
            const a = accounts[p];
            return a.app_configured ? (
              <a key={p} href={`/api/admin/social-oauth/${p}/start`} className="flex items-center justify-between rounded-lg border border-gray-200 px-4 py-3 text-sm font-semibold text-gray-900 transition hover:border-primary-300 hover:bg-primary-50">
                <span>{a.connected ? `Reconnect ${a.label}` : `Add ${a.label}`}</span><span aria-hidden="true">→</span>
              </a>
            ) : (
              <div key={p} className="rounded-lg border border-dashed border-gray-200 px-4 py-3 text-sm font-semibold text-gray-400" title={`${a.label} app is not configured on the server`}>
                Add {a.label}<span className="mt-1 block text-xs font-normal">App setup required</span>
              </div>
            );
          })}
        </div>
      </div>
      {PLATFORMS.map((p) => {
        const a = accounts[p];
        return (
          <div key={p} className="min-w-0 rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-900/5">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <h2 className="text-base font-semibold text-gray-900">{a.label}</h2>
              <Badge tone={a.temporarily_paused ? "yellow" : a.connected ? "green" : a.status === "error" ? "red" : "yellow"} dot>{a.temporarily_paused ? "Connected · publishing paused" : (a.source === "environment" || a.fallback_active) ? "Connected (Environment Continuity Mode)" : a.connected ? "Connected (OAuth)" : a.status === "needs_page" ? "Choose a Page" : a.status === "needs_account" ? "Choose an account" : a.status === "error" ? "Reconnect required" : "Authorization required"}</Badge>
            </div>
            {!a.app_configured && <p className="mt-2 rounded-md bg-yellow-50 px-3 py-2 text-xs text-yellow-800">
              The {a.label} app is not configured on the server ({APP_ENV[p]}), so {a.label} login cannot start yet.
              {p === "facebook" && a.source === "environment" ? " Publishing currently uses the server's FACEBOOK_PAGE_ACCESS_TOKEN." : ""}
            </p>}
            {a.fallback_active && <p className="mt-2 rounded-md bg-green-50 px-3 py-2 text-xs text-green-800">
              Secure server continuity is active. Publishing can continue even though the previous interactive login needs renewal.
            </p>}
            <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-xs">
              <Field label={p === "facebook" ? "Page" : "Account"}>{a.account_name ?? (a.account_id ? `#${a.account_id}` : null)}{a.username ? ` (@${a.username})` : ""}</Field>
              <Field label="Connected via">{a.source === "oauth" ? `${a.label} Login (OAuth)` : a.source === "environment" ? "Environment continuity token (Server configuration)" : null}</Field>
              <Field label="Connected">{when(a.connected_at)}</Field>
              <Field label="Last verified">{when(a.last_verified_at)}</Field>
              <Field label="Last publish">{when(a.last_publish_at)}</Field>
              <Field label="Publishing">{a.temporarily_paused ? "Paused by Meta" : a.publishing_enabled === false ? "Disabled" : a.capabilities?.can_publish ? "Available" : "Unavailable"}</Field>
              {p === "tiktok" && <Field label="Access renews by">{when(a.refresh_expires_at)}</Field>}
              {p === "facebook" && a.refresh_expires_at && <Field label="Login valid until">{when(a.refresh_expires_at)}</Field>}
            </dl>
            {a.last_error && <p className={`mt-2 rounded-lg px-3 py-2 text-xs ${a.fallback_active || a.temporarily_paused ? "bg-yellow-50 text-yellow-800" : "bg-red-50 text-red-800"}`}>
              {a.fallback_active ? "Previous login: " : ""}{a.last_error}
            </p>}
            {p === "instagram" && a.temporarily_paused && <p className="mt-2 rounded-md bg-yellow-50 px-3 py-2 text-xs text-yellow-900">Meta limited publishing. Your Instagram connection and queued posts are preserved; automatic retries are stopped. Check Instagram Account Status and request a review if offered. Resume only after Meta lifts the restriction. Reconnecting does not remove this publishing pause.</p>}
            {p === "facebook" && facebookDiagnostic && (
              <div className="mt-3 rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs text-gray-700">
                <p className="font-semibold text-gray-900">Latest Facebook OAuth diagnostic</p>
                <dl className="mt-2 grid grid-cols-2 gap-x-3 gap-y-1">
                  <Field label="Authenticated profile">{facebookDiagnostic.authenticated_user_name ?? "Unavailable"}</Field>
                  <Field label="Facebook user ID">{facebookDiagnostic.authenticated_user_id ?? "Unavailable"}</Field>
                  <Field label="Token">{facebookDiagnostic.token_type ? `${facebookDiagnostic.token_type} · ${facebookDiagnostic.token_valid ? "valid" : "invalid"}` : "Unavailable"}</Field>
                  <Field label="Pages from Meta">{facebookDiagnostic.pages_returned == null ? "Unavailable" : String(facebookDiagnostic.pages_returned)}</Field>
                  <Field label="Discovery method">{facebookDiagnostic.discovery_method === "business_portfolio" ? "Business Portfolio fallback" : facebookDiagnostic.discovery_method === "user_accounts" ? "/me/accounts" : "Unavailable"}</Field>
                  <Field label="Business matched">{facebookDiagnostic.business_matched == null ? "Not checked" : facebookDiagnostic.business_matched ? "Yes" : "No"}</Field>
                  <Field label="Connectable Pages">{facebookDiagnostic.connectable_pages == null ? "Unavailable" : String(facebookDiagnostic.connectable_pages)}</Field>
                  <Field label={`${facebookDiagnostic.expected_page_name} found`}>{facebookDiagnostic.expected_page_found == null ? "Unavailable" : facebookDiagnostic.expected_page_found ? "Yes" : "No"}</Field>
                </dl>
                <p className="mt-2"><span className="font-semibold">Granted permissions:</span> {(facebookDiagnostic.granted_permissions ?? []).join(", ") || "None reported"}</p>
                {facebookDiagnostic.meta_error && <p className="mt-2 rounded-md bg-red-50 p-2 text-red-800">Meta API error{facebookDiagnostic.meta_error.code ? ` ${facebookDiagnostic.meta_error.code}` : ""}: {facebookDiagnostic.meta_error.message}</p>}
                {facebookDiagnostic.error && <p className="mt-2 text-red-700">{facebookDiagnostic.error}</p>}
                <p className="mt-2 text-gray-500">Checked {when(facebookDiagnostic.updated_at)}</p>
              </div>
            )}
            {p === "instagram" && instagramDiagnostic && (
              <div className="mt-3 rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs text-gray-700">
                <p className="font-semibold text-gray-900">Latest Instagram authorization diagnostic</p>
                <dl className="mt-2 grid grid-cols-2 gap-x-3 gap-y-1">
                  <Field label="Facebook profile">{instagramDiagnostic.authenticated_user_name ?? "Unavailable"}</Field>
                  <Field label="Facebook user ID">{instagramDiagnostic.authenticated_user_id ?? "Unavailable"}</Field>
                  <Field label="Token">{instagramDiagnostic.token_valid == null ? "Unavailable" : instagramDiagnostic.token_valid ? "Valid user token" : "Invalid token"}</Field>
                  <Field label="Linked IG accounts">{instagramDiagnostic.instagram_accounts_returned == null ? "Unavailable" : String(instagramDiagnostic.instagram_accounts_returned)}</Field>
                  {instagramDiagnostic.publishing_eligible != null && <Field label="Publishing eligible">{instagramDiagnostic.publishing_eligible ? "Eligible (Professional)" : "Ineligible"}</Field>}
                  {instagramDiagnostic.verification_status && <Field label="Verification status">{instagramDiagnostic.verification_status}</Field>}
                  {instagramDiagnostic.graph_endpoint && <Field label="Endpoint">{instagramDiagnostic.graph_endpoint}</Field>}
                  {instagramDiagnostic.requested_fields && <Field label="Requested fields">{instagramDiagnostic.requested_fields}</Field>}
                </dl>
                <p className="mt-2"><span className="font-semibold">Granted permissions:</span> {(instagramDiagnostic.granted_permissions ?? []).join(", ") || "None reported"}</p>
                {instagramDiagnostic.meta_error && <p className="mt-2 rounded-md bg-red-50 p-2 text-red-800">Meta API error{instagramDiagnostic.meta_error.code ? ` ${instagramDiagnostic.meta_error.code}` : ""}: {instagramDiagnostic.meta_error.message}</p>}
                {instagramDiagnostic.error && <p className="mt-2 text-red-700">{instagramDiagnostic.error}</p>}
                <p className="mt-2 text-gray-500">Checked {when(instagramDiagnostic.updated_at)}</p>
              </div>
            )}
            {p === "instagram" && <p className="mt-2 text-xs text-gray-500">Needs an Instagram professional account (Business or Creator).</p>}
            {a.connected && a.capabilities && !a.capabilities.can_publish && <p className="mt-2 rounded-md bg-yellow-50 px-3 py-2 text-xs text-yellow-800">Publishing unavailable for this account or permission set.</p>}

            {p === "facebook" && a.pages.length > 0 && (
              <div className="mt-3"><label className="block text-sm/6 font-medium text-gray-900" htmlFor="facebook-page">Page that receives posts</label>
                {/* Native select kept: individual Pages without publishing access must stay disabled options. */}
                <select id="facebook-page" value={selectedFacebookPage || a.account_id || ""} disabled={busy === "page"} onChange={(e) => setSelectedFacebookPage(e.target.value)}
                  className={`${inputClass} mt-2`}>
                  <option value="">Choose a Page…</option>
                  {a.pages.map((pg) => <option key={pg.id} value={pg.id} disabled={!pg.can_publish}>{pg.name} ({pg.id}){pg.can_publish ? "" : " — no publishing access"}</option>)}
                </select>
                {!a.connected && <p className="mt-1 text-xs text-yellow-700">Choose Tenderhub.lk, then connect it to enable publishing.</p>}
                <Button variant="secondary" className="mt-2" busy={busy === "page"} disabled={!selectedFacebookPage || !a.pages.find((pg) => pg.id === selectedFacebookPage)?.can_publish}
                  onClick={() => run("page", "/api/admin/marketing/social/accounts/facebook/page", "POST", { page_id: selectedFacebookPage }, "Facebook Page connected and publishing access verified.")}>Connect selected Page</Button>
              </div>
            )}
            {p === "instagram" && a.status === "needs_account" && (a.instagram_accounts?.length ?? 0) > 0 && (
              <div className="mt-3">
                <Select label="Instagram account linked to Tenderhub.lk" value={selectedInstagramAccount || a.account_id || ""} disabled={busy === "instagram-account"} onChange={setSelectedInstagramAccount}
                  placeholder="Choose an Instagram account…"
                  options={(a.instagram_accounts ?? []).map((ig) => ({ value: ig.id, label: `${ig.username ? `@${ig.username}` : ig.name || `Instagram #${ig.id}`} · ${ig.account_type || "Professional"} · linked to ${ig.page_name}` }))} />
                <Button variant="secondary" className="mt-2" busy={busy === "instagram-account"} disabled={!selectedInstagramAccount}
                  onClick={() => run("instagram-account", "/api/admin/marketing/social/accounts/instagram/account", "POST", { account_id: selectedInstagramAccount }, "Instagram account connected and publishing access verified.")}>Connect selected account</Button>
              </div>
            )}
            {p === "tiktok" && a.connected && (
              <div className="mt-3">
                <Select label="Post privacy (offered by TikTok for this account)" value={a.privacy_level ?? ""} disabled={busy === "privacy"} placeholder="Choose…"
                  onChange={(v) => run("privacy", "/api/admin/marketing/social/accounts/tiktok/settings", "PUT", { privacy_level: v }, "TikTok privacy saved.")}
                  options={(a.privacy_level_options ?? []).map((o) => ({ value: o, label: o }))} />
                {!(a.privacy_level_options ?? []).includes("PUBLIC_TO_EVERYONE") && <p className="mt-2 text-xs text-yellow-700">TikTok does not offer public posting for this app/account yet (unaudited apps can only post privately).</p>}
              </div>
            )}

            <div className="mt-4 flex flex-wrap gap-2">
              {a.app_configured && <a href={`/api/admin/social-oauth/${p}/start`} className={buttonClass("primary")}>{a.source === "oauth" ? `Reconnect ${a.label}` : `Log in with ${a.label}`}</a>}
              {p === "instagram" && a.direct_login_available && <Link href="/api/admin/social-oauth/instagram/start?mode=direct" className={buttonClass("secondary")}>Use direct Instagram Login</Link>}
              {p === "facebook" && a.app_configured && <Link href="/api/admin/social-oauth/facebook/start?account=another" className={buttonClass("secondary")}>Reconnect with another Facebook account</Link>}
              {(a.connected || a.status === "error") && <Button variant="secondary" busy={busy === "verify"} onClick={() => run("verify", `/api/admin/marketing/social/accounts/${p}/verify`, "POST", {}, `${a.label} connection verified.`)}>Test connection</Button>}
              {p === "instagram" && a.connected && a.temporarily_paused && <Button variant="secondary" busy={busy === "resume-instagram"} onClick={() => run("resume-instagram", "/api/admin/marketing/social/accounts/instagram/resume-publishing", "POST", {}, "Instagram publishing resumed. If Meta still restricts posting, it will pause again without disconnecting.")}>Resume Instagram publishing</Button>}
              {p === "tiktok" && a.source === "oauth" && <Button variant="ghost" onClick={() => setDisconnect(p)}>Disconnect</Button>}
            </div>
            {p === "facebook" && <p className="mt-2 text-xs text-gray-500">On Meta&apos;s screen, use <strong>Not this account? Log in to another account</strong>. If Meta only shows Continue, log out of facebook.com in this browser or use a private window, then start this action again.</p>}
            {p === "instagram" && <p className="mt-2 text-xs text-gray-500">Instagram connection uses Facebook Login to find the professional account linked to Tenderhub.lk. On Meta&apos;s screen, choose the Facebook profile with Page access; use a private window or log out of Facebook to switch profiles.</p>}
            {a.connected && a.capabilities?.can_publish && (p === "facebook" || p === "instagram") && <p className={`mt-3 rounded-md px-3 py-2 text-xs font-medium ${a.temporarily_paused ? "bg-yellow-50 text-yellow-900" : "bg-green-50 text-green-800"}`}>{a.temporarily_paused ? "OAuth connection protected · publishing paused until staff resume" : "Publishing always on · protected production connection"}</p>}
            {p === "tiktok" && a.connected && a.source === "oauth" && a.capabilities?.can_publish && <label className="mt-3 flex items-center gap-2 text-sm font-medium text-gray-900"><input type="checkbox" className="size-4 rounded-sm border-gray-300 text-primary-600 focus:ring-primary-600" checked={a.publishing_enabled !== false} disabled={busy === "publishing"} onChange={(e) => run("publishing", `/api/admin/marketing/social/accounts/${p}/settings`, "PUT", { publishing_enabled: e.target.checked }, e.target.checked ? `${a.label} publishing enabled.` : `${a.label} publishing disabled.`)} /> Enable {a.label} publishing</label>}
          </div>
        );
      })}
      <div className="min-w-0 rounded-lg bg-white p-5 text-sm break-words text-gray-600 shadow-sm ring-1 ring-gray-900/5 lg:col-span-3">
        <p className="font-semibold text-gray-900">Platform app settings</p>
        <ul className="mt-1 list-disc space-y-0.5 pl-5">
          <li>Facebook app Settings → Basic → App Domains: <code>tenderhub.lk</code> (domain only, without https:// or a path).</li>
          <li>Facebook Login redirect URI: <code>https://tenderhub.lk/api/admin/social-oauth/facebook/callback</code> (permissions: pages_show_list, pages_read_engagement, pages_manage_posts, business_management).</li>
          <li>Instagram through Facebook Login redirect URI: <code>https://tenderhub.lk/api/admin/social-oauth/instagram/callback</code> (permissions: pages_show_list, pages_read_engagement, instagram_basic, instagram_content_publish, business_management). Direct Instagram Login remains a fallback when Facebook Login is unavailable.</li>
          <li>TikTok redirect URI: <code>https://tenderhub.lk/api/admin/social-oauth/tiktok/callback</code>; verified URL prefix for photo posts: <code>https://tenderhub.lk/social-media/</code> (scopes: user.info.basic, video.publish).</li>
        </ul>
      </div>
      <ConfirmDialog open={!!disconnect} onClose={() => setDisconnect(null)} confirmLabel="Disconnect"
        onConfirm={() => { if (disconnect) void run("disconnect", `/api/admin/marketing/social/accounts/${disconnect}`, "DELETE", undefined, `${PLATFORM_LABEL[disconnect]} disconnected.`); setDisconnect(null); }}
        title={`Disconnect ${disconnect ? PLATFORM_LABEL[disconnect] : ""}?`}
        message={<p>{disconnect === "facebook" ? "Pending and scheduled Facebook posts will be cancelled. Published posts remain on the Page." : `Scheduled ${disconnect ? PLATFORM_LABEL[disconnect] : ""} posts stay scheduled but will fail at their time until an account is connected again.`}</p>} />
    </section>
  );
}
