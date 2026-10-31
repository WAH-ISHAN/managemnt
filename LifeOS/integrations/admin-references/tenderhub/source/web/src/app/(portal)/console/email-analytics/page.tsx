import { inputClass as fieldClass } from "@/components/console/ui";
import Link from "next/link";
import { authed } from "@/lib/api";
import type { SelectHTMLAttributes } from "react";
import { ChevronDown } from "lucide-react";
import { Badge, Card, PageHeading, Table, TBody, TD, TEmpty, TH, THead, TR, buttonClass } from "@/components/console/ui";
import { PanelError } from "@/components/portal/panel-error";
import { dateTime } from "@/lib/format";
import { EMAIL_KIND_LABELS, EMAIL_STATUS_LABELS, emailStatusLabel } from "@/lib/email-status";

export const metadata = { title: "Email Analytics | TenderHub" };


/** Native select kept for the GET filter form (submits by name), styled as the kit's select. */
function NativeSelect({ className = "", ...props }: SelectHTMLAttributes<HTMLSelectElement>) {
  return (
    <div className={`grid grid-cols-1 ${className}`}>
      <select {...props} className={`${fieldClass} col-start-1 row-start-1 appearance-none pr-8`} />
      <ChevronDown aria-hidden="true" className="pointer-events-none col-start-1 row-start-1 mr-2 size-4 self-center justify-self-end text-gray-500" />
    </div>
  );
}

type DispatchRow = {
  id: number;
  recipient_email: string;
  user_name: string | null;
  subject: string;
  status: string;
  kind: string | null;
  bounce_type: string | null;
  last_error: string | null;
  attempt_count: number;
  max_attempts: number;
  delivered_at: string | null;
  click_count?: number | null;
  attempted_at: string;
  open_count?: number | null;
  opened_at?: string | null;
  last_opened_at?: string | null;
};

export default async function EmailAnalyticsPage({ searchParams }: {
  searchParams: Promise<{ page?: string; q?: string; status?: string; kind?: string }>;
}) {
  const filters = await searchParams;
  const page = Math.max(1, Number.parseInt(filters.page ?? "1", 10) || 1);
  const q = filters.q?.trim() ?? "";
  const status = filters.status && filters.status in EMAIL_STATUS_LABELS ? filters.status : "";
  const kind = filters.kind && filters.kind in EMAIL_KIND_LABELS ? filters.kind : "";
  const params = new URLSearchParams({ page: String(page), q, status, kind });
  const result = await authed(`/api/v1/admin/email/analytics?${params}`);
  if (!result.ok) return <PanelError status={result.status} />;
  const rows: DispatchRow[] = result.body?.data ?? [];
  const total = Number(result.body?.meta?.total ?? 0);
  const href = (nextPage: number) => `/console/email-analytics?${new URLSearchParams({ page: String(nextPage), q, status, kind })}`;

  return (
    <>
      <PageHeading title="Email Analytics" description="Every email TenderHub created, with its lifecycle. “Accepted by mail server” is not delivery: only a provider event marks an email Delivered, Bounced or Spam complaint. Opens mean the recipient's mail client loaded the tracking image." />
      <Card>
        <form action="/console/email-analytics" className="flex flex-wrap items-center gap-3 border-b border-gray-200 px-4 py-4 sm:px-6">
          <input name="q" defaultValue={q} aria-label="Find recipient or user" placeholder="Recipient email or user" className={`${fieldClass} min-w-0 flex-1 basis-56`} />
          <NativeSelect name="status" defaultValue={status} aria-label="Email status">
            <option value="">All statuses</option>
            {Object.entries(EMAIL_STATUS_LABELS).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
          </NativeSelect>
          <NativeSelect name="kind" defaultValue={kind} aria-label="Email type">
            <option value="">All types</option>
            {Object.entries(EMAIL_KIND_LABELS).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
          </NativeSelect>
          <button className={buttonClass("primary")}>Filter</button>
        </form>
        <Table>
          <THead><TH>Attempted</TH><TH>Recipient</TH><TH>Message</TH><TH>Type</TH><TH>Status</TH><TH>Engagement</TH></THead>
          <TBody>
            {rows.map((row) => <TR key={row.id} className="align-top">
              <TD className="whitespace-nowrap">{dateTime(row.attempted_at)}</TD>
              <TD><span className="font-medium text-gray-900">{row.recipient_email}</span><br /><span className="text-gray-500">{row.user_name ?? "User record unavailable"}</span></TD>
              <TD><Link href={`/console/email-analytics/${row.id}`} className="font-medium text-primary-700 hover:underline">{row.subject}</Link></TD>
              <TD>{row.kind ? EMAIL_KIND_LABELS[row.kind] ?? row.kind : "Not recorded"}</TD>
              <TD><span className="font-medium text-gray-900">{emailStatusLabel(row.status, row.bounce_type)}</span>
                {row.attempt_count > 1 || row.status === "queued" ? <><br /><span className="text-gray-500">Attempt {row.attempt_count} of {row.max_attempts}</span></> : null}
                {row.last_error && ["failed", "queued", "bounced", "cancelled"].includes(row.status) ? <><br /><span className="text-gray-500">{row.last_error}</span></> : null}</TD>
              <TD>
                {row.opened_at ? (
                  <span><Badge tone="green">Opened</Badge><br /><span className="text-gray-500">{dateTime(row.last_opened_at ?? row.opened_at)} · {row.open_count ?? 1} load(s)</span></span>
                ) : (
                  <span className="text-gray-500">No open signal</span>
                )}
                {row.click_count ? <><br /><span className="font-medium text-primary-700">{row.click_count} click(s)</span></> : null}
              </TD>
            </TR>)}
            {rows.length === 0 && <TEmpty colSpan={6} title="No recorded email attempts match these filters." />}
          </TBody>
        </Table>
      </Card>
      <nav aria-label="Email dispatch pages" className="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm text-gray-700">
        <span>Page {page} · {total} recorded attempts</span>
        <span className="flex gap-3">
          {page > 1 && <Link href={href(page - 1)} className={buttonClass("secondary")}>Previous</Link>}
          {page * 25 < total && <Link href={href(page + 1)} className={buttonClass("secondary")}>Next</Link>}
        </span>
      </nav>
    </>
  );
}
