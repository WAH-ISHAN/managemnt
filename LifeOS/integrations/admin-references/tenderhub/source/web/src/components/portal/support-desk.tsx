"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { Alert, Badge, Card, CardHeader, EmptyState } from "@/components/console/ui";
import { Button, Select, inputClass } from "@/components/console/interactive";

type Message = { id: number; name: string; email: string; company?: string | null; phone?: string | null; department?: string | null; message: string; status: string; priority: string; assigned_to?: number | null; assignee_name?: string | null; mailbox_id?: number | null; mailbox_email?: string | null; created_at: string };
type Mailbox = { id: number; label: string; email: string; purpose: string; is_primary: boolean | number };
const STATUS_OPTIONS = [{ value: "new", label: "New" }, { value: "in_progress", label: "In progress" }, { value: "waiting_customer", label: "Waiting for client" }, { value: "resolved", label: "Resolved" }, { value: "closed", label: "Closed" }];
const PRIORITY_OPTIONS = [{ value: "urgent", label: "Urgent" }, { value: "high", label: "High" }, { value: "normal", label: "Normal" }, { value: "low", label: "Low" }];
const PURPOSE_OPTIONS = [{ value: "general_support", label: "General support" }, { value: "sales", label: "Sales" }, { value: "technical", label: "Technical" }, { value: "billing", label: "Billing" }];

type Staff = { id: number; name: string; email: string; role: string };

export function SupportDesk({ initial }: { initial: { messages: Message[]; mailboxes: Mailbox[]; staff: Staff[] } }) {
  const router = useRouter();
  const [busy, setBusy] = useState<number | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [mailboxOpen, setMailboxOpen] = useState(false);
  const [label, setLabel] = useState("");
  const [email, setEmail] = useState("");
  const [purpose, setPurpose] = useState("general_support");

  async function update(message: Message, field: "status" | "priority" | "assigned_to" | "mailbox_id", value: string) {
    setBusy(message.id); setNotice(null);
    const body = { status: field === "status" ? value : message.status, priority: field === "priority" ? value : message.priority, assigned_to: field === "assigned_to" ? value || null : message.assigned_to, mailbox_id: field === "mailbox_id" ? value || null : message.mailbox_id };
    try {
      const res = await fetch(`/api/admin/support/messages/${message.id}`, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) });
      if (!res.ok) { const json = await res.json().catch(() => ({})); setNotice(json.detail ?? "Could not update this support message."); return; }
      router.refresh();
    } catch { setNotice("Network error. Please try again."); } finally { setBusy(null); }
  }

  async function createMailbox(e: React.FormEvent) {
    e.preventDefault(); setNotice(null);
    try {
      const res = await fetch("/api/admin/support/mailboxes", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ label, email, purpose }) });
      const json = await res.json().catch(() => ({}));
      if (!res.ok) { setNotice(json.detail ?? "Could not add mailbox."); return; }
      setLabel(""); setEmail(""); setPurpose("general_support"); setMailboxOpen(false); router.refresh();
    } catch { setNotice("Network error. Please try again."); }
  }

  const staffOptions = [{ value: "", label: "Unassigned" }, ...initial.staff.map((s) => ({ value: String(s.id), label: `${s.name} (${s.role})` }))];
  const mailboxOptions = [{ value: "", label: "Not routed" }, ...initial.mailboxes.map((box) => ({ value: String(box.id), label: box.email }))];

  return <div className="space-y-5">
    {notice && <Alert tone="error" title={notice} />}
    <Card>
      <CardHeader title="Support mailboxes" description="Primary inbox and staff routing aliases. Mailbox accounts themselves must be created in your mail provider."
        actions={<Button size="sm" onClick={() => setMailboxOpen((v) => !v)}>Add sub-mail</Button>} />
      {mailboxOpen && <form onSubmit={createMailbox} className="grid gap-3 border-b border-gray-200 bg-gray-50 p-4 sm:grid-cols-4 sm:px-6">
        <input required value={label} onChange={(e) => setLabel(e.target.value)} placeholder="Mailbox label" aria-label="Mailbox label" className={inputClass} />
        <input required type="email" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="redacted@example.invalid" aria-label="Mailbox email" className={inputClass} />
        <Select value={purpose} onChange={setPurpose} options={PURPOSE_OPTIONS} />
        <Button type="submit" variant="secondary">Save routing mailbox</Button>
      </form>}
      {initial.mailboxes.length === 0 ? <EmptyState title="No support mailboxes configured" /> :
      <div className="grid gap-3 p-4 sm:grid-cols-2 sm:px-6 lg:grid-cols-3">{initial.mailboxes.map((m) => <div key={m.id} className="min-w-0 rounded-lg border border-gray-200 p-3"><div className="flex items-center justify-between gap-2"><span className="truncate font-semibold text-gray-900">{m.label}</span>{Boolean(m.is_primary) && <Badge tone="green">Primary</Badge>}</div><p className="mt-1 truncate text-sm text-primary-600">{m.email}</p><p className="mt-1 text-xs text-gray-500">{m.purpose.replace("_", " ")}</p></div>)}</div>}
    </Card>
    <Card>
      <CardHeader title="Client enquiries" description={<>Messages submitted through TenderHub&apos;s public contact form. No synthetic or placeholder records are shown.</>} />
      <div className="divide-y divide-gray-100">{initial.messages.length === 0 ? <EmptyState title="No client enquiries are currently recorded." /> : initial.messages.map((m) => <article key={m.id} className="grid gap-4 px-4 py-5 sm:px-6 lg:grid-cols-[1fr_16rem]"><div className="min-w-0"><div className="flex flex-wrap items-center gap-2"><h3 className="font-semibold text-gray-900">{m.name}</h3><span className="break-all text-xs text-gray-500">{m.email}</span>{m.company && <span className="text-xs text-gray-500">· {m.company}</span>}</div><p className="mt-1 text-xs text-gray-400">{m.department || "General enquiry"} · {new Date(m.created_at).toLocaleString()}</p><p className="mt-3 text-sm/6 break-words whitespace-pre-wrap text-gray-700">{m.message}</p></div><div className="grid content-start gap-3">
        <Select label="Status" disabled={busy === m.id} value={m.status} onChange={(v) => update(m, "status", v)} options={STATUS_OPTIONS} />
        <Select label="Priority" disabled={busy === m.id} value={m.priority || "normal"} onChange={(v) => update(m, "priority", v)} options={PRIORITY_OPTIONS} />
        <Select label="Assign staff" disabled={busy === m.id} value={m.assigned_to ? String(m.assigned_to) : ""} onChange={(v) => update(m, "assigned_to", v)} options={staffOptions} />
        <Select label="Mailbox" disabled={busy === m.id} value={m.mailbox_id ? String(m.mailbox_id) : ""} onChange={(v) => update(m, "mailbox_id", v)} options={mailboxOptions} />
      </div></article>)}</div>
    </Card>
  </div>;
}
