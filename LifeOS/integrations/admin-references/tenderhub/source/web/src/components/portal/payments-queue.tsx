"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { DataTable, type Column } from "@/components/ds/data-table";
import { Badge, DescriptionList } from "@/components/console/ui";
import { Button, Modal, inputClass } from "@/components/console/interactive";
import { Toast } from "@/components/ds/overlay";
import { lkr, dateTime, date } from "@/lib/format";

type PaymentRow = { id: number; org: string; email: string; amount: number | string; term: string; order_number?: string | null; receipt_url?: string | null; receipt_name?: string | null; slip_ref?: string | null; bank?: string | null; paid_on?: string | null; waiting_hours?: number | null; overdue?: boolean; state: string; channel?: string | null; created_at: string };
type BankInstructions = { account_number: string; bank: string; branch: string };

export function PaymentsQueue({ rows, bank }: { rows: PaymentRow[]; bank: BankInstructions }) {
  const router = useRouter();
  const [review, setReview] = useState<PaymentRow | null>(null);
  const [reason, setReason] = useState("");
  const [toast, setToast] = useState<{ m: string; t: "ok" | "bad" } | null>(null);
  const [busy, setBusy] = useState(false);
  const [view, setView] = useState<"pending" | "reviewed" | "all">("pending");
  const visibleRows = rows.filter((row) => {
    if (view === "pending") return row.state === "claimed";
    if (view === "reviewed") return row.state !== "claimed";
    return true;
  });

  async function act(id: number, what: "confirm" | "reject") {
    if (what === "reject" && !reason.trim()) {
      setToast({ m: "A rejection needs a reason — the subscriber is told it verbatim.", t: "bad" });
      return;
    }
    setBusy(true);
    try {
    const res = await fetch(`/api/admin/payments/${id}/${what}`, {
      method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ reason }),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) { setToast({ m: json.detail ?? "That could not be recorded.", t: "bad" }); return; }
    setReview(null); setReason("");
    router.refresh();
    setToast({ m: what === "confirm" ? "Confirmed. The account is active and the term has started." : "Rejected, with the reason sent.", t: "ok" });
    } catch {
      setToast({ m: "Could not connect. Check the payment status before trying again.", t: "bad" });
    } finally {
      setBusy(false);
    }
  }

  const cols: Column<PaymentRow>[] = [
    { key: "org", header: "Organisation", sortable: true, sortValue: (r) => r.org,
      cell: (r) => <span className="font-medium text-gray-900">{r.org}</span>, meta: (r) => r.email },
    { key: "amount", header: "Amount", width: "110px", align: "right", sortable: true, sortValue: (r) => Number(r.amount),
      cell: (r) => <span className="font-mono tabular">{lkr(Number(r.amount))}</span>, meta: (r) => r.term },
    { key: "slip", header: "Slip", width: "140px", cell: (r) => <span className="font-mono text-xs">{r.slip_ref ?? "—"}</span>,
      meta: (r) => <>{r.order_number ?? r.bank ?? "—"} · {r.paid_on ? date(r.paid_on) : "—"}</> },
    { key: "waiting", header: "Waiting", width: "110px", align: "right", sortable: true, sortValue: (r) => r.waiting_hours ?? -1,
      cell: (r) => r.waiting_hours !== null
        ? <span className={`font-mono tabular ${r.overdue ? "font-semibold text-red-600" : "text-gray-600"}`}>{r.waiting_hours}h</span>
        : <span className="text-gray-400">—</span> },
    { key: "state", header: "State", width: "110px",
      cell: (r) => <Badge tone={r.state === "confirmed" ? "green" : r.state === "rejected" ? "red" : "yellow"}>{r.state}</Badge> },
    { key: "act", header: "", width: "90px", align: "right",
      cell: (r) => r.state === "claimed" ? <Button size="sm" onClick={() => setReview(r)}>Review</Button> : null },
  ];

  return (
    <>
      <div className="mb-3 flex flex-wrap gap-2">
        {[
          ["pending", "Pending receipts"],
          ["reviewed", "Reviewed payments"],
          ["all", "All payment records"],
        ].map(([key, label]) => (
          <button
            key={key}
            type="button"
            onClick={() => setView(key as "pending" | "reviewed" | "all")}
            className={`rounded-md border px-3 py-2 text-xs font-semibold transition ${
              view === key ? "border-primary-600 bg-primary-50 text-primary-800" : "border-gray-200 bg-white text-gray-600 hover:bg-gray-50"
            }`}
          >
            {label}
            <span className="ml-2 font-mono text-xs">
              {key === "pending"
                ? rows.filter((r) => r.state === "claimed").length
                : key === "reviewed"
                ? rows.filter((r) => r.state !== "claimed").length
                : rows.length}
            </span>
          </button>
        ))}
      </div>
      <DataTable rows={visibleRows} columns={cols} searchKeys={(r) => `${r.org} ${r.order_number ?? ""} ${r.slip_ref ?? ""}`}
        filters={[{ key: "state", label: "State",
          options: ["claimed", "confirmed", "rejected"].map((s) => ({ value: s, label: s, n: visibleRows.filter((r) => r.state === s).length })),
          match: (r, s) => s.includes(r.state) }]}
        empty={{ title: view === "pending" ? "No pending receipts" : "No payment records", help: view === "pending" ? "Every uploaded receipt has been reviewed." : "Nothing matches this view." }} />

      <Modal open={!!review} onClose={() => setReview(null)} title="Review this payment" size="lg"
        footer={review ? <>
          <Button variant="secondary" onClick={() => setReview(null)}>Cancel</Button>
          <Button variant="danger" disabled={busy} onClick={() => act(review.id, "reject")}>Reject</Button>
          <Button disabled={busy} onClick={() => act(review.id, "confirm")}>Confirm and activate</Button>
        </> : null}>
        {review ? (
          <div className="space-y-4">
            <DescriptionList columns={2} items={[
              ["Organisation", review.org],
              ["Order", <span key="o" className="font-mono">{review.order_number ?? "—"}</span>],
              ["Amount claimed", <span key="a" className="font-mono">{lkr(Number(review.amount))}</span>],
              ["Term", review.term],
              ["Bank", review.bank ?? "—"],
              ["Slip reference", <span key="s" className="font-mono">{review.slip_ref ?? "—"}</span>],
              ["Receipt", review.receipt_url ? <a key="r" href={review.receipt_url} target="_blank" rel="noreferrer" className="font-semibold text-primary-700 underline">{review.receipt_name ?? "Open receipt"}</a> : "—"],
              ["Date paid", review.paid_on ? date(review.paid_on) : "—"],
              ["Sent by", review.channel ?? "—"],
              ["Claimed", dateTime(review.created_at)],
            ]} />
            <div className="rounded-md bg-gray-50 px-3 py-2.5 text-sm text-gray-600">
              Check this against the statement for <span className="font-mono font-medium">{bank.account_number}</span> at {bank.bank}, {bank.branch}.
            </div>
            <label className="block">
              <span className="mb-2 block text-sm/6 font-medium text-gray-900">Reason, if rejecting — the subscriber is told this verbatim</span>
              <textarea value={reason} onChange={(e) => setReason(e.target.value)} rows={2}
                className={inputClass} />
            </label>
            <p className="text-xs text-gray-500">
              Confirming records the review and activates the organisation in one transaction. Splitting them is how
              an account ends up active with no payment behind it.
            </p>
          </div>
        ) : null}
      </Modal>

      {toast ? <Toast message={toast.m} tone={toast.t} onDone={() => setToast(null)} /> : null}
    </>
  );
}
