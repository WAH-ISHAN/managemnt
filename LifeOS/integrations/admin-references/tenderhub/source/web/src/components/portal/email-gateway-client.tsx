"use client";

import { useState } from "react";
import { RotateCw, Send, Search } from "lucide-react";
import { Alert, Badge, Card, CardBody, CardHeader, DescriptionList, Stat, StatGrid, StatusBadge, Table, TBody, TD, TEmpty, TH, THead, TR } from "@/components/console/ui";
import { Button, Field, inputClass } from "@/components/console/interactive";
import { emailStatusLabel } from "@/lib/email-status";

/**
 * Email transport, lifecycle and alert controls.
 *
 * Every number is read from the API (the email outbox, provider events, job
 * tables and automation incidents). "Accepted by mail server" is what SMTP can
 * prove; "Delivered", "Bounced" and "Complaint" only appear when a signed
 * provider event (Amazon SES via SNS) reported them.
 */

interface SmtpProfile {
  configured: boolean;
  host: string;
  port: number;
  crypto: string;
  auth_method?: string;
  user_mask: string;
  from_email: string;
}

type Lifecycle = Record<"queued" | "processing" | "provider_accepted" | "delivered" | "bounced" | "complained" | "failed" | "cancelled", number>;
type Incident = { id: number; job_key: string; status: string; first_failed_at: string; last_failed_at: string; failure_count: number; last_stage: string | null; last_error: string | null; resolved_at: string | null; alerts_sent: number };
type Setting = { value: string | null; updated_by: number | null; updated_at: string | null };
type AuthEmailJob = { id: number; kind: string; status: string; attempts: number; requested_at: string; next_attempt_at: string | null; completed_at: string | null; last_error: string | null; recipient_email: string | null };
type FinOpsStatus = {
  state: "healthy" | "warning" | "paused";
  limits: { automation_hourly: number; automation_daily: number; recipient_daily: number; total_daily: number; duplicate_window_minutes: number };
  usage: { automation_last_hour: number; automation_today: number; total_today: number; total_this_month: number; deferred: number; unique_recipients_today: number };
  quality_30d: { accepted: number; bounced: number; complained: number; bounce_rate: number; complaint_rate: number };
  cost: { currency: "USD"; rate_per_1000: number; estimated_this_month: number };
  daily: Array<{ day: string; accepted: number; estimated_cost: number }>;
};

interface EmailStatusData {
  configured?: boolean;
  active_profile: { host: string; port: number; crypto: string; from_email: string; from_name: string };
  profiles: { smtp: SmtpProfile };
  lifecycle: Lifecycle;
  last_24h: Record<"created" | "provider_accepted" | "delivered" | "bounced" | "complained" | "failed" | "opened" | "clicked", number>;
  queue: { oldest_due_at: string | null; last_worker_run: { at: string; attempted: number; accepted: number; failed: number } | null };
  provider_events: { configured: boolean; configuration_set: string | null; last_event_at: string | null; total: number };
  suppressions: { hard_bounce: number; complaint: number };
  finops?: FinOpsStatus;
  settings: { admin_new_tender_alerts: Setting };
  incidents: Incident[];
  recent_auth_email_jobs: AuthEmailJob[];
  digest: {
    pending: number;
    registration_pending: number;
    registration_failed: number;
    registration_processing: number;
    active_profiles: number;
    alert_recipients_entitled: number;
    notification_pending: number;
    tender_alert_items_24h: number;
  };
}

type TraceStep = { check: string; ok: boolean; detail: string };
type TraceEmail = { id: number; status: string; subject: string; attempted_at: string; accepted_at: string | null; delivered_at: string | null; bounced_at: string | null; last_error: string | null; events: string[] };
type Trace = { verdict: string; steps: TraceStep[]; emails: TraceEmail[] };

const LIFECYCLE_LABELS: Array<[keyof Lifecycle, string]> = [
  ["queued", "Queued"],
  ["processing", "Sending now"],
  ["provider_accepted", "Accepted by mail server"],
  ["delivered", "Delivered (provider confirmed)"],
  ["bounced", "Bounced"],
  ["complained", "Spam complaint"],
  ["failed", "Failed (dead letter)"],
  ["cancelled", "Not sent (suppressed)"],
];

