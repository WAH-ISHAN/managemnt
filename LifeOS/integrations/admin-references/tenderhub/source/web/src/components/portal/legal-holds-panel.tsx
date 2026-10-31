"use client";
import { useState } from "react";
import { useRouter } from "next/navigation";
import { Card, CardHeader, CardBody, Badge, EmptyState } from "@/components/console/ui";
import { Button, ConfirmDialog, inputClass } from "@/components/console/interactive";
import { Toast } from "@/components/ds/overlay";
import { dateTime } from "@/lib/format";

type LegalHold = { id: number; entity_type: string; entity_id: number; reason: string; created_name?: string | null; created_at: string };

export function LegalHoldsPanel({ rows }: { rows: LegalHold[] }) {
  const router = useRouter();
  const [toast, setToast] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [release, setRelease] = useState<LegalHold | null>(null);
  async function call(method: string, path: string, body?: unknown, ok?: string) {
    if (busy) return false;
    setBusy(true);
    try {
    const res = await fetch(`/api/admin/${path}`, { method, headers: { "Content-Type": "application/json" }, body: body ? JSON.stringify(body) : undefined });
    const j = await res.json().catch(() => ({}));
    setToast(res.ok ? (ok ?? "Done.") : (j.detail ?? "Refused."));
    if (res.ok) router.refresh();
    return res.ok;
    } catch {
      setToast("Could not connect. Reload the hold list before retrying.");
      return false;
    } finally { setBusy(false); }
  }
  return (
    <>
      <Card className="mb-5">
        <CardHeader title="Place a legal hold" description="The held record cannot be deleted until the hold is released." />
        <CardBody>
          <form className="grid gap-4 sm:grid-cols-[auto_8rem_1fr_auto] sm:items-end" onSubmit={async (e) => { e.preventDefault(); const form = e.currentTarget; const f = Object.fromEntries(new FormData(form).entries()); const saved = await call("POST", "legal-holds", { entity_type: f.entity_type, entity_id: Number(f.entity_id), reason: f.reason }, "Hold placed."); if (saved) form.reset(); }}>
            <label className="block"><span className="mb-2 block text-sm/6 font-medium text-gray-900">Entity type</span>
              <select name="entity_type" className={inputClass}><option value="procurement">procurement</option><option value="complaint">complaint</option><option value="contract">contract</option><option value="notice">notice</option><option value="document">document</option></select></label>
            <label className="block"><span className="mb-2 block text-sm/6 font-medium text-gray-900">Entity id</span>
              <input name="entity_id" type="number" min="1" step="1" required className={inputClass} /></label>
            <label className="block min-w-0"><span className="mb-2 block text-sm/6 font-medium text-gray-900">Reason</span>
              <input name="reason" required className={inputClass} /></label>
            <Button type="submit" busy={busy}>{busy ? "Saving…" : "Place hold"}</Button>
          </form>
        </CardBody>
      </Card>
      <Card>
        <CardHeader title="Active holds" />
        {rows.length ? (
          <ul role="list" className="divide-y divide-gray-100">
            {rows.map((h) => (
              <li key={h.id} className="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 px-4 py-4 sm:px-6">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2"><Badge tone="yellow">{h.entity_type} #{h.entity_id}</Badge><span className="text-sm text-gray-900">{h.reason}</span></div>
                  <p className="mt-1 text-xs text-gray-500">by {h.created_name ?? "staff"} · {dateTime(h.created_at)}</p>
                </div>
                <Button size="sm" variant="secondary" disabled={busy} onClick={() => setRelease(h)}>Release</Button>
              </li>
            ))}
          </ul>
        ) : <CardBody><EmptyState title="No active holds" description="No records currently have a legal hold. Place a hold above using an existing record's ID." /></CardBody>}
      </Card>
      <ConfirmDialog open={!!release} onClose={() => setRelease(null)} busy={busy} confirmLabel="Release"
        title="Release legal hold?" message={release ? `Release the legal hold on ${release.entity_type} #${release.entity_id}?` : ""}
        onConfirm={async () => { if (!release) return; await call("POST", `legal-holds/${release.id}/release`, {}, "Hold released."); setRelease(null); }} />
      {toast ? <Toast message={toast} onDone={() => setToast(null)} /> : null}
    </>
  );
}
