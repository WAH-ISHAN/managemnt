import { PanelError } from "@/components/portal/panel-error";
import { authed } from "@/lib/api";
import { Badge, Card, CardHeader, EmptyState, PageHeading, Stat, StatGrid, Table, TBody, TD, TH, THead, TR, type Tone } from "@/components/console/ui";
import { dateTime } from "@/lib/format";
import { ShieldAlert, ShieldCheck } from "lucide-react";

export const metadata = { title: "Security Centre — TenderHub Console" };
const TONE: Record<string, Tone> = {
  info: "gray",
  warning: "yellow",
  critical: "red",
};
type SecurityEvent = { id: number; kind: string; severity?: string; ip?: string | null; detail?: string | null; created_at?: string | null };

export default async function SecurityPage() {
  const res = await authed("/api/v1/admin/security/events");
  if (!res.ok) return <PanelError status={res.status} />;
  const rows = (res.body?.data ?? []) as SecurityEvent[];
  const sum = res.body?.meta?.summary_24h ?? {};

  return (
    <div className="space-y-6">
      <PageHeading
        title="Security Centre & Event Ledger"
        description="Security-relevant events across the platform: authentication failures, authorization refusals, and access anomalies."
        actions={<Badge tone="green" dot>Active surveillance</Badge>}
      />

      <StatGrid>
        {Object.keys(sum).length ? (
          Object.entries(sum).map(([k, v]) => (
            <Stat
              key={k}
              label={k.replace(/_/g, " ")}
              value={v as number}
              tone={Number(v) > 0 ? "warn" : "good"}
            />
          ))
        ) : (
          /* No per-kind counts were returned: show only what the API
             reported, never fabricated zeros or a "Healthy" verdict. */
          <Stat label="Total events (24h)" value={rows.length} />
        )}
      </StatGrid>

      <Card>
        <CardHeader
          title={<span className="flex items-center gap-2"><ShieldAlert aria-hidden="true" className="size-5 text-gray-500" />Security audit log</span>}
          actions={<span className="text-sm text-gray-500">{rows.length} recorded events</span>}
        />

        {rows.length ? (
          <Table>
            <THead>
              <TH>Event type</TH>
              <TH>Incident detail</TH>
              <TH>IP address</TH>
              <TH align="right">Timestamp</TH>
            </THead>
            <TBody>
              {rows.map((e) => (
                <TR key={e.id}>
                  <TD className="whitespace-nowrap">
                    <Badge tone={TONE[e.severity ?? ""] ?? "gray"}>
                      {e.kind.replace(/_/g, " ")}
                    </Badge>
                  </TD>
                  <TD className="max-w-md truncate">{e.detail ?? "—"}</TD>
                  <TD className="whitespace-nowrap font-mono">{e.ip || "Not recorded"}</TD>
                  <TD align="right" className="whitespace-nowrap">{dateTime(e.created_at)}</TD>
                </TR>
              ))}
            </TBody>
          </Table>
        ) : (
          <EmptyState
            icon={ShieldCheck}
            title="No security incidents recorded"
            description="Failed logins, authorization refusals, and access anomalies will be logged here in real-time."
          />
        )}
      </Card>
    </div>
  );
}
