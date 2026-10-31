import { PanelError } from "@/components/portal/panel-error";
import { authed } from "@/lib/api";
import { PageHeading, buttonClass } from "@/components/console/ui";
import { SeoConsoleClient } from "@/components/portal/seo-console-client";
import Link from "next/link";

export const metadata = { title: "SEO & Optimization Engine | TenderHub Admin" };

export default async function SeoConsolePage() {
  const res = await authed("/api/v1/admin/seo/status");
  if (!res.ok) return <PanelError status={res.status} />;

  if (!res.body?.data) return <PanelError status={503} />;

  return (
    <>
      <PageHeading
        title="Unified Website Optimization Engine"
        description="Continuous SEO, AEO, AIO, and GEO monitoring, structured data validation, and AI search engine readiness."
        actions={<Link href="/console/seo/backlinks" className={buttonClass("primary", "sm")}>Backlink analysis</Link>}
      />
      <SeoConsoleClient initialStatus={res.body.data} />
    </>
  );
}
