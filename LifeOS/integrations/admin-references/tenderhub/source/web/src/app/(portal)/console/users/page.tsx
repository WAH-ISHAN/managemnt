import { inputClass as fieldClass } from "@/components/console/ui";
import { PanelError } from "@/components/portal/panel-error";
import Link from "next/link";
import { authed } from "@/lib/api";
import type { SelectHTMLAttributes } from "react";
import { Building2, ChevronDown, ShieldCheck, UserCog, Users } from "lucide-react";
import { ButtonLink, Card, PageHeading, buttonClass } from "@/components/console/ui";
import { UsersTable } from "@/components/portal/users-table";

export const metadata = { title: "Users" };


/** Native select kept for the GET filter form (submits by name), styled as the kit's select. */
function NativeSelect({ className = "", ...props }: SelectHTMLAttributes<HTMLSelectElement>) {
  return (
    <div className={`grid grid-cols-1 ${className}`}>
      <select {...props} className={`${fieldClass} col-start-1 row-start-1 appearance-none pr-8`} />
      <ChevronDown aria-hidden="true" className="pointer-events-none col-start-1 row-start-1 mr-2 size-4 self-center justify-self-end text-gray-500" />
    </div>
  );
}

export default async function UsersPage({ searchParams }: { searchParams: Promise<{ page?: string; q?: string; status?: string; group?: string }> }) {
  const params = await searchParams;
  const page = Math.max(1, Number.parseInt(params.page ?? "1", 10) || 1);
  const q = params.q?.trim() ?? "";
  const status = ["active", "suspended", "pending"].includes(params.status ?? "") ? params.status! : "";
  const group = ["staff", "bidder", "company"].includes(params.group ?? "") ? params.group! : "";
  const query = new URLSearchParams({ page: String(page) });
  if (q) query.set("q", q);
  if (status) query.set("status", status);
  if (group) query.set("group", group);
  const res = await authed(`/api/v1/admin/users?${query}`);
  if (!res.ok) return <PanelError status={res.status} />;
  const meta = res.body?.meta;
  const counts = {
    allUsers: Number(meta?.account_counts?.all_users ?? meta?.all_total ?? 0),
    clients: Number(meta?.account_counts?.clients ?? 0),
    staff: Number(meta?.account_counts?.staff ?? 0),
    administrators: Number(meta?.account_counts?.administrators ?? 0),
  };
  const stats = [
    { label: "All users", value: counts.allUsers, detail: "Every registered account", icon: Users, tone: "bg-primary-50 text-primary-700" },
    { label: "Clients", value: counts.clients, detail: "Bidder and company accounts", icon: Building2, tone: "bg-blue-50 text-blue-700" },
    { label: "Staff", value: counts.staff, detail: "All console staff accounts", icon: UserCog, tone: "bg-amber-50 text-amber-700" },
    { label: "Administrators", value: counts.administrators, detail: "Owner and full admins", icon: ShieldCheck, tone: "bg-emerald-50 text-emerald-700" },
  ];
  const pageHref = (number: number) => {
    const next = new URLSearchParams({ page: String(number) });
    if (q) next.set("q", q);
    if (status) next.set("status", status);
    if (group) next.set("group", group);
    return `/console/users?${next}`;
  };
  return (
    <>
      <PageHeading
        title="Users"
        description="Every registered account on the platform. Staff accounts cannot be suspended here — use the server directly."
      />
      <section aria-label="Account summary" className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {stats.map((stat) => {
          const Icon = stat.icon;
          return (
            <Card key={stat.label} className="p-5">
              <div className="flex items-start justify-between gap-4">
                <div>
                  <p className="text-sm font-medium text-gray-500">{stat.label}</p>
                  <p className="mt-2 text-3xl font-semibold tracking-tight text-gray-950 tabular-nums">{stat.value.toLocaleString()}</p>
                  <p className="mt-1 text-xs text-gray-500">{stat.detail}</p>
                </div>
                <span className={`rounded-lg p-2.5 ${stat.tone}`}><Icon aria-hidden="true" className="size-5" /></span>
              </div>
            </Card>
          );
        })}
      </section>
      <Card>
        <form className="flex flex-wrap items-center gap-3 border-b border-gray-200 px-4 py-4 sm:px-6" action="/console/users">
          <input name="q" defaultValue={q} aria-label="Search all accounts" placeholder="Search accounts" className={`${fieldClass} min-w-0 flex-1 basis-56`} />
          <NativeSelect name="status" defaultValue={status} aria-label="Filter by account status">
            <option value="">All statuses</option>
            <option value="active">Active</option>
            <option value="suspended">Suspended</option>
            <option value="pending">Pending</option>
          </NativeSelect>
          <NativeSelect name="group" defaultValue={group} aria-label="Filter by account group">
            <option value="">All groups</option>
            <option value="staff">Staff</option>
            <option value="bidder">Bidder</option>
            <option value="company">Company</option>
          </NativeSelect>
          <button type="submit" className={buttonClass("primary")}>Search</button>
          {(q || status || group) && <ButtonLink href="/console/users" variant="secondary">Clear</ButtonLink>}
        </form>
        <UsersTable
          rows={res.body?.data ?? []}
          total={Number(meta?.total ?? 0)}
          allTotal={Number(meta?.all_total ?? meta?.total ?? 0)}
          page={page}
          perPage={Number(meta?.per_page ?? 50)}
        />
      </Card>
      <nav aria-label="Account pages" className="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm text-gray-700">
        <span>Page {page} of {Math.max(1, Number(meta?.pages ?? 1))} ({meta?.total ?? 0} accounts)</span>
        <span className="flex gap-3">
          {page > 1 && <Link href={pageHref(page - 1)} className={buttonClass("secondary")}>Previous</Link>}
          {page < Number(meta?.pages ?? 1) && <Link href={pageHref(page + 1)} className={buttonClass("secondary")}>Next</Link>}
        </span>
      </nav>
    </>
  );
}
