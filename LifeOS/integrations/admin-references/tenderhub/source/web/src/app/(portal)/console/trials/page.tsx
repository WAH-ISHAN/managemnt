import { inputClass as fieldClass } from "@/components/console/ui";
import Link from "next/link";
import { authed } from "@/lib/api";
import type { SelectHTMLAttributes } from "react";
import { ChevronDown } from "lucide-react";
import { Alert, Card, PageHeading, Table, TBody, TD, TEmpty, TH, THead, TR, buttonClass } from "@/components/console/ui";
import { PanelError } from "@/components/portal/panel-error";
import { dateTime } from "@/lib/format";

export const metadata = { title: "Trial monitoring | TenderHub Console" };


/** Native select kept for the GET filter form (submits by name), styled as the kit's select. */
function NativeSelect({ className = "", ...props }: SelectHTMLAttributes<HTMLSelectElement>) {
  return (
    <div className={`grid grid-cols-1 ${className}`}>
      <select {...props} className={`${fieldClass} col-start-1 row-start-1 appearance-none pr-8`} />
      <ChevronDown aria-hidden="true" className="pointer-events-none col-start-1 row-start-1 mr-2 size-4 self-center justify-self-end text-gray-500" />
    </div>
  );
}

type TrialRow = {
  user_id: number; name: string; email: string; phone: string | null; account_type: string; role: string; status: string;
  email_verified: boolean; organisation: string; plan: string; kyc: string; subscription_status: string;
  trial_started_at: string; trial_ends_at: string; days_left: number; seconds_left: number; bucket: string;
  last_login_at: string | null; categories: string[]; email_alerts: boolean; reminders_sent: number;
};

const BUCKETS: Array<[string, string]> = [
  ["expiring", "Expiring within 7 days"], ["today", "Expires today"], ["1d", "1 day"], ["3d", "2–3 days"],
  ["7d", "4–7 days"], ["active", "More than 7 days"], ["expired", "Expired"], ["", "All trials"],
];

function remaining(r: TrialRow): string {
  if (r.bucket === "expired") return "Expired";
  const hours = Math.floor(r.seconds_left / 3600);
  if (hours < 24) return `${hours} h left`;
  return `${r.days_left} day${r.days_left === 1 ? "" : "s"} left`;
}

