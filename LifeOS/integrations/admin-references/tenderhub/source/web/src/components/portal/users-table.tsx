"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { DataTable, type Column } from "@/components/ds/data-table";
import { Plus, UserPlus } from "lucide-react";
import { Alert, Badge, buttonClass, type Tone } from "@/components/console/ui";
import { Button, ConfirmDialog, Field, Modal, Select, inputClass } from "@/components/console/interactive";
import { Toast } from "@/components/ds/overlay";
import { date, dateTime } from "@/lib/format";
import { KycDecisionDialog, KYC_STATUS } from "@/components/portal/kyc-decision-dialog";

type User = {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  nic: string | null;
  email_verified_at: string | null;
  role: string;
  user_group: string;
  status: string;
  created_at: string;
  last_login_at: string | null;
  org_name: string | null;
  org_type: string | null;
  registration_category: string | null;
  discovery_source: string | null;
  is_primary_owner?: boolean;
  /** Organisation KYC: live verify_state + newest submission (from the API). */
  kyc?: {
    state: string | null;
    pending_submission_id: number | null;
    latest_submission_id?: number | null;
    last_submission: { status: string; reason: string | null; submitted_at: string } | null;
    verified_without_review?: boolean;
  };
};

/** The backend's KYC state, labelled. "Verified" only with an approved review on record. */
function kycBadge(r: User): [string, Tone] {
  if (r.user_group === "staff") return ["Not applicable", "gray"];
  if (r.kyc?.verified_without_review) return ["Verified — no KYC on record", "yellow"];
  if (!r.kyc?.last_submission) return r.kyc?.state === "suspended" ? KYC_STATUS.suspended : ["No KYC submitted", "gray"];
  // An open submission shows its own stage (pending / under review).
  if (r.kyc.pending_submission_id) return KYC_STATUS[r.kyc.last_submission.status] ?? KYC_STATUS.pending;
  return KYC_STATUS[r.kyc.state ?? "unverified"] ?? [r.kyc.state ?? "Not verified", "gray"];
}

type Confirm = {
  user: User;
  action: "suspend" | "reactivate" | "delete" | "verify" | "convert-to-bidder";
};

function statusTone(s: string): Tone {
  if (s === "active") return "green";
  if (s === "suspended") return "red";
  if (s === "pending") return "yellow";
  return "gray";
}

function groupTone(g: string): Tone {
  if (g === "staff") return "green";
  if (g === "bidder") return "primary";
  return "gray";
}

const ROLE_OPTIONS = [
  { value: "admin", label: "Administrator (Full)" },
  { value: "editor", label: "Ingestion Operator" },
  { value: "viewer", label: "Auditor / Viewer" },
];


/** Display label; owner and admin are both administrators. */
function staffRoleLabel(r: { user_group?: string | null; role?: string | null }): string {
  if (r.user_group !== "staff") return "User";
  if (r.role === "owner") return "Owner";
  if (r.role === "admin") return "Admin";
  return r.role ? r.role.charAt(0).toUpperCase() + r.role.slice(1) : "Staff";
}

