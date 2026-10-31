import { redirect } from "next/navigation";
import { PortalShell } from "@/components/ds/app-shell";
import { readSession } from "@/lib/session";
import type { NavGroup } from "@/components/ds/sidebar-shell";

const GROUPS: NavGroup[] = [
  { name: "Overview", items: [
    { href: "/console", label: "System health" },
    { href: "/console/infrastructure", label: "Infrastructure" },
    { href: "/console/automation", label: "Automation analysis" },
  ] },
  { name: "Content & ingestion", items: [
    { href: "/console/ingestion", label: "Ingestion" },
    { href: "/console/data-quality", label: "Data quality" },
    { href: "/console/notices", label: "Notices" },
    { href: "/console/blog", label: "Blog" },
    { href: "/console/seo", label: "SEO engine" },
  ] },
  { name: "Marketing", items: [
    { href: "/console/acquisition", label: "Acquisition analytics" },
    { href: "/console/marketing", label: "Social marketing" },
    { href: "/console/campaigns", label: "Campaigns" },
    { href: "/console/email", label: "Email gateway" },
    { href: "/console/email-analytics", label: "Email analytics" },
  ] },
  { name: "Customers", items: [
    { href: "/console/organisations", label: "Organisations" },
    { href: "/console/organisation-portal", label: "Organisation Portal" },
    { href: "/console/users", label: "Users" },
    { href: "/console/trials", label: "Trial monitoring" },
    { href: "/console/kyc", label: "Verification" },
    { href: "/console/support", label: "Support desk" },
  ] },
  { name: "Billing & integrations", items: [
    { href: "/console/payments", label: "Payments" },
    { href: "/console/worksuite", label: "WorkSuite integration" },
  ] },
  { name: "Security & compliance", items: [
    { href: "/console/risk", label: "Risk signals" },
    { href: "/console/security", label: "Security" },
    { href: "/console/legal-holds", label: "Legal holds" },
  ] },
];

export const metadata = { robots: { index: false, follow: false } };

export default async function ConsoleLayout({ children }: { children: React.ReactNode }) {
  const s = await readSession();
  if (!s || s.user.group !== "staff") {
    redirect("/admin");
  }

  return (
    <PortalShell portal="console" orgName="TenderHub" userName={s.user.name} isStaff={true}
      nav={GROUPS.flatMap((g) => g.items)} groups={GROUPS}>
      {children}
    </PortalShell>
  );
}
