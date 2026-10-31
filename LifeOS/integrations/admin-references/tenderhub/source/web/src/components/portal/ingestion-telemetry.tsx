"use client";

import { useEffect, useState } from "react";
import { Alert, Badge, Card, CardBody, CardHeader, Table, TBody, TD, TH, THead, TR } from "@/components/console/ui";
import { dateTime } from "@/lib/format";

type Run = {
  id: number; source_name: string | null; source_slug: string | null; status: string;
  started_at: string | null; completed_at: string | null; duration_ms: number | null;
  items_found: number; items_inserted: number; items_updated: number; items_duplicate: number;
  pages_attempted: number; pages_completed: number; http_failures: number; parse_failures: number;
  error_message: string | null;
};
export type IngestionTelemetryData = {
  checked_at: string;
  pipeline: { run_id: string; state: string; stage: string; started_at: string; updated_at: string; finished_at?: string } | null;
  protection: {
    checked_at: string; integrity: { ok: boolean; commit?: string; reason?: string };
    audit: { verified: boolean; sequence?: number; head?: string; reason?: string };
    disk: { total_bytes: number; free_bytes: number; used_percent: number };
    memory: { MemTotal?: number; MemAvailable?: number };
    collection_policy: { pages_per_batch: number; details_per_batch: number; minimum_request_interval_seconds: number };
    audit_method: string;
    traffic?: { sampled_requests: number; blocked_requests: number; blocked_by_class: Record<string, number>; window: string } | null;
  } | null;
  audit: { sequence: number; signature: string; at: string; state: string } | null;
  source_batch: { state: string; records_found: number; requests_sent: number; duration_seconds: number; updated_at: string } | null;
  recent_runs: Run[];
};

export function IngestionTelemetry({ initial }: { initial: IngestionTelemetryData | null }) {
  const [data, setData] = useState(initial);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    const timer = setInterval(async () => {
      try {
        const response = await fetch("/api/admin/ingest/telemetry", { cache: "no-store" });
        if (!response.ok) throw new Error("Telemetry could not be refreshed.");
        setData((await response.json()).data as IngestionTelemetryData);
        setError(null);
      } catch {
        setError("Live telemetry is unavailable. Any displayed figures are from the last successful check.");
      }
    }, 15000);
    return () => clearInterval(timer);
  }, []);
  const protection = data?.protection;
  const policy = protection?.collection_policy;
  const stale = Boolean(data && protection && Date.parse(data.checked_at) - Date.parse(protection.checked_at) > 600000);
  return (
    <Card>
      <CardHeader title="Collection progress & protection" description="Recorded host activity, resource use and recent database runs. This panel refreshes every 15 seconds." />
      <CardBody>
        {error && <Alert tone="warning" title={error} />}
        {stale && <Alert tone="warning" title="The host protection observation is more than ten minutes old. Verification badges below describe that previous check." />}
        {!data ? <p className="text-sm text-gray-500">Telemetry has not been received from the host.</p> : <div className="space-y-4">
          <dl className="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
            <div><dt className="text-gray-500">Pipeline</dt><dd>{data.pipeline ? `${data.pipeline.state} · ${data.pipeline.stage}` : "No host run recorded yet"}</dd>{data.pipeline && <dd className="mt-1 text-xs text-gray-500">Updated {dateTime(data.pipeline.updated_at)} · {data.pipeline.run_id}</dd>}</div>
            <div><dt className="text-gray-500">Collection budget</dt><dd>{policy ? `${policy.pages_per_batch} listing page(s), ${policy.details_per_batch} document detail(s) per batch` : "Host policy unavailable"}</dd>{policy && <dd className="mt-1 text-xs text-gray-500">At least {policy.minimum_request_interval_seconds}s between data requests · no parallel collection</dd>}</div>
            <div><dt className="text-gray-500">Latest source batch</dt><dd>{data.source_batch ? `${data.source_batch.state} · ${data.source_batch.requests_sent} data requests · ${data.source_batch.records_found} records · ${data.source_batch.duration_seconds}s` : "No bounded batch recorded yet"}</dd></div>
            <div><dt className="text-gray-500">Signed release</dt><dd>{protection ? <Badge tone={protection.integrity.ok ? "green" : "red"}>{protection.integrity.ok ? `Verified · ${protection.integrity.commit?.slice(0, 7)}` : "Verification failed"}</Badge> : "Not observed"}</dd></div>
            <div><dt className="text-gray-500">Ingestion audit chain</dt><dd>{protection ? <Badge tone={protection.audit.verified ? "green" : "red"}>{protection.audit.verified ? `${protection.audit.sequence ?? 0} verified event(s)` : "Verification failed"}</Badge> : "Not observed"}</dd><dd className="mt-1 text-xs text-gray-500">Local signed hash chain detects recorded changes; access controls protect reads.</dd></div>
            <div><dt className="text-gray-500">VPS resources</dt><dd>{protection ? `${protection.disk.used_percent}% disk used · ${(protection.disk.free_bytes / 1024 ** 3).toFixed(1)} GiB free` : "Host metrics unavailable"}</dd>{protection?.memory.MemAvailable != null && <dd className="mt-1 text-xs text-gray-500">{Math.round(protection.memory.MemAvailable / 1024 ** 2)} MiB RAM available · checked {dateTime(protection.checked_at)}</dd>}</div>
            <div><dt className="text-gray-500">Observed blocked requests</dt><dd>{protection?.traffic ? `${protection.traffic.blocked_requests} blocked / ${protection.traffic.sampled_requests} observed` : "Access log has not been observed yet"}</dd>{protection?.traffic && <dd className="mt-1 text-xs text-gray-500">{protection.traffic.window} · {Object.entries(protection.traffic.blocked_by_class).map(([name, count]) => `${name}: ${count}`).join(" · ") || "No blocks in this sample"}</dd>}</div>
          </dl>
          {data.audit && <p className="break-all font-mono text-xs text-gray-500">Latest signed ingestion event: #{data.audit.sequence} · {data.audit.signature} · {dateTime(data.audit.at)}</p>}
          <Table><THead><TR><TH>Source / started</TH><TH>Status</TH><TH>Found / new / updated / duplicate</TH><TH>Pages / duration</TH><TH>Errors</TH></TR></THead><TBody>
            {data.recent_runs.map(run => <TR key={run.id}>
              <TD>{run.source_name ?? run.source_slug ?? `Source run #${run.id}`}<div className="text-xs text-gray-500">{run.started_at ? dateTime(run.started_at) : "Start time not recorded"}</div></TD>
              <TD>{run.status}</TD><TD>{run.items_found} / {run.items_inserted} / {run.items_updated} / {run.items_duplicate}</TD>
              <TD>{run.pages_completed}/{run.pages_attempted} · {run.duration_ms == null ? "Duration not recorded" : `${(run.duration_ms / 1000).toFixed(1)}s`}</TD>
              <TD><span>{run.http_failures} HTTP · {run.parse_failures} parse</span>{run.error_message && <div className="max-w-sm break-words text-xs text-red-700">{run.error_message}</div>}</TD>
            </TR>)}
            {!data.recent_runs.length && <TR><TD>No database ingestion runs recorded.</TD><TD>—</TD><TD>—</TD><TD>—</TD><TD>—</TD></TR>}
          </TBody></Table>
        </div>}
      </CardBody>
    </Card>
  );
}
