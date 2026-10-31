import { authed } from "@/lib/api";
import { PanelError } from "@/components/portal/panel-error";
import { Alert, Badge, ButtonLink, Card, CardBody, CardHeader, DescriptionList, EmptyState, PageHeading, Stat, StatGrid, Table, TBody, TD, TH, THead, TR, type Tone } from "@/components/console/ui";
import { dateTimeColombo } from "@/lib/format";
import { IndexNowControl } from "@/components/portal/indexnow-control";
import {
  CheckCircle2,
  Clock3,
  Database,
  FileCheck2,
  FileSearch,
  RefreshCw,
} from "lucide-react";

export const metadata = { title: "Automation Analysis — TenderHub Console" };

type Source = {
  id: number;
  name: string;
  state: "healthy" | "failing" | "paused" | "idle";
  today_collected: number;
  total_collected: number;
  last_fetch_at: string | null;
  last_success_at: string | null;
  last_error: string | null;
  last_run_status: string | null;
};

type Run = {
  id: number;
  source: string;
  status: string;
  trigger: string;
  started_at: string | null;
  completed_at: string | null;
  duration_ms: number | null;
  items_found: number;
  items_inserted: number;
  items_updated: number;
  items_duplicate: number;
  parse_failures: number;
  http_failures: number;
  error_message: string | null;
};

function number(value: unknown) {
  return Number(value ?? 0).toLocaleString();
}

function stateTone(state: string): Tone {
  if (state === "healthy" || state === "completed") return "green";
  if (state === "failing" || state === "failed") return "red";
  if (state === "running") return "primary";
  return "yellow";
}

