import { PanelError } from "@/components/portal/panel-error";
import { authed } from "@/lib/api";
import { Badge, PageHeading } from "@/components/console/ui";
import { IngestionClient } from "@/components/portal/ingestion-client";

export const metadata = { title: "Ingestion & Crawler Governance" };

export default async function Ingestion() {
  const [sources, queue, schedule, liveRun, telemetry] = await Promise.all([
    authed("/api/v1/admin/ingest/sources"),
    authed("/api/v1/admin/notices"),
    authed("/api/v1/admin/ingest/schedule"),
    authed("/api/v1/admin/ingest/live-run"),
    authed("/api/v1/admin/ingest/telemetry"),
  ]);
  const failed = [sources, queue].find((result) => !result.ok);
  if (failed) return <PanelError status={failed.status} />;
  const rows = sources.body?.data ?? [];
  const pending = queue.body?.data ?? [];
  const automationSchedule = schedule.ok ? schedule.body?.data : null;

  return (
    <>
      <PageHeading
        title="Ingestion & Automation Telemetry"
        description="Continuous automated procurement scraper and data ingestion pipeline. Real-time telemetry across national print press and public portals."
        actions={
          <Badge tone="green" dot>Ingestion sources</Badge>
        }
      />

      <IngestionClient initialSources={rows} pendingNotices={pending} initialSchedule={automationSchedule}
        initialLiveRun={liveRun.ok ? liveRun.body?.data ?? null : null}
        initialTelemetry={telemetry.ok ? telemetry.body?.data ?? null : null} />
    </>
  );
}
