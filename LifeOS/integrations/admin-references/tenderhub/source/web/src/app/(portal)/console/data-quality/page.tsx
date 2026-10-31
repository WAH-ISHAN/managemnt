import { authed } from "@/lib/api";
import { PageHeading, Card } from "@/components/console/ui";
import { PanelError } from "@/components/portal/panel-error";

export const metadata = { title: "Tender data quality" };

type Issue = { id: number; title: string; reference: string | null; slug: string; flags: string[] };
type Quality = { total_published: number; missing_closing: number; missing_reference: number; missing_documents: number; unverified: number; unapproved_source: number; recent_issues: Issue[]; checked_at: string };

export default async function DataQuality() {
  const result = await authed("/api/v1/admin/ingest/quality");
  if (!result.ok) return <PanelError status={result.status} />;
  const data = result.body?.data as Quality;
  return <>
    <PageHeading title="Tender data quality" description={`Live review of published notices. Checked ${data.checked_at}. Counts may overlap.`} />
    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      {([['Published', data.total_published], ['Missing closing date', data.missing_closing], ['Missing reference', data.missing_reference], ['No documents', data.missing_documents], ['Unverified', data.unverified], ['Unapproved automated source', data.unapproved_source]] as const).map(([label, value]) =>
        <Card key={label}><div className="p-4"><p className="text-sm text-gray-600">{label}</p><p className="text-2xl font-semibold">{value}</p></div></Card>)}
    </div>
    <Card><div className="p-4"><h2 className="font-semibold">Recent records needing review</h2>
      {data.recent_issues.length === 0 ? <p className="mt-3 text-sm text-gray-600">No flagged published records.</p> :
        <ul className="mt-3 divide-y divide-gray-200">{data.recent_issues.map(issue => <li key={issue.id} className="py-3 text-sm">
          <a href={`/console/notices?notice=${issue.id}`} className="font-medium text-primary-700 hover:underline">{issue.title}</a>
          <p className="text-gray-600">{issue.reference ?? 'No reference'} · {issue.flags.join(', ')}</p>
        </li>)}</ul>}
    </div></Card>
  </>;
}
