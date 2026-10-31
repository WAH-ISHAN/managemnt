"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { KeyRound, Settings } from "lucide-react";
import {
  Alert, Badge, Card, CardBody, CardFooter, CardHeader, EmptyState, Stat, StatGrid,
  Table, TBody, TD, TEmpty, TH, THead, TR,
} from "@/components/console/ui";
import { Button, Field, Modal, Select, Toggle, inputClass } from "@/components/console/interactive";
import { dateTime } from "@/lib/format";
import { IngestionTelemetry, type IngestionTelemetryData } from "@/components/portal/ingestion-telemetry";

export type IngestionSource = {
  id: number;
  name: string;
  slug: string;
  source_key?: string;
  mode: string;
  baseline: number;
  this_week: number;
  verified_pct: number | null;
  status: "healthy" | "paused" | "failing" | "stale" | "idle" | string;
  active: boolean;
  paused: boolean;
  has_credentials?: boolean;
  credential_email?: string | null;
  credential_url?: string | null;
  adapter?: string | null;
  can_run?: boolean;
  last_fetch_at: string | null;
  last_success_at: string | null;
  last_error?: string | null;
};

/** The badge reflects the source's recorded collection outcome, never an assumption. */
function collectionBadge(s: IngestionSource, scheduleLabel: string) {
  if (!(s.active && !s.paused)) return <Badge tone="gray">Paused / Inactive</Badge>;
  switch (s.status) {
    case "failing": return <Badge tone="red" dot>Last collection failed</Badge>;
    case "stale": return <Badge tone="yellow" dot>No successful collection in 48h</Badge>;
    case "idle": return <Badge tone="gray" dot>Not collected yet</Badge>;
    default: return <Badge tone="green" dot>Healthy ({scheduleLabel})</Badge>;
  }
}

type AutomationSchedule = {
  mode: "hourly" | "every_4_hours" | "daily";
  hour: number | null;
  timezone: string;
  label: string;
  updated_at?: string | null;
};

export type LiveRun = {
  request: { id: string; queued_at: string | null } | null;
  status: {
    request_id: string | null;
    state: "waiting" | "running" | "completed" | "completed_with_errors" | "failed" | "rejected" | string | null;
    detail: string | null;
    started_at: string | null;
    finished_at: string | null;
    exit_code: number | null;
    summary: string[];
    updated_at: string | null;
  } | null;
};

const LIVE_STATE: Record<string, { tone: "primary" | "yellow" | "green" | "red" | "gray"; label: string }> = {
  queued: { tone: "primary", label: "Queued · waiting for host" },
  waiting: { tone: "yellow", label: "Waiting for the scheduled run to finish" },
  running: { tone: "primary", label: "Running now" },
  completed: { tone: "green", label: "Completed" },
  completed_with_errors: { tone: "yellow", label: "Completed with source errors" },
  failed: { tone: "red", label: "Failed" },
  rejected: { tone: "red", label: "Rejected by the host" },
};

/** The state the host recorded; a request it has not picked up yet is "queued". */
function liveState(run: LiveRun | null): string | null {
  if (run?.request) return run.status?.state === "waiting" && run.status.request_id === run.request.id ? "waiting" : "queued";
  return run?.status?.state ?? null;
}

type PendingNotice ={ id: number; title: string; reference: string; source?: string | null; missing?: string[]; closing_at?: string | null };


