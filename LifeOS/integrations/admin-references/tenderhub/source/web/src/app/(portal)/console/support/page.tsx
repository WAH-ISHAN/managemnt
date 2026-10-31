import { PanelError } from "@/components/portal/panel-error";
import { PageHeading } from "@/components/console/ui";
import { authed } from "@/lib/api";
import { SupportDesk } from "@/components/portal/support-desk";

export const metadata = { title: "Support Desk | TenderHub Admin" };

export default async function SupportPage() {
  const res = await authed("/api/v1/admin/support");
  if (!res.ok) return <PanelError status={res.status} />;
  return (
    <>
      <PageHeading title="Support Desk" description="Handle real website enquiries, route them to staff, and manage TenderHub support mailboxes." />
      <SupportDesk initial={res.body?.data ?? { messages: [], mailboxes: [], staff: [] }} />
    </>
  );
}
