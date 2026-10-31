import { PanelError } from "@/components/portal/panel-error";
import { authed } from "@/lib/api";
import { Card, PageHeading } from "@/components/console/ui";
import { AdminNoticesManager } from "@/components/portal/admin-notices-manager";
import { PublishingControl } from "@/components/portal/publishing-control";

export const metadata = { title: "Notices & Moderation — TenderHub Console" };

export default async function NoticesManagementPage() {
  const [queueRes, noticesRes, publishingRes] = await Promise.all([
    authed("/api/v1/admin/notices"),
    authed("/api/v1/notices?limit=100"),
    authed("/api/v1/admin/publishing"),
  ]);
  const failed = [queueRes, noticesRes, publishingRes].find((result) => !result.ok);
  if (failed) return <PanelError status={failed.status} />;

  const queue = queueRes.body?.data ?? [];
  const notices = noticesRes.body?.data ?? [];

  return (
    <div className="space-y-6">
      <PageHeading
        title="Procurement Notices & Moderation Queue"
        description="Authoritative notice lifecycle management. Review ingested drafts, publish unverified tenders, and create manual public entries with verified newspaper clippings."
      />
      <PublishingControl initial={publishingRes.body.data} />
      <Card>
        <div className="px-4 py-5 sm:p-6">
          <AdminNoticesManager initialQueue={queue} initialNotices={notices} />
        </div>
      </Card>
    </div>
  );
}
