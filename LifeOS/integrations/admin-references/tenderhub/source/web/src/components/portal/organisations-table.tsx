"use client";

import Link from "next/link";
import { DataTable, type Column } from "@/components/ds/data-table";
import { Badge } from "@/components/console/ui";
import { date } from "@/lib/format";
import { KYC_STATUS } from "@/components/portal/kyc-decision-dialog";

type OrganisationRow = { id: number; name: string; district?: string | null; cida_grade?: string | null; created_at: string; type: string; plan: string; sub_status?: string | null; tenders_published: number; bids_lodged: number; seats_used: number; seats: number; verify_state: string };

/**
 * Organisations are verified ONLY by approving a reviewed KYC submission
 * (Console → Verification); this list shows the state and links there.
 */
export function OrganisationsTable({ rows }: { rows: OrganisationRow[] }) {
  const cols: Column<OrganisationRow>[] = [
    { key: "name", header: "Organisation", sortable: true, sortValue: (r) => r.name,
      cell: (r) => (
        <span className="flex items-center gap-2.5">
          <span aria-hidden="true" className="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary-50 text-xs font-semibold text-primary-700">{r.name.trim().charAt(0).toUpperCase() || "?"}</span>
          <span className="font-medium text-gray-900">{r.name}</span>
        </span>
      ),
      meta: (r) => <>{r.district ?? "—"}{r.cida_grade ? ` · CIDA ${r.cida_grade}` : ""} · joined {date(r.created_at)}</> },
    { key: "type", header: "Type", width: "100px", sortable: true, sortValue: (r) => r.type,
      cell: (r) => <Badge tone={r.type === "company" ? "primary" : r.type === "staff" ? "green" : "gray"}>{r.type}</Badge> },
    { key: "plan", header: "Plan", width: "110px", sortable: true, sortValue: (r) => r.plan,
      cell: (r) => <span className="text-sm">{r.plan}</span>,
      meta: (r) => r.sub_status },
    { key: "activity", header: "Activity", width: "150px",
      cell: (r) => <span className="font-mono text-xs text-gray-600">{r.tenders_published} published · {r.bids_lodged} bids</span>,
      meta: (r) => `${r.seats_used} of ${r.seats} seats` },
    { key: "verify", header: "Verification", width: "160px", align: "right",
      cell: (r) => {
        const [label, tone] = KYC_STATUS[r.verify_state] ?? [r.verify_state, "gray" as const];
        return <span className="flex flex-col items-end gap-1"><Badge tone={tone} dot>{label}</Badge>
          {r.type !== "staff" && r.verify_state !== "verified" && <Link href="/console/kyc" className="text-xs font-semibold text-primary-700 hover:underline">KYC review</Link>}</span>;
      } },
  ];

  return (
    <>
      <DataTable rows={rows} columns={cols} searchKeys={(r) => `${r.name} ${r.district ?? ""}`}
        filters={[
          { key: "type", label: "Type", options: ["bidder", "company", "staff"].map((t) => ({ value: t, label: t, n: rows.filter((r) => r.type === t).length })), match: (r, s) => s.includes(r.type) },
          { key: "verify", label: "Verification", options: ["unverified", "pending", "verified", "rejected", "resubmission_required", "suspended"].map((t) => ({ value: t, label: t, n: rows.filter((r) => r.verify_state === t).length })), match: (r, s) => s.includes(r.verify_state) },
        ]}
        empty={{ title: "No organisations" }} />
    </>
  );
}
