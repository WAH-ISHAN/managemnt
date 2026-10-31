import { PanelError } from "@/components/portal/panel-error";
import { authed } from "@/lib/api";
import { PageHeading } from "@/components/console/ui";
import { LegalHoldsPanel } from "@/components/portal/legal-holds-panel";

export const metadata = { title: "Legal holds" };
export default async function LegalHoldsPage() {
  const res = await authed("/api/v1/admin/legal-holds");
  if (!res.ok) return <PanelError status={res.status} />;
  return (
    <>
      <PageHeading title="Legal holds" description="While a hold is active, the held record cannot be deleted — evidence is preserved for disputes and audits." />
      <LegalHoldsPanel rows={res.body?.data ?? []} />
    </>
  );
}