export default async function TrialsPage({ searchParams }: {
  searchParams: Promise<{ bucket?: string; q?: string; verified?: string; group?: string; category?: string }>;
}) {
  const f = await searchParams;
  let bucket = f.bucket ?? "expiring";
  const query = (b: string) => new URLSearchParams({ bucket: b, q: f.q ?? "", verified: f.verified ?? "", group: f.group ?? "", category: f.category ?? "" });
  const [first, cats] = await Promise.all([authed(`/api/v1/admin/trials?${query(bucket)}`), authed("/api/v1/taxonomy/categories")]);
  let res = first;
  if (!res.ok) return <PanelError status={res.status} />;
  const counts: Record<string, number> = res.body?.meta?.counts ?? {};
  const expiring = (counts.today ?? 0) + (counts["1d"] ?? 0) + (counts["3d"] ?? 0) + (counts["7d"] ?? 0);
  const allTrials = Object.values(counts).reduce((a, c) => a + c, 0);
  // Opened without a chosen window and nothing expires this week: show every
  // trial rather than an empty table next to a non-zero count.
  const fellBack = f.bucket === undefined && expiring === 0 && allTrials > 0;
  if (fellBack) {
    bucket = "";
    res = await authed(`/api/v1/admin/trials?${query(bucket)}`);
    if (!res.ok) return <PanelError status={res.status} />;
  }
  const rows: TrialRow[] = res.body?.data ?? [];
  const params = query(bucket);
  const categories: Array<{ slug: string; name: string }> = cats.body?.data ?? [];
  const link = (b: string) => `/console/trials?${new URLSearchParams({ ...Object.fromEntries(params), bucket: b })}`;

  return (
    <>
      <PageHeading title="Trial monitoring" description="Accounts on the 60-day free trial, by time left (Asia/Colombo days, the same windows as the 7-, 3- and 1-day reminder emails). Paid organisations are excluded." />
      <nav aria-label="Trial windows" className="mb-4 flex flex-wrap gap-2">
        {BUCKETS.map(([b, label]) => {
          const n = b === "expiring" ? expiring : b === "" ? allTrials : counts[b] ?? 0;
          return (
            <Link key={b || "all"} href={link(b)} aria-current={bucket === b ? "page" : undefined}
              className={`rounded-full px-3 py-1 text-xs font-semibold ${bucket === b ? "bg-primary-600 text-white" : "bg-white text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50"}`}>
              {label} ({n})
            </Link>
          );
        })}
      </nav>
      {fellBack && <div className="mb-4"><Alert tone="info" title={`No trial expires within 7 days, so every trial account is shown (${allTrials}).`} /></div>}
      <Card>
        <form action="/console/trials" className="flex flex-wrap items-center gap-3 border-b border-gray-200 px-4 py-4 sm:px-6">
          <input type="hidden" name="bucket" value={bucket} />
          <label className="sr-only" htmlFor="trial-q">Search</label>
          <input id="trial-q" name="q" defaultValue={f.q ?? ""} placeholder="Name, email, phone or organisation" className={`${fieldClass} min-w-0 flex-1 basis-56`} />
          <label className="sr-only" htmlFor="trial-verified">KYC</label>
          <NativeSelect id="trial-verified" name="verified" defaultValue={f.verified ?? ""}>
            <option value="">Any KYC</option><option value="verified">KYC verified</option><option value="unverified">Not verified</option>
          </NativeSelect>
          <label className="sr-only" htmlFor="trial-group">Account type</label>
          <NativeSelect id="trial-group" name="group" defaultValue={f.group ?? ""}>
            <option value="">Any account type</option><option value="bidder">Bidder</option><option value="company">Company (buyer)</option>
          </NativeSelect>
          <label className="sr-only" htmlFor="trial-category">Alert category</label>
          <NativeSelect id="trial-category" name="category" defaultValue={f.category ?? ""}>
            <option value="">Any category</option>
            {categories.map((c) => <option key={c.slug} value={c.slug}>{c.name}</option>)}
          </NativeSelect>
          <button className={buttonClass("primary")}>Filter</button>
        </form>
        <Table>
          <THead><TH>User</TH><TH>Organisation</TH><TH>Account</TH><TH>KYC</TH><TH>Trial</TH><TH>Time left</TH><TH>Last login</TH><TH>Alerts</TH><TH>Actions</TH></THead>
          <TBody>
            {rows.map((r) => (
              <TR key={r.user_id} className="align-top">
                <TD><span className="font-semibold text-gray-900">{r.name}</span><br /><span className="text-xs text-gray-500">{r.email}</span>{r.phone ? <><br /><span className="text-xs text-gray-500">{r.phone}</span></> : null}</TD>
                <TD><span className="text-gray-900">{r.organisation}</span><br /><span className="text-xs text-gray-500">Plan {r.plan} · {r.subscription_status}</span></TD>
                <TD className="capitalize"><span className="text-gray-900">{r.account_type === "company" ? "Company (buyer)" : r.account_type}</span><br /><span className="text-xs text-gray-500">{r.role}{r.status !== "active" ? ` · ${r.status}` : ""}</span></TD>
                <TD><span className="text-gray-900">{r.kyc}</span><br /><span className="text-xs text-gray-500">{r.email_verified ? "Email verified" : "Email not verified"}</span></TD>
                <TD className="whitespace-nowrap"><span className="text-xs">{dateTime(r.trial_started_at)}<br />to {dateTime(r.trial_ends_at)}</span></TD>
                <TD className="whitespace-nowrap"><span className={`font-semibold ${r.bucket === "expired" ? "text-gray-500" : ["today", "1d"].includes(r.bucket) ? "text-red-700" : r.bucket === "3d" ? "text-yellow-700" : "text-gray-900"}`}>{remaining(r)}</span></TD>
                <TD className="whitespace-nowrap"><span className="text-xs">{r.last_login_at ? dateTime(r.last_login_at) : "Never"}</span></TD>
                <TD><span className="text-xs">{r.email_alerts ? "Email on" : "Email off"}{r.categories.length ? <><br /><span className="text-gray-500">{r.categories.join(", ")}</span></> : null}<br /><span className="text-gray-500">{r.reminders_sent} trial reminder(s)</span></span></TD>
                <TD><span className="text-xs"><Link className="font-semibold text-primary-700 hover:underline" href={`/console/users?q=${encodeURIComponent(r.email)}`}>Open user</Link></span></TD>
              </TR>
            ))}
            {rows.length === 0 && <TEmpty colSpan={9} title="No trial accounts in this window." />}
          </TBody>
        </Table>
      </Card>
    </>
  );
}
