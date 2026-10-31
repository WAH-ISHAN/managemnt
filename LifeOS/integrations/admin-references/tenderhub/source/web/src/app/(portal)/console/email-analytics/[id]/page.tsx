import { notFound } from "next/navigation";
import { authed } from "@/lib/api";
import type { ReactNode } from "react";
import { ButtonLink, Card, CardBody, CardHeader, DescriptionList, EmptyState, PageHeading } from "@/components/console/ui";
import { PanelError } from "@/components/portal/panel-error";
import { dateTime } from "@/lib/format";
import { EMAIL_KIND_LABELS, emailStatusLabel } from "@/lib/email-status";

export const metadata = { title: "Email Dispatch | TenderHub" };

type ProviderEvent = { event_type: string; recipient: string | null; detail: string | null; occurred_at: string | null; received_at: string };
type TenderSnapshot = { id: number; notice_id: number; event_type: string; title: string; reference: string | null; category: string | null; closing_at: string | null; tender_url: string };

export default async function EmailDispatchPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  if (!/^\d+$/.test(id)) notFound();
  const result = await authed(`/api/v1/admin/email/analytics/${id}`);
  if (result.status === 404) notFound();
  if (!result.ok) return <PanelError status={result.status} />;
  const row = result.body?.data;
  if (!row) return <PanelError status={503} />;

  const details: [string, ReactNode][] = [
    ["Recipient", <span key="r" className="font-medium text-gray-900">{row.recipient_email} ({row.user_name ?? "User record unavailable"})</span>],
    ["Status", <span key="s" className="font-medium text-gray-900">{emailStatusLabel(row.status, row.bounce_type)}</span>],
    ["Type", `${row.kind ? EMAIL_KIND_LABELS[row.kind] ?? row.kind : "Not recorded"} · attempt ${row.attempt_count ?? 0} of ${row.max_attempts ?? 1}`],
    ["Accepted by mail server", row.accepted_at ? dateTime(row.accepted_at) : "Not accepted"],
    ["Delivered (provider confirmed)", row.delivered_at ? dateTime(row.delivered_at) : "No delivery event received"],
    ...(row.bounced_at ? [["Bounced", `${dateTime(row.bounced_at)} (${row.bounce_type})`] as [string, ReactNode]] : []),
    ...(row.complained_at ? [["Spam complaint", dateTime(row.complained_at)] as [string, ReactNode]] : []),
    ["Clicks", row.click_count ? `${row.click_count} (first ${dateTime(row.first_clicked_at)})` : "No tracked click"],
    ...(row.last_error ? [["Last error", row.last_error] as [string, ReactNode]] : []),
    ["Attempted", dateTime(row.attempted_at)],
    ["Completed", row.completed_at ? dateTime(row.completed_at) : "No completion recorded"],
    ["Open signal", <span key="o" className="font-medium text-gray-900">{row.opened_at ? dateTime(row.opened_at) : "No open signal recorded"}</span>],
    ["Last open", row.last_opened_at ? `${dateTime(row.last_opened_at)} (${row.open_count ?? 1} load(s))` : "No tracking pixel load recorded"],
    ["Transport response", row.detail ?? "No response recorded"],
    ["Tracking note", "Email opens are recorded when the recipient email client loads images. Some inboxes block images or proxy them, so unopened does not always mean unread."],
  ];

  return <div className="space-y-6">
    <PageHeading title={`Email attempt #${id}`} description="Stored record of the recipient, exact message text, included tenders, mail server response and provider events." actions={<ButtonLink href="/console/email-analytics" variant="secondary">Back to all emails</ButtonLink>} />
    <Card><CardBody><DescriptionList columns={2} items={details} /></CardBody></Card>
    <Card>
      <CardHeader title="Tender updates in this email" />
      {(row.tenders as TenderSnapshot[]).length ? <ul role="list" className="divide-y divide-gray-100">{(row.tenders as TenderSnapshot[]).map((t) => <li key={t.id} className="px-4 py-4 text-sm sm:px-6">
        <span className="font-semibold text-gray-900">{t.event_type === "closing" ? "Closing soon" : t.event_type === "expired" ? "Deadline passed" : "New"}: {t.title}</span>
        <div className="mt-1 text-gray-500">{t.reference ?? "No reference"} · {t.category ?? "Category not recorded"} · Closing: {t.closing_at ? dateTime(t.closing_at) : "Not recorded"}</div>
        <a href={t.tender_url} className="mt-1 inline-block font-medium text-primary-700 hover:underline">View tender</a>
      </li>)}</ul> : <EmptyState title="No tender updates were stored for this email." />}
    </Card>
    <Card>
      <CardHeader title="Provider events" />
      {(row.provider_events as ProviderEvent[] | undefined)?.length ? <ul role="list" className="divide-y divide-gray-100">{(row.provider_events as ProviderEvent[]).map((e, i) => <li key={i} className="px-4 py-3 text-sm sm:px-6">
        <span className="font-semibold text-gray-900">{e.event_type}</span> <span className="text-gray-500">· {e.occurred_at ? dateTime(e.occurred_at) : dateTime(e.received_at)}</span><div className="text-gray-500">{e.detail}</div>
      </li>)}</ul> : <CardBody><p className="text-sm text-gray-500">No signed provider event has been received for this email.</p></CardBody>}
    </Card>
    <Card>
      <CardHeader title="Exact plain-text email" description={`Subject: ${row.subject}`} />
      <CardBody>
        <pre className="overflow-x-auto rounded-lg bg-gray-50 p-4 text-xs whitespace-pre-wrap text-gray-800">{row.text_body}</pre>
      </CardBody>
    </Card>
  </div>;
}
