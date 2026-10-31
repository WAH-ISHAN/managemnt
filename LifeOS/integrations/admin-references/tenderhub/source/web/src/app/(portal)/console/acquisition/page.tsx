import { PanelError } from "@/components/portal/panel-error";
import { authed } from "@/lib/api";
import { Card, CardBody, CardHeader, PageHeading } from "@/components/console/ui";

export const metadata = { title: "Acquisition Analytics | TenderHub Admin" };

type Row = { key: string; label: string; registrations: number };
type Report = { period_days: number; since: string; total_registrations: number; sources: Row[]; campaigns: Row[]; landing_pages: Row[]; recent: Array<Record<string, string | number | null>> };

function Breakdown({ title, rows }: { title: string; rows: Row[] }) {
  return <Card><CardHeader title={title} description="Registrations recorded from real first-touch attribution data." /><CardBody>
    {rows.length ? <div className="space-y-3">{rows.map((row) => <div key={`${title}-${row.key}`} className="flex items-center justify-between gap-4 border-b border-gray-100 pb-2 text-sm last:border-0"><span className="break-all text-gray-700">{row.label}</span><span className="font-semibold text-gray-950">{row.registrations}</span></div>)}</div> : <p className="text-sm text-gray-500">No registrations were recorded in this period.</p>}
  </CardBody></Card>;
}

export default async function AcquisitionPage() {
  const res = await authed<Report>("/api/v1/admin/acquisition?days=90");
  if (!res.ok) return <PanelError status={res.status} />;
  const report = res.body?.data as Report | undefined;
  if (!report) return <PanelError status={503} />;
  return <>
    <PageHeading title="Acquisition analytics" description="See how new users discovered TenderHub using first-touch source, campaign, referrer and landing-page data captured at registration." meta={<span>Last {report.period_days} days · {report.total_registrations} recorded registrations</span>} />
    <div className="grid gap-5 lg:grid-cols-3"><Breakdown title="Where users came from" rows={report.sources} /><Breakdown title="Campaigns" rows={report.campaigns} /><Breakdown title="Landing pages" rows={report.landing_pages} /></div>
    <Card className="mt-5"><CardHeader title="Recent registration attribution" description="Click IDs are retained only as acquisition identifiers; no passwords or payment data are collected." /><CardBody><div className="overflow-x-auto"><table className="min-w-full divide-y divide-gray-200 text-left text-sm"><thead><tr className="text-xs uppercase tracking-wide text-gray-500"><th className="px-3 py-2">User</th><th className="px-3 py-2">Source</th><th className="px-3 py-2">Campaign</th><th className="px-3 py-2">Landing page</th><th className="px-3 py-2">Registered</th></tr></thead><tbody className="divide-y divide-gray-100">{report.recent.map((row) => <tr key={String(row.user_id)}><td className="px-3 py-3"><div className="font-medium text-gray-900">{row.name || "—"}</div><div className="text-xs text-gray-500">{row.email || "—"}</div></td><td className="px-3 py-3">{row.first_source || "Not recorded"}<div className="text-xs text-gray-500">{row.first_medium || ""}</div></td><td className="px-3 py-3">{row.first_campaign || "—"}</td><td className="max-w-xs break-all px-3 py-3 text-xs text-gray-600">{row.first_landing_path || "—"}</td><td className="whitespace-nowrap px-3 py-3 text-xs text-gray-500">{row.created_at || "—"}</td></tr>)}</tbody></table></div></CardBody></Card>
  </>;
}