export function IngestionClient({
  initialSources,
  pendingNotices,
  initialSchedule,
  initialLiveRun,
  initialTelemetry,
}: {
  initialSources: IngestionSource[];
  pendingNotices: PendingNotice[];
  initialSchedule?: AutomationSchedule | null;
  initialLiveRun?: LiveRun | null;
  initialTelemetry?: IngestionTelemetryData | null;
}) {
  const router = useRouter();
  const [liveRun, setLiveRun] = useState<LiveRun | null>(initialLiveRun ?? null);
  const [startingLive, setStartingLive] = useState(false);
  const currentLiveState = liveState(liveRun);
  const liveActive = currentLiveState === "queued" || currentLiveState === "waiting" || currentLiveState === "running";

  // Poll the host-recorded outcome while a Live run is pending, then refresh
  // the source table so it shows what that run actually collected.
  useEffect(() => {
    if (!liveActive) return;
    const timer = setInterval(async () => {
      try {
        const res = await fetch("/api/admin/ingest/live-run", { cache: "no-store" });
        if (!res.ok) return;
        const next = (await res.json()).data as LiveRun;
        setLiveRun(next);
        const state = liveState(next);
        if (state !== "queued" && state !== "waiting" && state !== "running") router.refresh();
      } catch { /* the next tick retries */ }
    }, 5000);
    return () => clearInterval(timer);
  }, [liveActive, router]);

  const handleLiveRun = async () => {
    setStartingLive(true);
    try {
      const res = await fetch("/api/admin/ingest/live-run", { method: "POST", headers: { "Content-Type": "application/json" } });
      const data = await res.json().catch(() => ({}));
      if (res.ok) {
        setLiveRun(data.data as LiveRun);
        setToast({ m: "Run requested now. The host watcher starts it immediately when the pipeline is free.", t: "ok" });
      } else {
        setToast({ m: data.detail || "Could not start the Live automation run.", t: "bad" });
      }
    } catch {
      setToast({ m: "Network error while starting the Live automation run.", t: "bad" });
    } finally {
      setStartingLive(false);
    }
  };
  const [sources, setSources] = useState<IngestionSource[]>(initialSources);
  const [togglingId, setTogglingId] = useState<number | null>(null);
  const [toast, setToast] = useState<{ m: string; t: "ok" | "bad" } | null>(null);
  const [creatingWebsiteSource, setCreatingWebsiteSource] = useState(false);
  const [websiteName, setWebsiteName] = useState("");
  const [websiteUrl, setWebsiteUrl] = useState("");
  // When the schedule API fails, say so; never present a default as the saved cadence.
  const [scheduleLoaded, setScheduleLoaded] = useState(Boolean(initialSchedule));
  const [schedule, setSchedule] = useState<AutomationSchedule>(
    initialSchedule ?? {
      mode: "every_4_hours",
      hour: null,
      timezone: "Asia/Colombo",
      label: "Unavailable",
      updated_at: null,
    }
  );
  const [scheduleMode, setScheduleMode] = useState<AutomationSchedule["mode"]>(schedule.mode);
  const [scheduleHour, setScheduleHour] = useState<number>(schedule.hour ?? 5);
  const [savingSchedule, setSavingSchedule] = useState(false);

  // Credential Modal State
  const [activeModalSource, setActiveModalSource] = useState<IngestionSource | null>(null);
  const [formEmail, setFormEmail] = useState("");
  const [formPassword, setFormPassword] = useState("");
  const [formUrl, setFormUrl] = useState("");
  const [savingCreds, setSavingCreds] = useState(false);

  // Search filter
  const [search, setSearch] = useState("");

  const activeCount = sources.filter((s) => s.active && !s.paused).length;
  const disabledCount = sources.length - activeCount;
  const failingCount = sources.filter((s) => s.active && !s.paused && s.status === "failing").length;
  const scheduleCaption = !scheduleLoaded
    ? "Schedule could not be loaded"
    : schedule.mode === "daily"
      ? `${schedule.label} SLST`
      : `${schedule.label} in ${schedule.timezone}`;

  const handleSaveSchedule = async () => {
    setSavingSchedule(true);
    try {
      const res = await fetch("/api/admin/ingest/schedule", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ mode: scheduleMode, hour: scheduleHour }),
      });
      const data = await res.json().catch(() => ({}));

      if (res.ok) {
        const next = data.data as AutomationSchedule;
        setSchedule(next);
        setScheduleLoaded(true);
        setScheduleMode(next.mode);
        setScheduleHour(next.hour ?? scheduleHour);
        setToast({ m: `Automation schedule saved: ${next.label}.`, t: "ok" });
        router.refresh();
      } else {
        setToast({ m: data.detail || "Could not save automation schedule.", t: "bad" });
      }
    } catch {
      setToast({ m: "Network error while saving automation schedule.", t: "bad" });
    } finally {
      setSavingSchedule(false);
    }
  };


  const handleCreateWebsiteSource = async () => {
    setCreatingWebsiteSource(true);
    try {
      const res = await fetch("/api/admin/ingest/website-sources", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          name: websiteName.trim(),
          url: websiteUrl.trim(),
        }),
      });
      const data = await res.json().catch(() => ({}));

      if (res.ok) {
        const created = data.data as IngestionSource;
        setSources((prev) => [...prev, created]);
        setWebsiteName("");
        setWebsiteUrl("");
        setToast({ m: `Website crawler source added: ${created.name}.`, t: "ok" });
        router.refresh();
      } else {
        setToast({ m: data.detail || "Could not add website source.", t: "bad" });
      }
    } catch {
      setToast({ m: "Network error while adding website source.", t: "bad" });
    } finally {
      setCreatingWebsiteSource(false);
    }
  };

  const openCredsModal = (s: IngestionSource) => {
    setActiveModalSource(s);
    setFormEmail(s.credential_email || "");
    setFormPassword("");
    setFormUrl(s.credential_url || "");
  };

  const closeCredsModal = () => {
    setActiveModalSource(null);
    setFormEmail("");
    setFormPassword("");
    setFormUrl("");
  };

  // Toggle Source Active/Disabled
  const handleToggle = async (s: IngestionSource) => {
    setTogglingId(s.id);
    const newActiveState = !(s.active && !s.paused);
    try {
      const res = await fetch(`/api/admin/ingest/sources/${s.id}/toggle`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ active: newActiveState }),
      });
      const data = await res.json().catch(() => ({}));

      if (res.ok) {
        setSources((prev) =>
          prev.map((item) =>
            item.id === s.id
              ? {
                  ...item,
                  active: newActiveState,
                  paused: !newActiveState,
                  status: data.data?.status ?? (newActiveState ? "idle" : "paused"),
                }
              : item
          )
        );
        setToast({
          m: `${s.name} is now ${newActiveState ? "ENABLED (Active)" : "DISABLED (Paused)"}.`,
          t: "ok",
        });
        router.refresh();
      } else {
        setToast({ m: data.detail || "Failed to update source state.", t: "bad" });
      }
    } catch {
      setToast({ m: "Network error while updating source status.", t: "bad" });
    } finally {
      setTogglingId(null);
    }
  };

  // Save Credentials
  const handleSaveCredentials = async () => {
    if (!activeModalSource) return;
    setSavingCreds(true);
    try {
      const res = await fetch(`/api/admin/ingest/sources/${activeModalSource.id}/credentials`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          email: formEmail.trim(),
          password: formPassword.trim(),
          login_url: formUrl.trim(),
        }),
      });
      const data = await res.json().catch(() => ({}));

      if (res.ok) {
        setSources((prev) =>
          prev.map((item) =>
            item.id === activeModalSource.id
              ? {
                  ...item,
                  has_credentials: true,
                  credential_email: formEmail.trim() || item.credential_email,
                  credential_url: formUrl.trim() || item.credential_url,
                }
              : item
          )
        );
        setToast({ m: `Credentials saved for ${activeModalSource.name}.`, t: "ok" });
        closeCredsModal();

      } else {
        setToast({ m: data.detail || "Failed to save portal credentials.", t: "bad" });
      }
    } catch {
      setToast({ m: "Network error while saving credentials.", t: "bad" });
    } finally {
      setSavingCreds(false);
    }
  };

  const [publishingNoticeId, setPublishingNoticeId] = useState<number | null>(null);

  const handlePublishNotice = async (id: number) => {
    setPublishingNoticeId(id);
    try {
      const res = await fetch(`/api/admin/notices/${id}/publish`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
      });
      const data = await res.json().catch(() => ({}));
      if (res.ok) {
        setToast({ m: `Notice #${id} published to live catalogue!`, t: "ok" });
        router.refresh();
      } else {
        setToast({ m: data.detail || "Could not publish notice. Ensure closing date is present.", t: "bad" });
      }
    } catch {
      setToast({ m: "Network error publishing notice.", t: "bad" });
    } finally {
      setPublishingNoticeId(null);
    }
  };

  const filteredSources = sources.filter((s) =>
    s.name.toLowerCase().includes(search.toLowerCase()) ||
    s.slug.toLowerCase().includes(search.toLowerCase())
  );

  return (
    <div className="space-y-6">
      {toast && (
        <Alert
          tone={toast.t === "ok" ? "success" : "error"}
          title={toast.m}
          actions={<Button variant="ghost" size="xs" onClick={() => setToast(null)}>Dismiss</Button>}
        />
      )}

      {/* Telemetry KPI Overview */}
      <StatGrid cols={4}>
        <Stat
          label="Active Ingestion Sources"
          value={`${activeCount} enabled`}
          tone={activeCount === 0 || failingCount > 0 ? "bad" : "good"}
          detail={
            <span className="inline-flex items-center gap-1.5">
              <span className={`size-2 rounded-full ${failingCount > 0 ? "bg-red-500" : activeCount > 0 ? "animate-pulse bg-green-500" : "bg-gray-400"}`} />
              {failingCount > 0 ? `${failingCount} failing · ${scheduleCaption}` : scheduleCaption}
            </span>
          }
        />
        <Stat
          label="Disabled / Dormant"
          value={disabledCount}
          detail={`of ${sources.length} total portals`}
        />
        <Stat
          label="Primary National Source"
          value={<span className="block truncate text-sm font-bold">Official Gazette / National Press</span>}
          detail={`Verified feed · ${schedule.label}`}
        />
        <Stat
          label="Scheduled Execution"
          value={schedule.label}
          tone={scheduleLoaded ? undefined : "bad"}
          detail={scheduleLoaded ? `${schedule.timezone} (SLST)` : "The saved schedule could not be read"}
        />
      </StatGrid>

      {!scheduleLoaded && (
        <Alert tone="error" title="The automation schedule could not be loaded. The VPS runner keeps its saved cadence; save a schedule below to set it explicitly." />
      )}

      <Card>
        <CardHeader
          title="Automation Schedule"
          description="Set when the VPS automation runner imports official tender data."
        />
        <CardBody>
          <div className="grid items-start gap-3 lg:grid-cols-[1.2fr_1fr_auto]">
            <Select<AutomationSchedule["mode"]>
              value={scheduleMode}
              onChange={setScheduleMode}
              options={[
                { value: "hourly", label: "Every hour" },
                { value: "every_4_hours", label: "Every 4 hours" },
                { value: "daily", label: "Daily at selected hour" },
              ]}
            />
            <Select<string>
              value={String(scheduleHour)}
              onChange={(v) => setScheduleHour(Number(v))}
              disabled={scheduleMode !== "daily"}
              options={Array.from({ length: 24 }, (_, hour) => ({
                value: String(hour),
                label: `${String(hour).padStart(2, "0")}:00 SLST`,
              }))}
            />
            <Button busy={savingSchedule} onClick={handleSaveSchedule}>
              {savingSchedule ? "Saving..." : "Save Schedule"}
            </Button>
          </div>
          <p className="mt-2 text-xs text-gray-500">
            Current schedule: <strong className="font-semibold text-gray-700">{scheduleCaption}</strong>
            {schedule.updated_at ? ` · updated ${dateTime(schedule.updated_at)}` : ""}
          </p>
        </CardBody>
      </Card>

      <Card>
        <CardHeader
          title="Run collection now"
          description="Start a small collection batch now, then sync notices, match alerts and prepare marketing drafts. A scheduled run in progress finishes first."
          actions={
            <Button busy={startingLive} disabled={liveActive} onClick={handleLiveRun}>
              {liveActive ? "Run in progress…" : "Run now"}
            </Button>
          }
        />
        <CardBody>
          {currentLiveState ? (
            <div className="space-y-2">
              <div className="flex flex-wrap items-center gap-2">
                <Badge tone={LIVE_STATE[currentLiveState]?.tone ?? "gray"} dot>
                  {LIVE_STATE[currentLiveState]?.label ?? currentLiveState}
                </Badge>
                {liveRun?.request?.queued_at && <span className="text-xs text-gray-500">queued {dateTime(liveRun.request.queued_at)}</span>}
                {liveRun?.status?.started_at && !liveRun.request && <span className="text-xs text-gray-500">started {dateTime(liveRun.status.started_at)}</span>}
                {liveRun?.status?.finished_at && !liveRun.request && <span className="text-xs text-gray-500">finished {dateTime(liveRun.status.finished_at)}</span>}
              </div>
              {!liveRun?.request && liveRun?.status?.detail && <p className="text-sm text-gray-700">{liveRun.status.detail}</p>}
              {!liveRun?.request && (liveRun?.status?.summary?.length ?? 0) > 0 && (
                <ul className="rounded-md bg-gray-50 px-3 py-2 font-mono text-xs text-gray-700">
                  {liveRun!.status!.summary.map((line, i) => <li key={i}>{line}</li>)}
                </ul>
              )}
            </div>
          ) : (
            <p className="text-sm text-gray-500">No Live run has been requested yet.</p>
          )}
        </CardBody>
      </Card>

      <IngestionTelemetry initial={initialTelemetry ?? null} />
      <Card>
        <CardHeader
          title="Procurement Sources & Crawler Governance"
          description="Configure enabled portals, manage authentication credentials, and run scheduled automated crawler collection."
          actions={
            <input
              type="text"
              placeholder="Search portals…"
              aria-label="Search portals"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className={`${inputClass} sm:w-56`}
            />
          }
        />

        <CardBody className="border-b border-gray-200">
          <div className="grid gap-3 lg:grid-cols-[1fr_1.8fr_auto]">
            <input
              type="text"
              aria-label="Official website source name"
              placeholder="Official website source name"
              value={websiteName}
              onChange={(e) => setWebsiteName(e.target.value)}
              className={inputClass}
            />
            <input
              type="url"
              aria-label="Official website URL"
              placeholder="https://example.gov.lk/procurement or official notice URL"
              value={websiteUrl}
              onChange={(e) => setWebsiteUrl(e.target.value)}
              className={`${inputClass} font-mono`}
            />
            <Button
              busy={creatingWebsiteSource}
              disabled={!websiteName.trim() || !websiteUrl.trim()}
              onClick={handleCreateWebsiteSource}
            >
              {creatingWebsiteSource ? "Adding..." : "Add Website"}
            </Button>
          </div>
          <p className="mt-2 text-xs text-gray-500">
            Website crawler sources are added to the governance table below. Only official domains are accepted.
          </p>
        </CardBody>

        <Table>
          <THead>
            <TH>Source Portal</TH>
            <TH>Mode</TH>
            <TH align="right">Notices (7 days)</TH>
            <TH align="right">Last Ingested</TH>
            <TH align="center">Collection Status</TH>
            <TH align="center">Enable / Disable</TH>
            <TH align="right">Actions</TH>
          </THead>
          <TBody>
            {filteredSources.length === 0 ? (
              <TEmpty colSpan={7} title="No ingestion sources found" help={search ? "No portals match your search." : undefined} />
            ) : (
              filteredSources.map((s) => {
                const isActive = s.active && !s.paused;
                const isToggling = togglingId === s.id;

                return (
                  <TR key={s.id} className={isActive ? "" : "bg-gray-50"}>
                    <TD>
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="font-semibold text-gray-900">{s.name}</span>
                        {isActive && <Badge tone="green">Active Feed</Badge>}
                        {s.has_credentials && (
                          <span title={s.credential_email ? `Auth: ${s.credential_email}` : "Credentials configured"}>
                            <Badge tone="gray">
                              <KeyRound aria-hidden="true" className="size-3" />
                              {s.credential_email ? s.credential_email.split("@")[0] : "Saved"}
                            </Badge>
                          </span>
                        )}
                      </div>
                      <div className="mt-0.5 font-mono text-xs text-gray-500">
                        {s.slug} {s.credential_url ? `· ${s.credential_url}` : ""}
                      </div>
                    </TD>
                    <TD>
                      <Badge tone={isActive ? "primary" : "gray"}>{s.mode}</Badge>
                    </TD>
                    <TD align="right" className="font-mono font-bold tabular-nums text-green-700">
                      {s.this_week}
                    </TD>
                    <TD align="right" className="font-mono text-xs">
                      <div title="Last collection attempt">{s.last_fetch_at ? dateTime(s.last_fetch_at) : "—"}</div>
                      <div className="text-gray-500" title="Last successful collection">
                        {s.last_success_at ? `ok ${dateTime(s.last_success_at)}` : "no success recorded"}
                      </div>
                    </TD>
                    <TD align="center">
                      {collectionBadge(s, schedule.label)}
                      {isActive && s.last_error && (
                        <p className="mx-auto mt-1 max-w-56 text-left text-xs text-red-700 line-clamp-3" title={s.last_error}>
                          {s.last_error}
                        </p>
                      )}
                    </TD>
                    <TD align="center">
                      <div className="inline-flex">
                        <Toggle
                          checked={isActive}
                          onChange={() => handleToggle(s)}
                          disabled={isToggling}
                          label={isActive ? "Enabled" : "Disabled"}
                        />
                      </div>
                    </TD>
                    <TD align="right">
                      <Button size="sm" variant="secondary" onClick={() => openCredsModal(s)}>
                        <Settings aria-hidden="true" className="size-4" />
                        Credentials
                      </Button>
                    </TD>
                  </TR>
                );
              })
            )}
          </TBody>
        </Table>

        <CardFooter className="text-xs text-gray-500">
          <div className="flex flex-col gap-1">
            <span className="font-semibold text-gray-700">Governance & Multi-Source Automation Rules:</span>
            <span>
              • <strong>Active Sources:</strong> Automated ingestion executes on the configured Sri Lanka Time schedule with cross-site deduplication, database synchronization, and notification alert dispatches.
            </span>
            <span>
              • <strong>Disabled Sources:</strong> Kept in dormant state. The pipeline will never scrape or contact disabled portals.
            </span>
            <span>
              • <strong>Scheduled and Live Runs:</strong> Data collection runs on the configured schedule. The Live button runs the same pipeline immediately and never overlaps a scheduled run.
            </span>
          </div>
        </CardFooter>
      </Card>

      {/* Moderation Queue Section */}
      <Card>
        <CardHeader
          title="Moderation Queue"
          description="Items requiring field verification before indexing into the public directory."
        />
        {pendingNotices.length ? (
          <ul role="list" className="divide-y divide-gray-100">
            {pendingNotices.map((n) => (
              <li key={n.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6">
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-medium text-gray-900">{n.title}</p>
                  <p className="mt-0.5 font-mono text-xs text-gray-500">
                    {n.reference} · {n.source ?? "—"}
                  </p>
                  <div className="mt-1.5 flex flex-wrap gap-1.5">
                    {(n.missing ?? []).map((m: string) => (
                      <Badge key={m} tone="yellow">
                        missing {m}
                      </Badge>
                    ))}
                  </div>
                </div>
                <Button
                  size="sm"
                  busy={publishingNoticeId === n.id}
                  disabled={!n.closing_at}
                  onClick={() => handlePublishNotice(n.id)}
                >
                  {publishingNoticeId === n.id ? "Publishing…" : "Publish"}
                </Button>
              </li>
            ))}
          </ul>
        ) : (
          <EmptyState title="Nothing awaiting review" />
        )}
        <CardFooter className="text-xs text-gray-500">
          Publication is refused outright without a closing date. Real newspapers are preserved with high-resolution clipping attachments and verified timelines.
        </CardFooter>
      </Card>

      {/* Credentials Configuration Modal */}
      <Modal
        open={activeModalSource !== null}
        onClose={closeCredsModal}
        title="Portal Credentials & Automation"
        description={
          activeModalSource ? (
            <>Target: <span className="font-semibold text-gray-700">{activeModalSource.name}</span></>
          ) : undefined
        }
        footer={
          <>
            <Button variant="secondary" onClick={closeCredsModal} disabled={savingCreds}>
              Cancel
            </Button>
            <Button variant="primary" busy={savingCreds} onClick={() => handleSaveCredentials()}>
              {savingCreds ? "Saving…" : "Save Credentials"}
            </Button>
          </>
        }
      >
        {activeModalSource && (
          <div className="space-y-4">
            <Field label="Portal Login Email / Username" htmlFor="cred-email">
              <input
                id="cred-email"
                type="text"
                placeholder="e.g. redacted@example.invalid"
                value={formEmail}
                onChange={(e) => setFormEmail(e.target.value)}
                className={inputClass}
              />
            </Field>
            <Field
              label="Portal Login Password"
              htmlFor="cred-password"
              hint="Credentials are encrypted and used securely by the scraper engine."
            >
              <input
                id="cred-password"
                type="password"
                autoComplete="new-password"
                placeholder={activeModalSource.has_credentials ? "•••••••• (Leave blank to keep unchanged)" : "Enter password"}
                value={formPassword}
                onChange={(e) => setFormPassword(e.target.value)}
                className={inputClass}
              />
            </Field>
            <Field label="Portal Login / Target URL (Optional)" htmlFor="cred-url">
              <input
                id="cred-url"
                type="url"
                placeholder="https://example.com/login"
                value={formUrl}
                onChange={(e) => setFormUrl(e.target.value)}
                className={`${inputClass} font-mono`}
              />
            </Field>
            <Alert tone="warning" title="Automation Note">
              Saving credentials allows the automation engine to authenticate and scrape deep notices, buyer details, and full document clippings.
            </Alert>
          </div>
        )}
      </Modal>
    </div>
  );
}