export function EmailGatewayClient({ initialStatus }: { initialStatus: EmailStatusData }) {
  const [status, setStatus] = useState<EmailStatusData>(initialStatus);
  const [testRecipient, setTestRecipient] = useState("");
  const [busy, setBusy] = useState<string | null>(null);
  const [message, setMessage] = useState<{ text: string; type: "ok" | "err" } | null>(null);
  const [traceNotice, setTraceNotice] = useState("");
  const [traceUser, setTraceUser] = useState("");
  const [trace, setTrace] = useState<Trace | null>(null);

  const smtp = status.profiles?.smtp;
  const configured = Boolean(status.configured ?? smtp?.configured);
  const adminAlertsOn = status.settings?.admin_new_tender_alerts?.value === "1";

  async function refreshStatus() {
    const refreshed = await fetch("/api/admin/email/status");
    if (refreshed.ok) setStatus((await refreshed.json()).data);
  }

  async function run(label: string, action: () => Promise<string>) {
    setBusy(label);
    setMessage(null);
    try {
      setMessage({ text: await action(), type: "ok" });
      await refreshStatus();
    } catch (error) {
      setMessage({ text: error instanceof Error ? error.message : "The request failed.", type: "err" });
    } finally {
      setBusy(null);
    }
  }

  const retryFailed = () => run("retry", async () => {
    const response = await fetch("/api/admin/email/retry-failed", { method: "POST" });
    const body = await response.json();
    if (!response.ok) throw new Error(body.detail || "Failed to retry onboarding emails.");
    return `${body.data.requeued} onboarding emails queued for retry.`;
  });

  const toggleAdminAlerts = () => run("toggle", async () => {
    const response = await fetch("/api/admin/email/settings", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ admin_new_tender_alerts: !adminAlertsOn }),
    });
    const body = await response.json();
    if (!response.ok) throw new Error(body.detail || "Could not change the setting.");
    return adminAlertsOn
      ? "Admin new-tender emails are off. Users still receive their own tender alerts; failure and recovery alerts still reach staff."
      : "Admin new-tender emails are on.";
  });

  const sendTest = () => run("test", async () => {
    const res = await fetch("/api/admin/email/test", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ to: testRecipient }),
    });
    const json = await res.json();
    if (!res.ok) throw new Error(json.detail || json.data?.detail || "The mail server did not accept the diagnostic email.");
    return `Email #${json.data.dispatch_id} was accepted by the mail server. Check the inbox to confirm it arrived.`;
  });

  async function runTrace() {
    setBusy("trace");
    setTrace(null);
    setMessage(null);
    const key = /^\d+$/.test(traceUser.trim()) ? "user_id" : "email";
    const params = new URLSearchParams({ notice_id: traceNotice.trim(), [key]: traceUser.trim() });
    try {
      const res = await fetch(`/api/admin/email/trace?${params}`);
      const json = await res.json();
      if (!res.ok) throw new Error(json.detail || "Trace failed.");
      setTrace(json.data);
    } catch (error) {
      setMessage({ text: error instanceof Error ? error.message : "Trace failed.", type: "err" });
    } finally {
      setBusy(null);
    }
  }

  const lastRun = status.queue?.last_worker_run;
  const events = status.provider_events;
  const finops = status.finops;

  return (
    <div className="space-y-8">
      {message && (
        <Alert
          tone={message.type === "ok" ? "success" : "error"}
          title={message.text}
          actions={<Button variant="ghost" size="sm" onClick={() => setMessage(null)}>Dismiss</Button>}
        />
      )}

      <section aria-labelledby="lifecycle-heading" className="space-y-4">
        <h2 id="lifecycle-heading" className="text-base font-semibold text-gray-900">Email lifecycle (all recorded emails)</h2>
        <StatGrid>
          {LIFECYCLE_LABELS.map(([key, label]) => <Stat key={key} label={label} value={status.lifecycle?.[key] ?? 0} />)}
        </StatGrid>
        <h3 className="pt-2 text-sm font-semibold text-gray-900">Last 24 hours</h3>
        <StatGrid>
          <Stat label="Created" value={status.last_24h?.created ?? 0} />
          <Stat label="Accepted by mail server" value={status.last_24h?.provider_accepted ?? 0} />
          <Stat label="Delivered (confirmed)" value={status.last_24h?.delivered ?? 0} tone="good" />
          <Stat label="Bounced" value={status.last_24h?.bounced ?? 0} tone={(status.last_24h?.bounced ?? 0) > 0 ? "warn" : "default"} />
          <Stat label="Complaints" value={status.last_24h?.complained ?? 0} tone={(status.last_24h?.complained ?? 0) > 0 ? "warn" : "default"} />
          <Stat label="Failed" value={status.last_24h?.failed ?? 0} tone={(status.last_24h?.failed ?? 0) > 0 ? "bad" : "default"} />
          <Stat label="Opened (pixel)" value={status.last_24h?.opened ?? 0} />
          <Stat label="Clicked" value={status.last_24h?.clicked ?? 0} />
        </StatGrid>
        <p className="text-sm text-gray-500">
          {events?.configured
            ? `Delivery, bounce and complaint events arrive from Amazon SES (${events.total} recorded, last ${events.last_event_at ?? "none yet"}).`
            : "SES delivery events are not configured (SES_SNS_TOPIC_ARN is unset): emails can only reach “Accepted by mail server”, never “Delivered”."}{" "}
          Opens come from a tracking image; mail-client image proxies inflate them and image blocking hides them.
        </p>
      </section>

      {finops && (
        <Card>
          <CardHeader
            title="Amazon SES FinOps guardrails"
            description="Live outbox usage. Automated email is deferred before a limit is exceeded; authentication and other transactional messages keep the remaining daily headroom."
            actions={<Badge tone={finops.state === "healthy" ? "green" : finops.state === "warning" ? "yellow" : "red"} dot>{finops.state}</Badge>}
          />
          <CardBody className="space-y-5">
            <StatGrid>
              <Stat label="Automated this hour" value={`${finops.usage.automation_last_hour} / ${finops.limits.automation_hourly}`} tone={finops.usage.automation_last_hour >= finops.limits.automation_hourly * 0.8 ? "warn" : "default"} />
              <Stat label="Automated today" value={`${finops.usage.automation_today} / ${finops.limits.automation_daily}`} tone={finops.usage.automation_today >= finops.limits.automation_daily * 0.8 ? "warn" : "default"} />
              <Stat label="All email today" value={`${finops.usage.total_today} / ${finops.limits.total_daily}`} tone={finops.usage.total_today >= finops.limits.total_daily * 0.8 ? "warn" : "default"} />
              <Stat label="Safety deferred" value={finops.usage.deferred} tone={finops.usage.deferred > 0 ? "warn" : "good"} />
              <Stat label="Recipients today" value={finops.usage.unique_recipients_today} />
              <Stat label="Estimated SES cost this month" value={`$${finops.cost.estimated_this_month.toFixed(4)}`} />
              <Stat label="30-day bounce rate" value={`${finops.quality_30d.bounce_rate}%`} tone={finops.quality_30d.bounced > 0 ? "warn" : "good"} />
              <Stat label="30-day complaint rate" value={`${finops.quality_30d.complaint_rate}%`} tone={finops.quality_30d.complained > 0 ? "warn" : "good"} />
            </StatGrid>
            <Alert
              tone={finops.state === "healthy" ? "success" : finops.state === "warning" ? "warning" : "error"}
              title={finops.state === "healthy" ? "Send rate is inside the safety budget" : finops.state === "warning" ? "Send rate is nearing a safety limit" : "A safety limit is active; automated email is being deferred"}
            >
              Each recipient receives at most {finops.limits.recipient_daily} automated emails per day. Identical automated messages within {finops.limits.duplicate_window_minutes} minutes are deduplicated. Cost uses the configured estimate of ${finops.cost.rate_per_1000.toFixed(2)} per 1,000 emails.
            </Alert>
            <Table>
              <THead><TH>Accepted day</TH><TH align="right">Emails</TH><TH align="right">Estimated cost</TH></THead>
              <TBody>
                {finops.daily.map((day) => <TR key={day.day}><TD>{day.day}</TD><TD align="right">{day.accepted}</TD><TD align="right">${day.estimated_cost.toFixed(4)}</TD></TR>)}
                {finops.daily.length === 0 && <TEmpty colSpan={3} title="No email was accepted in the last 14 days." />}
              </TBody>
            </Table>
          </CardBody>
        </Card>
      )}

      <section aria-label="Queue and recipients">
        <StatGrid>
          <Stat label="Oldest email waiting to send" value={<span className="text-lg break-words">{status.queue?.oldest_due_at ?? "None"}</span>} />
          <Stat label="Email worker last ran" value={<span className="text-lg break-words">{lastRun?.at ? new Date(lastRun.at).toLocaleString() : "Never recorded"}</span>} />
          <Stat label="Suppressed: hard bounce" value={status.suppressions?.hard_bounce ?? 0} />
          <Stat label="Suppressed: complaint" value={status.suppressions?.complaint ?? 0} />
          <Stat label="Users eligible for alert email" value={status.digest.alert_recipients_entitled} />
          <Stat label="Tender alerts queued in 24h" value={status.digest.tender_alert_items_24h} />
          <Stat label="Notification emails waiting" value={status.digest.notification_pending} />
          <Stat label="Registration emails failed" value={status.digest.registration_failed} tone={status.digest.registration_failed > 0 ? "bad" : "default"} />
          <Stat label="Registration emails waiting" value={status.digest.registration_pending} />
          <Stat label="Registration emails sending" value={status.digest.registration_processing ?? 0} />
        </StatGrid>
      </section>

      <Card>
        <CardHeader title="Recent registration and recovery emails" description="Job state is recorded separately from SMTP acceptance and provider delivery in Email Analytics." />
        <Table>
          <THead><TH>Recipient</TH><TH>Type</TH><TH>Job state</TH><TH align="right">Attempts</TH><TH>Requested</TH><TH>Next attempt / completed</TH><TH>Error</TH></THead>
          <TBody>
            {status.recent_auth_email_jobs?.map((job) => (
              <TR key={job.id}>
                <TD className="break-all">{job.recipient_email ?? "Deleted user"}</TD>
                <TD>{job.kind}</TD>
                <TD><StatusBadge status={job.status} /></TD>
                <TD align="right">{job.attempts}</TD>
                <TD>{job.requested_at}</TD>
                <TD>{job.next_attempt_at ?? job.completed_at ?? "—"}</TD>
                <TD className="max-w-xs break-words">{job.last_error ?? "—"}</TD>
              </TR>
            ))}
            {(!status.recent_auth_email_jobs || status.recent_auth_email_jobs.length === 0) && <TEmpty colSpan={7} title="No authentication email jobs recorded." />}
          </TBody>
        </Table>
      </Card>

      <Card>
        <CardHeader
          title="Admin new-tender emails"
          description={<>A summary of newly collected tenders, emailed to staff after each automation run that found new tenders. Switching this off does
              not affect users&apos; own tender alerts, or automation failure and recovery alerts.</>}
          actions={
            <Button variant={adminAlertsOn ? "secondary" : "primary"} onClick={toggleAdminAlerts} disabled={busy !== null} aria-pressed={adminAlertsOn}>
              {adminAlertsOn ? "On — switch off" : "Off — switch on"}
            </Button>
          }
        />
        <CardBody className="flex flex-wrap items-center gap-3 text-sm text-gray-500">
          <Badge tone={adminAlertsOn ? "green" : "gray"} dot>{adminAlertsOn ? "On" : "Off"}</Badge>
          {status.settings?.admin_new_tender_alerts?.updated_at && (
            <span>Last changed {status.settings.admin_new_tender_alerts.updated_at} UTC by staff #{status.settings.admin_new_tender_alerts.updated_by}.</span>
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader title="Automation incidents" description="Recorded even when no alert email could be sent. Only the primary owner is alerted on the first failure, at most hourly while it continues, and once on recovery." />
        <Table>
          <THead><TH>Job</TH><TH>State</TH><TH>Failing since</TH><TH align="right">Failures</TH><TH>Last error</TH><TH>Resolved</TH></THead>
          <TBody>
            {status.incidents?.map((i) => (
              <TR key={i.id}>
                <TD strong>{i.job_key}</TD>
                <TD><StatusBadge status={i.status} /></TD>
                <TD className="whitespace-nowrap">{i.first_failed_at}</TD>
                <TD align="right" className="tabular-nums">{i.failure_count}</TD>
                <TD className="max-w-md break-words">{i.last_error ?? "—"}</TD>
                <TD className="whitespace-nowrap">{i.resolved_at ?? "—"}</TD>
              </TR>
            ))}
            {(!status.incidents || status.incidents.length === 0) && <TEmpty colSpan={6} title="No automation incidents recorded." />}
          </TBody>
        </Table>
      </Card>

      <Card>
        <CardHeader title="Why did (or didn't) a user get a tender alert?" />
        <CardBody>
          <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
            <div className="sm:w-40">
              <Field label="Tender ID" htmlFor="trace-notice">
                <input id="trace-notice" inputMode="numeric" value={traceNotice} onChange={(e) => setTraceNotice(e.target.value)} placeholder="Tender ID" className={inputClass} />
              </Field>
            </div>
            <div className="min-w-0 flex-1">
              <Field label="User ID or email" htmlFor="trace-user">
                <input id="trace-user" value={traceUser} onChange={(e) => setTraceUser(e.target.value)} placeholder="User ID or email" className={inputClass} />
              </Field>
            </div>
            <Button onClick={runTrace} busy={busy === "trace"} disabled={busy !== null || !traceNotice || !traceUser}>
              <Search size={16} aria-hidden="true" />
              Explain
            </Button>
          </div>
          {trace && (
            <div className="mt-5 space-y-3 text-sm">
              <p className="flex flex-wrap items-center gap-2 font-medium text-gray-900">
                Verdict:
                <Badge tone={trace.verdict === "emailed" ? "green" : trace.verdict === "eligible" ? "primary" : "red"}>
                  {trace.verdict === "emailed" ? "Alert email recorded" : trace.verdict === "eligible" ? "Eligible — will be queued on the next alert run" : "Not eligible"}
                </Badge>
              </p>
              <ul className="space-y-1 text-gray-700">
                {trace.steps.map((s, i) => (
                  <li key={i} className="flex gap-2"><span aria-hidden="true" className={s.ok ? "text-green-600" : "text-red-600"}>{s.ok ? "✓" : "✗"}</span><span className="min-w-0 break-words"><strong className="text-gray-900">{s.check}:</strong> {s.detail}</span></li>
                ))}
              </ul>
              {trace.emails.map((e) => (
                <p key={e.id} className="rounded-md bg-gray-50 p-3 break-words text-gray-700">
                  Email <a className="font-medium text-primary-600 hover:text-primary-500" href={`/console/email-analytics/${e.id}`}>#{e.id}</a> — {emailStatusLabel(e.status)}
                  {e.accepted_at ? `, accepted ${e.accepted_at}` : ""}{e.delivered_at ? `, delivered ${e.delivered_at}` : ""}{e.bounced_at ? `, bounced ${e.bounced_at}` : ""}{e.last_error ? ` — ${e.last_error}` : ""}
                </p>
              ))}
            </div>
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader
          title="SMTP transport"
          description="Configured on the server through SMTP_* environment variables. Changing it requires a deployment update."
          actions={<Badge tone={configured ? "green" : "red"} dot>{configured ? "Configured" : "Not configured — emails will fail"}</Badge>}
        />
        <CardBody>
          <DescriptionList
            columns={2}
            items={([
              ["Host", smtp?.host || "—"],
              ["Port / encryption", smtp ? `${smtp.port} (${(smtp.crypto || "none").toUpperCase()})` : "—"],
              ["Authentication", smtp?.auth_method?.toUpperCase() || "—"],
              ["Username", smtp?.user_mask || "—"],
              ["From address", status.active_profile?.from_email || smtp?.from_email || "—"],
              ["SES configuration set", events?.configuration_set || "Not set"],
            ] as [string, string][]).map(([label, value]) => [label, <span key={label} className="font-mono">{value}</span>])}
          />
        </CardBody>
      </Card>

      <Card>
        <CardHeader title="Send a diagnostic email" description="To your own address or a configured staff alert address only; at most 5 per hour. It is recorded in Email Analytics like any other email." />
        <CardBody>
          <div className="flex flex-col gap-3 sm:flex-row">
            <label className="sr-only" htmlFor="email-test-recipient">Recipient</label>
            <input id="email-test-recipient" type="email" value={testRecipient} onChange={(e) => setTestRecipient(e.target.value)} placeholder="redacted@example.invalid" className={`${inputClass} sm:flex-1`} />
            <Button onClick={sendTest} disabled={busy !== null || !configured || !testRecipient.includes("@")}>
              <Send size={16} aria-hidden="true" />
              {busy === "test" ? "Sending..." : "Send test email"}
            </Button>
          </div>
        </CardBody>
      </Card>

      <Button variant="secondary" onClick={retryFailed} disabled={busy !== null || status.digest.registration_failed === 0}>
        <RotateCw size={16} aria-hidden="true" />
        {busy === "retry" ? "Queuing retries..." : "Retry failed onboarding emails"}
      </Button>
    </div>
  );
}
