import Link from "next/link";
import { authed } from "@/lib/api";
import { PanelError } from "@/components/portal/panel-error";
import { BacklinkAnalysisClient, type BacklinkData } from "@/components/portal/backlink-analysis-client";
import { PageHeading, buttonClass } from "@/components/console/ui";

export const metadata = { title: "Backlink Analysis | TenderHub Admin" };

export default async function BacklinkAnalysisPage() {
  const res = await authed("/api/v1/admin/seo/backlinks");
  if (!res.ok || !res.body?.data) return <PanelError status={res.status || 503}/>;
  return <><PageHeading title="Backlink Analysis" description="Verified off-page SEO evidence, referring domains, link history and legitimate editorial outreach." crumbs={[{href:"/console/seo",label:"SEO engine"},{href:"/console/seo/backlinks",label:"Backlinks"}]} actions={<Link href="/console/seo" className={buttonClass("secondary","sm")}>On-page SEO</Link>}/><BacklinkAnalysisClient initialData={res.body.data as BacklinkData}/></>;
}
