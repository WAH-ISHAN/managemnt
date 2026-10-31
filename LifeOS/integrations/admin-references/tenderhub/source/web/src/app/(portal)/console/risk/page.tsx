import Link from "next/link";
import { PanelError } from "@/components/portal/panel-error";
import { authed } from "@/lib/api";
import { Badge, Card, EmptyState, PageHeading, Stat, StatGrid, type Tone } from "@/components/console/ui";
import { dateTime } from "@/lib/format";

export const metadata = { title: "Risk signals" };
const TONE: Record<string, Tone> = { low: "gray", medium: "yellow", high: "red" };
type RiskSignal = { severity: string; signal: string; reason: string; ref?: string | null; source?: string; notice_slug?: string | null; closing_at?: string | null };
type Check = { signal: string; examined: number; found: number; scope: string };

export default async function RiskPage() {
  const res = await authed("/api/v1/admin/risk-signals");
  if (!res.ok) return <PanelError status={res.status} />;
  const rows = (res.body?.data ?? []) as RiskSignal[];
  const checks = (res.body?.meta?.checks ?? []) as Check[];
  return (
    <>
      <PageHeading title="Risk signals" description="Patterns worth a human's attention, computed live from TenderHub records — these are review signals, not determinations of wrongdoing." />
      {checks.length > 0 && (
        <section aria-label="Checks run" className="mb-6">
          <StatGrid>
            {checks.map((c) => (
              <Stat key={c.signal} label={c.signal.replace(/_/g, " ")} value={c.found} detail={`found in ${c.examined.toLocaleString()} examined · ${c.scope}`} />
            ))}
          </StatGrid>
        </section>
      )}
      <Card>
        {rows.length ? (
          <ol role="list" className="divide-y divide-gray-100">
            {rows.map((s, i) => (
              <li key={i} className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 px-4 py-4 sm:px-6">
                <span className="min-w-0">
                  <span className="flex flex-wrap items-center gap-2">
                    <Badge tone={TONE[s.severity] ?? "gray"}>{s.severity}</Badge>
                    <span className="text-sm font-medium text-gray-900">{s.signal.replace(/_/g, " ")}</span>
                    {s.source && <span className="text-xs text-gray-400">{s.source}</span>}
                  </span>
                  <p className="mt-1 text-sm text-gray-500">{s.reason}</p>
                  {s.closing_at && <p className="mt-0.5 text-xs text-gray-400">Closes {dateTime(s.closing_at)}</p>}
                </span>
                <span className="shrink-0 text-right font-mono text-xs text-gray-400">
                  {s.ref}
                  {s.notice_slug && <><br /><Link href={`/tenders/${s.notice_slug}`} className="font-sans font-semibold text-primary-700 hover:underline">Open tender</Link></>}
                </span>
              </li>
            ))}
          </ol>
        ) : <EmptyState title="No signals" description="Every check above ran on live data and nothing currently meets a review threshold." />}
      </Card>
    </>
  );
}
