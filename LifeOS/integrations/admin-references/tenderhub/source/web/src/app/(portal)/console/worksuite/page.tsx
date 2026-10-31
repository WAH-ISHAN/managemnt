import { authed } from "@/lib/api";
import { Badge, Card, CardBody, CardHeader, DescriptionList, PageHeading, Stat, StatGrid, Table, TBody, TD, TEmpty, TH, THead, TR } from "@/components/console/ui";
import { PanelError } from "@/components/portal/panel-error";
import { WorkSuiteAdminSwitch } from "@/components/portal/worksuite-admin-switch";

export const metadata = { title: "WorkSuite integration | TenderHub Console" };

type Health = {
  enabled: boolean; setting: { updated_at: string | null; updated_by: number | null }; allowed_hosts: string[];
  connections: Record<string, number>; links: Record<string, number>; calls_24h: number;
  failures_24h: Record<string, number>; last_successful_call_at: string | null;
  recent_failures: Array<{ operation: string; http_status: number | null; outcome: string; detail: string | null; created_at: string }>;
  organisations: Array<{
    id: number; name: string; type: string; plan: string; subscription: string; renews_at: string | null; kyc: string;
    worksuite_company: string | null; connections: number; tenders_synced: number; last_synced_at: string | null;
    eligible: boolean; eligibility: string;
  }>;
};

const ELIGIBILITY_LABEL: Record<string, string> = {
  ELIGIBLE: "Eligible",
  WORKSUITE_KYC_REQUIRED: "KYC not verified",
  WORKSUITE_KYC_PENDING: "KYC under review",
  WORKSUITE_PAYMENT_PENDING: "Payment pending",
  WORKSUITE_SUBSCRIPTION_INACTIVE: "Subscription inactive",
  WORKSUITE_PLAN_REQUIRED: "Plan/trial not entitled",
  WORKSUITE_ACCOUNT_INACTIVE: "No active member",
  WORKSUITE_NOT_AVAILABLE: "Integration off",
};

export default async function WorkSuiteAdminPage() {
  const res = await authed("/api/v1/admin/integrations/worksuite");
  if (!res.ok) return <PanelError status={res.status} />;
  const h: Health = res.body.data;

  return (
    <>
      <PageHeading title="WorkSuite integration" description="Health of user-owned WorkSuite connections and each organisation's access decision. WorkSuite requires a verified organisation with an active, confirmed paid plan (not a trial). No keys or customer WorkSuite data are shown here." />
      <div className="space-y-6">
        <WorkSuiteAdminSwitch enabled={h.enabled} updatedAt={h.setting.updated_at} />
        <StatGrid>
          <Stat label="Connected users" value={h.connections.connected ?? 0} />
          <Stat label="Expired connections" value={h.connections.expired ?? 0} />
          <Stat label="Connections needing attention" value={h.connections.error ?? 0} />
          <Stat label="Disconnected" value={h.connections.revoked ?? 0} />
          <Stat label="Synced tender links" value={h.links.synced ?? 0} />
          <Stat label="Sync failures" value={h.links.failed ?? 0} />
          <Stat label="Outcome uncertain" value={h.links.unknown ?? 0} />
          <Stat label="API calls (24h)" value={h.calls_24h} />
        </StatGrid>
        <Card>
          <CardBody>
            <DescriptionList columns={2} items={[
              ["Last successful WorkSuite call", h.last_successful_call_at ?? "never"],
              ["Failures in 24h", Object.keys(h.failures_24h).length ? Object.entries(h.failures_24h).map(([k, v]) => `${k.replace(/_/g, " ")} ${v}`).join(" · ") : "none"],
              ["Allowed WorkSuite hosts", h.allowed_hosts.join(", ")],
            ]} />
          </CardBody>
        </Card>
        <Card>
          <CardHeader title="Organisations with a WorkSuite connection" />
          <Table>
            <THead><TH>Organisation</TH><TH>WorkSuite access</TH><TH>KYC</TH><TH>Subscription</TH><TH>Plan</TH><TH>WorkSuite company</TH><TH>Connections</TH><TH>Tenders sent</TH><TH>Last synced</TH></THead>
            <TBody>
              {h.organisations.map((o) => (
                <TR key={o.id}>
                  <TD><span className="font-medium text-gray-900">{o.name}</span><span className="block text-xs text-gray-500">{o.type} #{o.id}</span></TD>
                  <TD><Badge tone={o.eligible ? "green" : "yellow"}>{ELIGIBILITY_LABEL[o.eligibility] ?? o.eligibility}</Badge></TD>
                  <TD>{o.kyc}</TD>
                  <TD>{o.subscription}{o.renews_at ? <span className="block text-xs text-gray-500">until {o.renews_at}</span> : null}</TD>
                  <TD>{o.plan}</TD>
                  <TD>{o.worksuite_company ?? "—"}</TD>
                  <TD>{o.connections}</TD>
                  <TD>{o.tenders_synced}</TD>
                  <TD className="whitespace-nowrap">{o.last_synced_at ?? "—"}</TD>
                </TR>
              ))}
              {h.organisations.length === 0 && <TEmpty colSpan={9} title="No organisation has connected WorkSuite." />}
            </TBody>
          </Table>
        </Card>
        <Card>
          <CardHeader title="Recent failed calls" />
          <Table>
            <THead><TH>When</TH><TH>Operation</TH><TH>HTTP</TH><TH>Outcome</TH><TH>Detail</TH></THead>
            <TBody>
              {h.recent_failures.map((f, i) => <TR key={i}><TD className="whitespace-nowrap">{f.created_at}</TD><TD>{f.operation}</TD><TD>{f.http_status ?? "—"}</TD><TD>{f.outcome}</TD><TD>{f.detail ?? "—"}</TD></TR>)}
              {h.recent_failures.length === 0 && <TEmpty colSpan={5} title="No failed calls recorded." />}
            </TBody>
          </Table>
        </Card>
      </div>
    </>
  );
}
