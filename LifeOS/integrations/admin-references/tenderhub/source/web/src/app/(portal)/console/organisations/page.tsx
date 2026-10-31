import { PanelError } from "@/components/portal/panel-error";
import { authed } from "@/lib/api";
import { Card, PageHeading } from "@/components/console/ui";
import { OrganisationsTable } from "@/components/portal/organisations-table";

export const metadata = { title: "Organisations" };

export default async function Organisations() {
  const res = await authed("/api/v1/admin/organisations");
  if (!res.ok) return <PanelError status={res.status} />;
  return (
    <>
      <PageHeading title="Organisations" description="Every account on the platform: type, district, grade, verification, plan, seats and what they have actually done." />
      <Card><OrganisationsTable rows={res.body?.data ?? []} /></Card>
    </>
  );
}
