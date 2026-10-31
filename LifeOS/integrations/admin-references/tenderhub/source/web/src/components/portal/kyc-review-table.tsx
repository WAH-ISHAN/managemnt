"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { DataTable, type Column } from "@/components/ds/data-table";
import { Alert, Badge } from "@/components/console/ui";
import { Button, Select } from "@/components/console/interactive";
import { Toast } from "@/components/ds/overlay";
import { dateTime } from "@/lib/format";
import { KycDecisionDialog, KYC_STATUS } from "@/components/portal/kyc-decision-dialog";

type KycDoc = { id: number; label?: string; kind: string };
type KycRow = {
  id: number; org_name: string; submitted_at: string; categories?: string | null; submitted_name?: string | null; submitted_email?: string | null;
  reason?: string | null; status: string; reviewer_name?: string | null; reviewed_at?: string | null; documents?: KycDoc[];
  kyc_payload?: { company_name?: string; reg_no?: string; nic?: string } | null;
};

const OPEN = ["pending", "under_review"];
const FILTERS: Array<[string, string]> = [
  ["open", "Awaiting decision"], ["pending", "Pending review"], ["under_review", "Under review"], ["approved", "Approved"],
  ["rejected", "Rejected"], ["resubmission_required", "Resubmission required"], ["all", "All submissions"],
];

/**
 * Console → Verification. Every decision happens in the KYC review screen
 * (documents, details, confirmation, reason, audit history); this list only
 * opens it.
 */
export function KycReviewTable({ rows }: { rows: KycRow[] }) {
  const router = useRouter();
  const [toast, setToast] = useState<string | null>(null);
  const [review, setReview] = useState<number | null>(null);
  const openCount = rows.filter((r) => OPEN.includes(r.status)).length;
  const [status, setStatus] = useState(openCount > 0 || rows.length === 0 ? "open" : "all");
  const matches = (r: KycRow, s: string) => s === "all" || (s === "open" ? OPEN.includes(r.status) : r.status === s);

  const cols: Column<KycRow>[] = [
    { key: "org", header: "Organisation", sortable: true, sortValue: (r) => r.org_name,
      cell: (r) => <span className="font-medium text-gray-900">{r.org_name}</span>,
      meta: (r) => <>submitted {dateTime(r.submitted_at)} · {r.submitted_name || r.submitted_email}<br />{r.kyc_payload?.company_name || r.org_name} · BRN {r.kyc_payload?.reg_no || "—"} · {r.documents?.length ?? 0} document(s){r.reason && <><br />Reason: {r.reason}</>}</> },
    { key: "state", header: "Status", width: "170px",
      cell: (r) => { const [label, tone] = KYC_STATUS[r.status] ?? [r.status, "gray" as const]; return <Badge tone={tone} dot>{label}</Badge>; } },
    { key: "actions", header: "", width: "200px", align: "right",
      cell: (r) => OPEN.includes(r.status)
        ? <Button size="sm" onClick={() => setReview(r.id)}>Review KYC</Button>
        : <span className="flex items-center justify-end gap-2 text-xs text-gray-500">{r.reviewer_name || "Staff"} · {dateTime(r.reviewed_at)}<Button size="sm" variant="secondary" onClick={() => setReview(r.id)}>View</Button></span> },
  ];

  return (
    <>
      <Select label="Submission status" className="mb-4 w-full sm:max-w-xs" value={status} onChange={setStatus}
        options={FILTERS.map(([value, label]) => ({ value, label: `${label} (${rows.filter((r) => matches(r, value)).length})` }))} />
      {openCount === 0 && rows.length > 0 && <div role="status" className="mb-4"><Alert tone="info" title="No KYC submission is waiting for review. Decided submissions are listed below." /></div>}
      <DataTable rows={rows.filter((r) => matches(r, status))} columns={cols} searchKeys={(r) => r.org_name}
        empty={{ title: "No matching KYC submissions", help: "This is a live database list. Organisations must submit KYC from their account panel before staff can review it." }} />
      {review !== null && (
        <KycDecisionDialog submissionId={review} onClose={() => { setReview(null); router.refresh(); }}
          onDecided={(message) => { setReview(null); setToast(message); router.refresh(); }} />
      )}
      {toast ? <Toast message={toast} onDone={() => setToast(null)} /> : null}
    </>
  );
}
