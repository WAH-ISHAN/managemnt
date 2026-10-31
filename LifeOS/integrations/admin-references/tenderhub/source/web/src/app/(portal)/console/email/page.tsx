import { PanelError } from "@/components/portal/panel-error";
import { authed } from "@/lib/api";
import { Alert, PageHeading } from "@/components/console/ui";
import { EmailGatewayClient } from "@/components/portal/email-gateway-client";

export const metadata = { title: "Email Gateway | TenderHub Admin" };

export default async function EmailGatewayPage() {
  const res = await authed("/api/v1/admin/email/status");
  if (!res.ok) return <PanelError status={res.status} />;

  return (
    <>
      <PageHeading
        title="Email Transport Gateway"
        description="Inspect deployment mail settings and send a real diagnostic message. Transport changes require a server configuration update."
      />
      {res.ok && res.body?.data ? (
        <EmailGatewayClient initialStatus={res.body.data} />
      ) : (
        <Alert tone="error" title="Email gateway status unavailable">
          Could not retrieve live email transport configuration from the backend. Please check API connectivity.
        </Alert>
      )}
    </>
  );
}
