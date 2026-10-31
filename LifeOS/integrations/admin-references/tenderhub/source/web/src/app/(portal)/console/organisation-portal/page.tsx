import { authed } from "@/lib/api";
import { Card, CardBody, CardHeader, PageHeading, Stat, StatGrid } from "@/components/console/ui";
import { PanelError } from "@/components/portal/panel-error";
import { OrganisationPortalSwitch } from "@/components/portal/organisation-portal-switch";

export const metadata = { title: "Organisation Portal | TenderHub Console" };

type PortalState = {
  enabled: boolean; updated_at: string | null; updated_by: string | null; message: string;
  organisation_users: number; organisations: number;
};

export default async function OrganisationPortalPage() {
  const res = await authed("/api/v1/admin/portals/organisation");
  if (!res.ok) return <PanelError status={res.status} />;
  const p: PortalState = res.body.data;

  return (
    <>
      <PageHeading
        title="Organisation Portal"
        description="Temporarily open or close the buyer workspace for organisation accounts. Switching only changes access: organisation accounts, tenders, documents, contracts and bids are never modified. The Bidder Portal and tender automation are not affected. Owners and administrators can change this; every change is recorded in the audit ledger."
      />
      <div className="space-y-6">
        <OrganisationPortalSwitch enabled={p.enabled} updatedAt={p.updated_at} updatedBy={p.updated_by} users={p.organisation_users} />
        <StatGrid>
          <Stat label="Organisations" value={p.organisations} />
          <Stat label="Active organisation users" value={p.organisation_users} />
        </StatGrid>
        <Card>
          <CardHeader title="What organisation users see while it is disabled" />
          <CardBody>
            <p className="text-sm text-gray-700">{p.message}</p>
            <p className="mt-3 text-xs text-gray-500">
              They can still sign in and out, view their profile, change their password and use their privacy rights.
              Pending organisation invitations stay valid and can be accepted once the portal is enabled.
            </p>
          </CardBody>
        </Card>
      </div>
    </>
  );
}
