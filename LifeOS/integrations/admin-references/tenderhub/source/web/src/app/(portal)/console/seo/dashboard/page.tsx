import Link from "next/link";
import { ArrowLeft, ExternalLink } from "lucide-react";
import { Badge, buttonClass } from "@/components/console/ui";

export const metadata = { title: "UWOE Dashboard | TenderHub SEO" };

export default function SeoDashboardFullScreenPage() {
  return (
    <div className="flex h-screen flex-col bg-white">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3 sm:px-6">
        <div className="flex min-w-0 flex-wrap items-center gap-3">
          <Link href="/console/seo" className={buttonClass("ghost", "sm")}>
            <ArrowLeft aria-hidden="true" className="size-4" /> Back to SEO console
          </Link>
          <Badge tone="green" dot>Unified Website Optimization Engine (UWOE)</Badge>
        </div>
        <a
          href="/api/admin/admin/seo/dashboard"
          target="_blank"
          rel="noreferrer"
          className={buttonClass("secondary", "sm")}
        >
          Direct HTML view <ExternalLink aria-hidden="true" className="size-4" />
        </a>
      </div>
      <iframe
        src="/api/admin/admin/seo/dashboard"
        className="w-full flex-1 border-0"
        title="Unified Website Optimization Engine Interactive Report"
      />
    </div>
  );
}