export default async function AutomationAnalysisPage() {
  const response = await authed("/api/v1/admin/reports/automation");
  if (!response.ok) return <PanelError status={response.status} />;

  const data = response.body?.data ?? {};
  const today = data.today ?? {};
  const runs = data.runs_today ?? {};
  const sources: Source[] = data.sources ?? [];
  const recentRuns: Run[] = data.recent_runs ?? [];
  const indexNowResponse = await authed("/api/v1/admin/seo/indexnow");
  const indexNow = indexNowResponse.ok ? indexNowResponse.body?.data : null;

  const metrics = [
    { label: "Collected today", value: today.collected, detail: "official-source records created", icon: Database },
    { label: "Published today", value: today.published, detail: "live in the TenderHub catalogue", icon: FileCheck2 },
    { label: "Awaiting review", value: today.awaiting_review, detail: "requires operator verification", icon: FileSearch },
    { label: "Verified today", value: today.verified, detail: "database verified flag", icon: CheckCircle2 },
  ];

  const pipeline = [
    { label: "Discovered", value: runs.items_found, note: `${number(runs.runs)} runs` },
    { label: "Inserted", value: runs.items_inserted, note: "new records" },
    { label: "Updated", value: runs.items_updated, note: "existing records changed" },
    { label: "Duplicates", value: runs.items_duplicate, note: "unchanged records skipped" },
    { label: "Published", value: today.published, note: "created today and live" },
  ];

  return (
    <div className="space-y-8 pb-12">
      <PageHeading
        title="Automation analysis"
        description="Live database telemetry for the official TenderHub ingestion pipeline. No estimated or placeholder figures."
        meta={<span>Sri Lanka day: {data.window?.date ?? "—"}</span>}
        actions={
          <ButtonLink href="/console/automation" variant="secondary">
            <RefreshCw aria-hidden="true" className="-ml-0.5 size-4" /> Refresh data
          </ButtonLink>
        }
      />

      <StatGrid>
        {metrics.map((metric) => (
          <Stat key={metric.label} icon={metric.icon} label={metric.label} value={number(metric.value)} detail={metric.detail} />
        ))}
      </StatGrid>

      <Card>
        <CardHeader
          title="Today's automation flow"
          description="Counts come from ingestion run records and notices created during the Asia/Colombo calendar day."
          actions={
            <div className="flex flex-wrap gap-x-4 gap-y-1 text-sm text-gray-500">
              <span>Documents: <strong className="font-semibold text-gray-900">{number(today.with_documents)}</strong></span>
              <span>Missing deadline: <strong className="font-semibold text-gray-900">{number(today.missing_closing_date)}</strong></span>
              <span>Failures: <strong className="font-semibold text-gray-900">{number(Number(runs.parse_failures ?? 0) + Number(runs.http_failures ?? 0))}</strong></span>
            </div>
          }
        />
        <CardBody>
          <ol role="list" className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            {pipeline.map((stage, index) => (
              <li key={stage.label} className="rounded-lg bg-gray-50 p-4 ring-1 ring-gray-200">
                <p className="text-sm font-medium text-gray-500">{index + 1}. {stage.label}</p>
                <p className="mt-2 text-2xl font-semibold tracking-tight text-gray-900">{number(stage.value)}</p>
                <p className="mt-1 text-xs text-gray-500">{stage.note}</p>
              </li>
            ))}
          </ol>
        </CardBody>
      </Card>

      {sources.length ? (
        <section className="grid gap-6 xl:grid-cols-2">
          {sources.map((source) => (
            <Card key={source.id}>
              <CardHeader
                title={source.name}
                description="Official automation source"
                actions={<Badge tone={stateTone(source.state)} dot>{source.state}</Badge>}
              />
              <CardBody>
                <dl className="grid grid-cols-2 gap-3">
                  <div className="rounded-lg bg-gray-50 p-3"><dt className="text-sm text-gray-500">Today</dt><dd className="text-xl font-semibold text-gray-900">{number(source.today_collected)}</dd></div>
                  <div className="rounded-lg bg-gray-50 p-3"><dt className="text-sm text-gray-500">All time</dt><dd className="text-xl font-semibold text-gray-900">{number(source.total_collected)}</dd></div>
                </dl>
                <div className="mt-4">
                  <DescriptionList items={[
                    ["Last fetch", source.last_fetch_at ? dateTimeColombo(source.last_fetch_at) : "Never recorded"],
                    ["Last success", source.last_success_at ? dateTimeColombo(source.last_success_at) : "Never recorded"],
                    ["Last run", source.last_run_status ?? "No run recorded"],
                  ]} />
                </div>
                {source.last_error ? <div className="mt-4"><Alert tone="error" title="Last error">{source.last_error}</Alert></div> : null}
              </CardBody>
            </Card>
          ))}
        </section>
      ) : (
        <Alert tone="warning" title="No configured official automation sources were found." />
      )}

      <Card>
        <CardHeader title="Latest automation runs" description="Most recent 20 authentic execution records." />
        {recentRuns.length ? (
          <Table>
            <THead><TH>Run</TH><TH>Source / trigger</TH><TH>Result</TH><TH>Items</TH><TH>Quality</TH><TH align="right">Duration</TH></THead>
            <TBody>
              {recentRuns.map((run) => (
                <TR key={run.id} className="align-top">
                  <TD><p className="font-semibold text-gray-900">#{run.id}</p><p className="mt-1 whitespace-nowrap">{run.started_at ? dateTimeColombo(run.started_at) : "No start time"}</p></TD>
                  <TD><p className="font-medium text-gray-900">{run.source}</p><p className="mt-1">{run.trigger}</p></TD>
                  <TD><Badge tone={stateTone(run.status)}>{run.status}</Badge>{run.error_message ? <p className="mt-2 max-w-xs text-red-600">{run.error_message}</p> : null}</TD>
                  <TD><p className="whitespace-nowrap">{number(run.items_found)} found</p><p className="whitespace-nowrap">{number(run.items_inserted)} new · {number(run.items_updated)} updated</p></TD>
                  <TD><p className="whitespace-nowrap">{number(run.items_duplicate)} duplicates</p><p className="whitespace-nowrap">{number(run.parse_failures)} parse · {number(run.http_failures)} HTTP failures</p></TD>
                  <TD align="right" className="font-mono">{run.duration_ms === null ? "—" : `${(run.duration_ms / 1000).toFixed(1)}s`}</TD>
                </TR>
              ))}
            </TBody>
          </Table>
        ) : (
          <EmptyState icon={Clock3} title="No automation runs have been recorded yet." />
        )}
      </Card>

      {indexNow ? <IndexNowControl initialConfig={indexNow.config} initialRuns={indexNow.recent_runs ?? []} /> : <Alert tone="warning" title="IndexNow telemetry is unavailable." />}

      <Alert tone="info" title="Authenticity note">
        This board includes only the two approved official automation sources. “Verified” reflects the real database verification flag; it is not a fabricated confidence score.
      </Alert>
    </div>
  );
}
