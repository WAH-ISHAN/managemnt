import { PanelError } from "@/components/portal/panel-error";
import { authed } from "@/lib/api";
import {
  Badge, ButtonLink, Card, CardBody, CardHeader, PageHeading, ProgressBar, Stat, StatGrid,
  Table, TBody, TD, TEmpty, TH, THead, TR,
} from "@/components/console/ui";
import { dateTime } from "@/lib/format";
import { Activity, AlertTriangle, ArrowUpRight, Clock, Database, FileText, ShieldCheck, Users, Wallet } from "lucide-react";

export const metadata = { title: "System Health — TenderHub Console" };

type DistrictCoverage = { district?: string | null; n?: number | string | null };
type SecurityEvent = { id?: number | string; kind?: string | null; ip?: string | null; detail?: string | null; severity?: string | null; created_at?: string | null };
type HealthReport = {
  catalogue?: Record<string, number | string | null>;
  accounts?: Record<string, number | string | null>;
  engagement?: Record<string, number | string | null>;
};

export default async function Console() {
  const [health, coverage, secEvents] = await Promise.all([
    authed<HealthReport>("/api/v1/admin/reports/health"),
    authed<DistrictCoverage[]>("/api/v1/admin/reports/coverage"),
    authed<SecurityEvent[]>("/api/v1/admin/security/events"),
  ]);
  const failed = [health, coverage, secEvents].find((result) => !result.ok);
  if (failed) return <PanelError status={failed.status} />;

  const d = health.body?.data ?? {};
  const cat = d.catalogue ?? {};
  const acc = d.accounts ?? {};
  const eng = d.engagement ?? {};
  const cov = coverage.body?.data ?? [];
  const events = secEvents.body?.data ?? [];

  const max = Math.max(1, ...cov.map((c: DistrictCoverage) => Number(c.n)));
  const totalCoverage = cov.reduce((accCount: number, cur: DistrictCoverage) => accCount + Number(cur.n || 0), 0);

  const awaitingPayment = Number(acc.awaiting_payment ?? 0);
  const fmt = (v: unknown) => Number(v ?? 0).toLocaleString();

  return (
    <div className="space-y-8 pb-12">
      <PageHeading
        title="System health"
        description="Database indicators for catalogue freshness, accounts, payments and geographic coverage."
        meta={
          <span className="inline-flex items-center gap-x-2">
            <span className="relative flex size-2"><span className="absolute inline-flex size-full animate-ping rounded-full bg-green-400 opacity-75" /><span className="relative inline-flex size-2 rounded-full bg-green-500" /></span>
            Live from production database
          </span>
        }
        actions={<ButtonLink href="/console/automation" variant="secondary">Automation analysis</ButtonLink>}
      />

      <StatGrid>
        <Stat icon={FileText} label="Live notices" value={fmt(cat.live)} detail="Active tenders in the national catalogue" href="/console/notices" />
        <Stat icon={ShieldCheck} label="Human verified" value={`${cat.verified_pct ?? 0}%`} detail={`${fmt(cat.awaiting_review)} in moderation queue`} />
        <Stat icon={Users} label="Registered bidders" value={fmt(acc.bidders)} detail={`${fmt(acc.publishers)} buyers / organisations`} href="/console/users" />
        <Stat icon={Wallet} label="Paying subscribers" value={fmt(acc.paying_bidders)} detail={`${acc.conversion_pct ?? 0}% conversion`} href="/console/payments" />
      </StatGrid>

      <StatGrid>
        <Stat icon={Clock} label="Added today" value={fmt(cat.added_today)} />
        <Stat icon={Database} label="Last ingestion" value={typeof cat.minutes_since_fetch === "number" ? `${cat.minutes_since_fetch}m ago` : "—"} href="/console/ingestion" />
        <Stat icon={Activity} label="Tenders in pipelines" value={fmt(eng.tenders_in_pipelines)} />
        <Stat icon={AlertTriangle} label="Pending settlement" value={fmt(awaitingPayment)} tone={awaitingPayment > 0 ? "warn" : "default"} href="/console/payments" />
      </StatGrid>

      <Card>
        <CardHeader
          title="Recent security activity"
          description="Events captured from authentication, notice moderation and security controls."
          actions={<ButtonLink href="/console/security" variant="ghost" size="sm">View all<ArrowUpRight aria-hidden="true" className="size-4" /></ButtonLink>}
        />
        <Table>
          <THead>
            <TH>Event</TH>
            <TH>Detail</TH>
            <TH>Severity</TH>
            <TH align="right">Time</TH>
          </THead>
          <TBody>
            {events.length > 0 ? events.slice(0, 8).map((evt: SecurityEvent, i: number) => {
              const isBad = evt.severity === "critical" || evt.severity === "warning";
              return (
                <TR key={evt.id ?? i}>
                  <TD strong className="whitespace-nowrap">
                    <div className="capitalize">{evt.kind?.replace(/_/g, " ") ?? "Event"}</div>
                    <div className="font-mono text-xs font-normal text-gray-500">{evt.ip || "IP not recorded"}</div>
                  </TD>
                  <TD className="max-w-md truncate">{evt.detail || "No detail recorded"}</TD>
                  <TD>
                    <Badge tone={evt.severity === "critical" ? "red" : isBad ? "yellow" : "green"} dot>{evt.severity || "info"}</Badge>
                  </TD>
                  <TD align="right" className="whitespace-nowrap tabular-nums">{dateTime(evt.created_at)}</TD>
                </TR>
              );
            }) : (
              <TEmpty colSpan={4} title="No security events recorded" help="Authentication and moderation events will appear here as they occur." />
            )}
          </TBody>
        </Table>
      </Card>

      <Card>
        <CardHeader
          title="Geographic coverage"
          description={`Procurement distribution across Sri Lanka's districts — ${cov.length} reporting, ${totalCoverage.toLocaleString()} mapped tenders.`}
        />
        <CardBody>
          {cov.length === 0 ? (
            <p className="text-sm text-gray-500">No district coverage has been recorded yet.</p>
          ) : (
            <ul role="list" className="grid grid-cols-1 gap-x-8 gap-y-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
              {cov.slice(0, 16).map((c: DistrictCoverage) => {
                const count = Number(c.n);
                return (
                  <li key={c.district ?? "unknown"}>
                    <div className="mb-1.5 flex items-center justify-between gap-2 text-sm">
                      <span className="truncate font-medium text-gray-900">{c.district ?? "Unassigned"}</span>
                      <span className="tabular-nums text-gray-500">{count.toLocaleString()}</span>
                    </div>
                    <ProgressBar value={count} max={max} label={`${c.district ?? "Unassigned"} share`} />
                  </li>
                );
              })}
            </ul>
          )}
        </CardBody>
      </Card>
    </div>
  );
}