export function UsersTable({
  rows,
  total,
  allTotal,
  page,
  perPage,
}: {
  rows: User[];
  total: number;
  allTotal: number;
  page: number;
  perPage: number;
}) {
  const router = useRouter();
  const [confirm, setConfirm] = useState<Confirm | null>(null);
  const [busy, setBusy] = useState(false);
  const [toast, setToast] = useState<string | null>(null);
  const [kycReview, setKycReview] = useState<number | null>(null);

  // Create User Modal State
  const [createOpen, setCreateOpen] = useState(false);
  const [createLoading, setCreateLoading] = useState(false);
  const [createError, setCreateError] = useState<string | null>(null);
  const [formName, setFormName] = useState("");
  const [formEmail, setFormEmail] = useState("");
  const [formPassword, setFormPassword] = useState("");
  const [formRole, setFormRole] = useState("admin");
  const [formPhone, setFormPhone] = useState("");

  const handleGeneratePassword = () => {
    const chars = "abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%&*";
    const randomValues = new Uint32Array(14);
    window.crypto.getRandomValues(randomValues);
    let gen = "";
    for (let i = 0; i < 14; i++) {
      gen += chars.charAt(randomValues[i] % chars.length);
    }
    setFormPassword(gen);
  };

  async function handleCreateUser(e: React.FormEvent) {
    e.preventDefault();
    setCreateError(null);

    if (!formName.trim()) {
      setCreateError("Please enter full name.");
      return;
    }
    if (!formEmail.trim() || !formEmail.includes("@")) {
      setCreateError("Please enter a valid email address.");
      return;
    }
    if (!formPassword || formPassword.length < 8) {
      setCreateError("Password must be at least 8 characters long.");
      return;
    }

    setCreateLoading(true);
    try {
      const res = await fetch("/api/admin/admin/users", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          name: formName.trim(),
          email: formEmail.trim().toLowerCase(),
          password: formPassword,
          role: formRole,
          user_group: "staff",
          phone: formPhone.trim() || undefined,
        }),
      });

      const json = await res.json().catch(() => ({}));

      if (!res.ok) {
        setCreateError(json?.detail || "Failed to create administrator. Please check the details.");
        return;
      }

      setToast(`Administrator "${formEmail.trim()}" created successfully!`);
      setCreateOpen(false);
      setFormName("");
      setFormEmail("");
      setFormPassword("");
      setFormPhone("");
      router.refresh();
    } catch {
      setCreateError("Network error. Could not reach server to create account.");
    } finally {
      setCreateLoading(false);
    }
  }

  async function doAction(user: User, action: Confirm['action']) {
    if (busy) return;
    setBusy(true);
    try {
      const endpoint =
        action === "delete"
          ? `/api/admin/admin/users/${user.id}/delete`
          : `/api/admin/admin/users/${user.id}/${action}`;

      const res = await fetch(endpoint, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
      });
      const j = await res.json().catch(() => ({}));

      const actionText =
        action === "convert-to-bidder" ? "converted to a bidder account" :
        action === "verify" ? "manually verified" : action === "delete" ? "deleted" : action === "suspend" ? "suspended" : "reactivated";

      setToast(
        res.ok
          ? `User ${user.email} ${actionText} successfully.`
          : (j.detail ?? `Could not complete ${action}.`),
      );
      if (res.ok) router.refresh();
    } catch {
      setToast("Network error. Could not update this account.");
    } finally {
      setBusy(false);
      setConfirm(null);
    }
  }

  const cols: Column<User>[] = [
    {
      key: "name",
      header: "User",
      sortable: true,
      sortValue: (r) => r.name,
      cell: (r) => (
        <div className="flex flex-col gap-0.5">
          <div className="flex flex-wrap items-center gap-1.5">
            <span className="font-medium text-gray-900">{r.name}</span>
            {r.email_verified_at ? <Badge tone="green">Verified</Badge> : <Badge tone="yellow">Unverified</Badge>}
          </div>
          <span className="break-all text-xs text-gray-500">{r.email}</span>
          {r.phone && (
            <span className="font-mono text-xs text-gray-400">Phone: {r.phone}</span>
          )}
          {r.nic && (
            <span className="font-mono text-xs text-gray-400">NIC: {r.nic}</span>
          )}
        </div>
      ),
      meta: (r) => (
        <span className="text-xs text-gray-400">
          {r.org_name ? `Org: ${r.org_name}` : "Direct User"}
        </span>
      ),
    },
    {
      key: "group",
      header: "Group",
      width: "110px",
      sortable: true,
      sortValue: (r) => r.user_group,
      cell: (r) => (
        <Badge tone={groupTone(r.user_group)}>
          {r.user_group === "staff" ? "Staff Admin" : r.user_group}
        </Badge>
      ),
    },
    {
      key: "role",
      header: "Role",
      width: "100px",
      sortable: true,
      sortValue: (r) => staffRoleLabel(r),
      cell: (r) => (
        <Badge tone="gray">{staffRoleLabel(r)}</Badge>
      ),
    },
    {
      key: "status",
      header: "Status",
      width: "100px",
      sortable: true,
      sortValue: (r) => r.status,
      cell: (r) => <Badge tone={statusTone(r.status)}>{r.status}</Badge>,
    },
    {
      key: "kyc",
      header: "KYC",
      width: "170px",
      sortable: true,
      sortValue: (r) => kycBadge(r)[0],
      cell: (r) => {
        const [label, tone] = kycBadge(r);
        const reason = ["rejected", "resubmission_required"].includes(r.kyc?.state ?? "") ? r.kyc?.last_submission?.reason : null;
        return <span title={reason ? `Reason: ${reason}` : undefined}><Badge tone={tone} dot>{label}</Badge></span>;
      },
    },
    {
      key: "registration_category",
      header: "Registered Category",
      width: "220px",
      sortable: true,
      sortValue: (r) => r.registration_category ?? "",
      cell: (r) => <span className="text-xs text-gray-600">{r.registration_category || (r.user_group === "staff" ? "Not applicable" : "Not recorded")}</span>,
    },
    {
      key: "discovery_source",
      header: "Found TenderHub via",
      width: "180px",
      sortable: true,
      sortValue: (r) => r.discovery_source ?? "",
      cell: (r) => <span className="text-xs text-gray-600">{r.discovery_source?.replaceAll("_", " ") || (r.user_group === "staff" ? "Not applicable" : "Not recorded")}</span>,
    },
    {
      key: "created",
      header: "Created",
      width: "140px",
      sortable: true,
      sortValue: (r) => r.created_at ?? "",
      cell: (r) => (
        <span className="text-xs text-gray-500">
          {date(r.created_at)}
        </span>
      ),
    },
    {
      key: "last_seen",
      header: "Last Seen",
      width: "140px",
      sortable: true,
      sortValue: (r) => r.last_login_at ?? "",
      cell: (r) =>
        r.last_login_at ? (
          <span className="text-xs text-gray-600">{dateTime(r.last_login_at)}</span>
        ) : (
          <span className="text-xs text-gray-400">Never</span>
        ),
    },
    {
      key: "actions",
      header: "Actions",
      width: "280px",
      align: "right",
      cell: (r) => {
        const isPrimaryOwner = Boolean(r.is_primary_owner || r.email === "redacted@example.invalid");

        if (isPrimaryOwner) {
          return (
            <Badge tone="primary">Primary Admin</Badge>
          );
        }

        return (
          <div className="flex flex-wrap items-center justify-end gap-1.5">
            <a href={`/api/admin/admin/users/export.xlsx?id=${r.id}`} className={buttonClass("secondary", "xs")}>Excel</a>
            <a href={`/api/admin/admin/users/export.csv?id=${r.id}`} className={buttonClass("secondary", "xs")}>CSV</a>
            <a href={`/api/admin/admin/users/export.pdf?id=${r.id}`} className={buttonClass("secondary", "xs")}>PDF</a>
            {r.user_group === "company" && r.status !== "suspended" && (
              <Button size="sm" variant="secondary" disabled={busy} onClick={() => setConfirm({ user: r, action: "convert-to-bidder" })}>Make bidder</Button>
            )}
            {['bidder', 'company'].includes(r.user_group) && r.kyc?.pending_submission_id ? (
              <Button size="sm" disabled={busy} onClick={() => setKycReview(r.kyc!.pending_submission_id ?? null)}>Review KYC</Button>
            ) : ['bidder', 'company'].includes(r.user_group) && r.kyc?.latest_submission_id ? (
              <Button size="sm" variant="secondary" disabled={busy} onClick={() => setKycReview(r.kyc!.latest_submission_id ?? null)}>KYC documents</Button>
            ) : null}
            {['bidder', 'company'].includes(r.user_group) && !r.email_verified_at && r.status !== 'suspended' && (
              <Button size="sm" variant="secondary" disabled={busy} onClick={() => setConfirm({ user: r, action: "verify" })}>Verify email</Button>
            )}
            {r.user_group !== "staff" ? (
              r.status === "suspended" ? (
                <Button
                  size="sm"
                  variant="secondary"
                  onClick={() => setConfirm({ user: r, action: "reactivate" })}
                >
                  Reactivate
                </Button>
              ) : (
                <Button
                  size="sm"
                  variant="secondary"
                  onClick={() => setConfirm({ user: r, action: "suspend" })}
                >
                  Suspend
                </Button>
              )
            ) : null}

            <Button
              size="sm"
              variant="danger"
              onClick={() => setConfirm({ user: r, action: "delete" })}
            >
              Delete
            </Button>
          </div>
        );
      },
    },
  ];

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 bg-white px-4 py-5 sm:px-6">
        <div className="min-w-0">
          <h2 className="text-base font-semibold text-gray-900">User Accounts Directory</h2>
          <p className="mt-1 text-sm text-gray-500">
            {total === allTotal
              ? `${allTotal.toLocaleString()} total registered account${allTotal === 1 ? "" : "s"} on the platform.`
              : `${total.toLocaleString()} matching account${total === 1 ? "" : "s"} from ${allTotal.toLocaleString()} total.`}
            {rows.length > 0
              ? ` Showing ${((page - 1) * perPage + 1).toLocaleString()}–${Math.min(page * perPage, total).toLocaleString()}.`
              : ""}
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <a href="/api/admin/admin/users/export.xlsx" className={buttonClass("secondary", "md")}>Export Excel</a>
          <a href="/api/admin/admin/users/export.csv" className={buttonClass("secondary", "md")}>Export CSV</a>
          <a href="/api/admin/admin/users/export.pdf" className={buttonClass("secondary", "md")}>Export PDF</a>
          <Button onClick={() => setCreateOpen(true)}>
            <Plus aria-hidden="true" className="-ml-0.5 size-4" />
            Create Administrator
          </Button>
        </div>
      </div>

      <DataTable
        rows={rows}
        columns={cols}
        perPage={Math.max(1, rows.length)}
        empty={{
          title: "No users found",
          help: "Click 'Create Administrator' above to add staff members.",
        }}
      />

      <Modal
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        title="Create Staff Administrator"
        description={<span className="inline-flex items-center gap-1.5"><UserPlus aria-hidden="true" className="size-4 text-primary-600" />Grants administrative access to TenderHub Console.</span>}
        footer={<>
          <Button variant="secondary" onClick={() => setCreateOpen(false)}>Cancel</Button>
          <Button type="submit" form="create-admin-form" busy={createLoading}>{createLoading ? "Creating…" : "Create Administrator"}</Button>
        </>}
      >
        <form id="create-admin-form" onSubmit={handleCreateUser} className="space-y-4">
          {createError && <Alert tone="error" title={createError} />}

          <Field label="Full Name" htmlFor="create-admin-name">
            <input id="create-admin-name" type="text" required value={formName} onChange={(e) => setFormName(e.target.value)}
              placeholder="e.g. Priyantha Silva" className={inputClass} />
          </Field>

          <Field label="Admin Email Address" htmlFor="create-admin-email">
            <input id="create-admin-email" type="email" required value={formEmail} onChange={(e) => setFormEmail(e.target.value)}
              placeholder="e.g. redacted@example.invalid" className={inputClass} />
          </Field>

          <div>
            <div className="flex items-center justify-between gap-2">
              <label htmlFor="create-admin-password" className="block text-sm/6 font-medium text-gray-900">Password (min 8 chars)</label>
              <button type="button" onClick={handleGeneratePassword} className="text-sm font-semibold text-primary-600 hover:text-primary-500">
                Generate Password
              </button>
            </div>
            <div className="mt-2">
              <input id="create-admin-password" type="text" required value={formPassword} onChange={(e) => setFormPassword(e.target.value)}
                autoComplete="new-password" placeholder="••••••••••••" className={`${inputClass} font-mono`} />
            </div>
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Select label="Role Privilege" value={formRole} onChange={setFormRole} options={ROLE_OPTIONS} />
            <Field label="Phone (Optional)" htmlFor="create-admin-phone">
              <input id="create-admin-phone" type="text" value={formPhone} onChange={(e) => setFormPhone(e.target.value)}
                placeholder="077xxxxxxx" className={inputClass} />
            </Field>
          </div>
        </form>
      </Modal>

      {confirm ? (
        <ConfirmDialog
          open
          onClose={() => setConfirm(null)}
          onConfirm={() => doAction(confirm.user, confirm.action)}
          title={
            confirm.action === "convert-to-bidder" ? `Convert ${confirm.user.name} to a bidder account?` :
            confirm.action === "verify" ? `Manually verify ${confirm.user.name}'s email?` : confirm.action === "delete"
              ? `Delete ${confirm.user.name}?`
              : confirm.action === "suspend"
                ? `Suspend ${confirm.user.name}?`
                : `Reactivate ${confirm.user.name}?`
          }
          message={
            confirm.action === "convert-to-bidder" ? `${confirm.user.email} moves from the buyer (company) portal to the bidder portal, and their organisation becomes a bidder organisation. Trial dates, subscription, KYC, role and alert settings are kept. Refused if the organisation has other members or its own tenders. Audited.` :
            confirm.action === "verify" ? `Mark ${confirm.user.email} as verified by administrator override. This is audited and does not approve the organisation's KYC or change its membership. The user should sign in again to refresh their verified session.` : confirm.action === "delete"
              ? `Are you sure you want to permanently delete ${confirm.user.name} (${confirm.user.email})? This action cannot be undone.`
              : confirm.action === "suspend"
                ? `${confirm.user.name} (${confirm.user.email}) will be unable to sign in until reactivated.`
                : `${confirm.user.name} (${confirm.user.email}) will be able to sign in again.`
          }
          confirmLabel={
            confirm.action === "convert-to-bidder" ? "Convert to bidder" :
            confirm.action === "verify" ? "Verify email" : confirm.action === "delete"
              ? "Delete Account"
              : confirm.action === "suspend"
                ? "Suspend"
                : "Reactivate"
          }
          destructive={confirm.action === "delete" || confirm.action === "suspend"}
          busy={busy}
        />
      ) : null}

      {kycReview !== null && (
        <KycDecisionDialog submissionId={kycReview} onClose={() => setKycReview(null)}
          onDecided={(message) => { setKycReview(null); setToast(message); router.refresh(); }} />
      )}

      {toast ? <Toast message={toast} onDone={() => setToast(null)} /> : null}
    </>
  );
}
