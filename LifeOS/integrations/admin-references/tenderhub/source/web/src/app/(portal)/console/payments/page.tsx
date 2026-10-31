import { PanelError } from "@/components/portal/panel-error";
import { authed } from "@/lib/api";
import { Alert, Card, PageHeading } from "@/components/console/ui";
import { PaymentsQueue } from "@/components/portal/payments-queue";
import { PaymentGatewaySettings, type GatewayReport } from "@/components/portal/payment-gateway-settings";

export const metadata = { title: "Payments" };

export default async function Payments() {
  const [res, gateways] = await Promise.all([authed("/api/v1/admin/payments"), authed<GatewayReport>("/api/v1/admin/payments/gateways")]);
  if (!res.ok) return <PanelError status={res.status} />;
  return (
    <>
      <PageHeading title="Payments queue" description="Someone has paid and cannot use what they paid for. This queue decides whether they renew." />
      {gateways.ok && gateways.body?.data ? <PaymentGatewaySettings initial={gateways.body.data as GatewayReport} /> :
        <div className="mb-6"><Alert tone="error" title="Gateway status unavailable">Payment configuration could not be loaded.</Alert></div>}
      <Card><PaymentsQueue rows={res.body?.data ?? []} bank={res.body?.meta?.bank ?? {}} /></Card>
    </>
  );
}
