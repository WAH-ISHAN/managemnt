import { PanelError } from "@/components/portal/panel-error";
import { authed } from "@/lib/api";
import { PageHeading } from "@/components/console/ui";
import { KycReviewTable } from "@/components/portal/kyc-review-table";

export const metadata = { title: "Vendor verification" };
export default async function KycPage() {
  const res = await authed("/api/v1/admin/kyc?status=all");
  if (!res.ok) return <PanelError status={res.status} />;
  return (
    <>
      <PageHeading title="Vendor verification" description="Review real organisation KYC submissions and their decision history. Account email verification is managed separately in Users." />
      <KycReviewTable rows={res.body?.data ?? []} />
    </>
  );
}
