"use client";

import { useEffect, useState } from "react";
import { Download, ExternalLink, FileText } from "lucide-react";
import { Alert, Badge, type Tone } from "@/components/console/ui";
import { Button, Modal, inputClass } from "@/components/console/interactive";
import { dateTime } from "@/lib/format";

type KycDocument = {
  id: number; name: string; kind: string; label: string; required: boolean; mime: string | null; size_bytes: number;
  uploaded_at: string; available: boolean; intact: boolean; viewable: boolean; url: string;
};
type AuditEvent = { id: number; event_type: string; summary: string; actor_name: string | null; actor_role: string | null; created_at: string; payload: Record<string, unknown> };
type KycReview = {
  id: number; org_name: string; status: string; verify_state?: string | null; submitted_at: string; categories?: string | null; reason?: string | null;
  review_started_name?: string | null; review_started_at?: string | null; reviewer_name?: string | null; reviewed_at?: string | null;
  kyc_payload?: { company_name?: string; reg_no?: string; nic?: string; notes?: string | null } | null;
  documents: KycDocument[];
  applicant: { id: number; name: string; email: string; phone: string | null; nic: string | null; user_group: string; role: string; status: string;
    email_verified_at: string | null; created_at: string; last_login_at: string | null; registration_category: string | null } | null;
  organisation: { id: number; name: string; type: string; entity_type?: string | null; reg_no: string | null; cida_grade: string | null; contact_email: string | null; contact_phone: string | null;
    verify_state: string; verified_at: string | null; plan: string; created_at: string; district: string | null } | null;
  readiness: { approvable: boolean; problems: string[]; missing_documents: string[]; required_documents: string[] };
  history: {
    submissions: Array<{ id: number; status: string; submitted_at: string; review_started_name: string | null; review_started_at: string | null; reviewer_name: string | null; reviewed_at: string | null; reason: string | null }>;
    audit: AuditEvent[];
  };
};

export const KYC_STATUS: Record<string, [string, Tone]> = {
  pending: ["Pending review", "yellow"],
  under_review: ["Under review", "yellow"],
  approved: ["Approved", "green"],
  verified: ["Verified", "green"],
  rejected: ["Rejected", "red"],
  resubmission_required: ["Resubmission required", "red"],
  suspended: ["Suspended", "red"],
  unverified: ["Not verified", "gray"],
};

function KycBadge({ status }: { status: string }) {
  const [label, tone] = KYC_STATUS[status] ?? [status, "gray" as Tone];
  return <Badge tone={tone} dot>{label}</Badge>;
}

function Field({ label, value, compare }: { label: string; value: React.ReactNode; compare?: string | null }) {
  const differs = typeof value === "string" && compare != null && compare !== "" && value.trim().toLowerCase() !== compare.trim().toLowerCase();
  return (
    <div className="min-w-0">
      <dt className="text-xs font-medium text-gray-500">{label}</dt>
      <dd className="text-sm break-words text-gray-900">{value || "—"}{differs && <span className="ml-1 rounded-md bg-yellow-50 px-1.5 py-0.5 text-xs font-medium text-yellow-800 ring-1 ring-yellow-600/20 ring-inset" title={`Account record: ${compare}`}>differs from account: {compare}</span>}</dd>
    </div>
  );
}

function categories(value: unknown): string {
  try {
    const parsed = typeof value === "string" ? JSON.parse(value) : value;
    return Array.isArray(parsed) ? parsed.filter((item) => typeof item === "string").join(", ") : "";
  } catch {
    return "";
  }
}

const kb = (n: number) => (n >= 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`);

/**
 * The KYC review screen. Everything shown comes from GET admin/kyc/{id}; every
 * decision goes to POST admin/kyc/{id}/review, which re-checks the rules
 * (documents present and intact, confirmation, reason, admin role) server-side.
 * Documents are fetched through the authenticated admin route only.
 */
export function KycDecisionDialog({ submissionId, onClose, onDecided }: {
  submissionId: number;
  onClose: () => void;
  onDecided: (message: string) => void;
}) {
  const [sub, setSub] = useState<KycReview | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [mode, setMode] = useState<"view" | "approve" | "reject" | "request_resubmission">("view");
  const [reason, setReason] = useState("");
  const [reviewed, setReviewed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [docId, setDocId] = useState<number | null>(null);

  useEffect(() => {
    let live = true;
    (async () => {
      try {
        let res = await fetch(`/api/admin/kyc/${submissionId}`, { cache: "no-store" });
        let json = await res.json().catch(() => ({}));
        if (!live) return;
        if (!res.ok || !json.data) { setError(json.detail ?? "The submission could not be loaded."); return; }
        // Opening a pending submission takes it into review (recorded with your name).
        // Read-only staff are refused by the API and simply view it.
        if (json.data.status === "pending") {
          const started = await fetch(`/api/admin/kyc/${submissionId}/start-review`, { method: "POST" });
          if (started.ok) {
            res = await fetch(`/api/admin/kyc/${submissionId}`, { cache: "no-store" });
            json = await res.json().catch(() => json);
          }
        }
        if (!live) return;
        setSub(json.data);
        setDocId(json.data.documents?.[0]?.id ?? null);
      } catch {
        if (live) setError("Could not connect. Try again.");
      }
    })();
    return () => { live = false; };
  }, [submissionId]);

  async function decide(action: "approve" | "reject" | "request_resubmission") {
    setBusy(true);
    setError(null);
    try {
      const res = await fetch(`/api/admin/kyc/${submissionId}/review`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(action === "approve" ? { action, confirm_reviewed: reviewed } : { action, reason }),
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok) {
        setError(json.detail ?? "The decision could not be recorded.");
        return;
      }
      const name = sub?.organisation?.name ?? sub?.org_name ?? "The organisation";
      onDecided(action === "approve" ? `${name} is now KYC verified.` : action === "reject" ? `KYC rejected for ${name}.` : `Resubmission requested from ${name}.`);
    } catch {
      setError("Could not connect. Check the KYC status before trying again.");
    } finally {
      setBusy(false);
    }
  }

  const open = sub ? ["pending", "under_review"].includes(sub.status) : false;
  const doc = sub?.documents.find((d) => d.id === docId) ?? null;
  const payload = sub?.kyc_payload ?? {};

  const footer = !sub || !open ? (
    <Button variant="secondary" onClick={onClose}>Close</Button>
  ) : mode === "approve" ? (
    <>
      <Button variant="secondary" onClick={() => setMode("view")} disabled={busy}>Back</Button>
      <Button onClick={() => decide("approve")} busy={busy} disabled={!reviewed || !sub.readiness.approvable}>Approve and verify</Button>
    </>
  ) : mode !== "view" ? (
    <>
      <Button variant="secondary" onClick={() => setMode("view")} disabled={busy}>Back</Button>
      <Button variant="danger" onClick={() => decide(mode)} busy={busy} disabled={reason.trim().length < 5}>
        {mode === "reject" ? "Reject KYC" : "Request resubmission"}
      </Button>
    </>
  ) : (
    <>
      <Button variant="secondary" onClick={() => { setReason(""); setMode("request_resubmission"); }}>Request resubmission</Button>
      <Button variant="secondary" onClick={() => { setReason(""); setMode("reject"); }}>Reject</Button>
      <Button onClick={() => { setReviewed(false); setMode("approve"); }} disabled={!sub.readiness.approvable}
        title={sub.readiness.approvable ? undefined : sub.readiness.problems.join(" ")}>Approve</Button>
    </>
  );

  return (
    <Modal open onClose={onClose} title="KYC review" size="xl" footer={footer}>
      {!sub && !error && <p role="status" className="text-sm text-gray-500">Loading submission…</p>}
      {error && <div role="alert" className="mb-3"><Alert tone="error" title={error} /></div>}
      {sub && (
        <div className="space-y-4">
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-base font-semibold text-gray-900">{sub.organisation?.name ?? sub.org_name}</span>
            <KycBadge status={sub.status} />
            <span className="text-xs text-gray-500">Submission #{sub.id} · submitted {dateTime(sub.submitted_at)}
              {sub.review_started_name ? ` · review started by ${sub.review_started_name} ${dateTime(sub.review_started_at)}` : ""}
              {sub.reviewer_name ? ` · decided by ${sub.reviewer_name} ${dateTime(sub.reviewed_at)}` : ""}</span>
          </div>
          {!open && sub.reason && <p className="rounded-md bg-gray-50 p-3 text-sm text-gray-700"><strong>Reason given:</strong> {sub.reason}</p>}
          {open && !sub.readiness.approvable && (
            <Alert tone="warning" title="Cannot be approved as submitted.">{sub.readiness.problems.join(" ")} Reject it or request a resubmission.</Alert>
          )}

          <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)]">
            <div className="space-y-4">
              <section className="rounded-lg border border-gray-200 p-4">
                <h3 className="mb-2 text-sm font-semibold text-gray-900">Submitted KYC details</h3>
                <dl className="grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2">
                  <Field label="Company / trading name" value={payload.company_name ?? ""} compare={sub.organisation?.name} />
                  <Field label="Business registration (BRN)" value={payload.reg_no ?? ""} />
                  <Field label="Authorised signatory NIC" value={payload.nic ?? ""} compare={sub.applicant?.nic} />
                  <Field label="Documents declared" value={categories(sub.categories)} />
                  {payload.notes ? <div className="sm:col-span-2"><Field label="Applicant notes" value={payload.notes} /></div> : null}
                </dl>
              </section>
              <section className="rounded-lg border border-gray-200 p-4">
                <h3 className="mb-2 text-sm font-semibold text-gray-900">Applicant account</h3>
                {sub.applicant ? (
                  <dl className="grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2">
                    <Field label="Full name" value={sub.applicant.name} />
                    <Field label="Email" value={`${sub.applicant.email}${sub.applicant.email_verified_at ? " (verified)" : " (not verified)"}`} />
                    <Field label="Mobile" value={sub.applicant.phone ?? ""} />
                    <Field label="NIC on account" value={sub.applicant.nic ?? ""} />
                    <Field label="Account" value={`${sub.applicant.user_group} · ${sub.applicant.role} · ${sub.applicant.status}`} />
                    <Field label="Registered tender category" value={sub.applicant.registration_category ?? ""} />
                    <Field label="Registered" value={dateTime(sub.applicant.created_at)} />
                    <Field label="Last login" value={sub.applicant.last_login_at ? dateTime(sub.applicant.last_login_at) : "Never"} />
                  </dl>
                ) : <p className="text-sm text-gray-500">The submitting account no longer exists.</p>}
                <p className="mt-3 text-xs text-gray-500">TenderHub does not collect name-with-initials or a postal address; an address proof document, when uploaded, is listed under documents.</p>
              </section>
              <section className="rounded-lg border border-gray-200 p-4">
                <h3 className="mb-2 text-sm font-semibold text-gray-900">Organisation record</h3>
                {sub.organisation && (
                  <dl className="grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2">
                    <Field label="Name" value={sub.organisation.name} />
                    <Field label="Entity type / plan" value={`${sub.organisation.entity_type ?? "Legacy / not selected"} · ${sub.organisation.plan}`} />
                    <Field label="Registration no. on record" value={sub.organisation.reg_no ?? ""} compare={payload.reg_no} />
                    <Field label="District" value={sub.organisation.district ?? ""} />
                    <Field label="CIDA grade" value={sub.organisation.cida_grade ?? ""} />
                    <Field label="Contact" value={[sub.organisation.contact_email, sub.organisation.contact_phone].filter(Boolean).join(" · ")} />
                    <Field label="Current verification" value={<KycBadge status={sub.organisation.verify_state} />} />
                  </dl>
                )}
              </section>
            </div>

              <section className="rounded-lg border border-gray-200 p-4">
              <div className="mb-2 flex items-center justify-between gap-2"><h3 className="text-sm font-semibold text-gray-900">KYC documents ({sub.documents.length})</h3><span className="text-xs text-gray-500">Open or download each verified upload</span></div>
              {sub.readiness.missing_documents.length > 0 && <p className="mb-2 text-sm font-semibold text-red-700">Missing required: {sub.readiness.missing_documents.join(", ")}</p>}
              {sub.documents.length === 0 ? <p className="text-sm text-gray-500">No documents are attached to this submission.</p> : (
                <>
                  <ul className="mb-2 flex flex-wrap gap-1.5">
                    {sub.documents.map((d) => (
                      <li key={d.id}>
                        <button type="button" onClick={() => setDocId(d.id)}
                          className={`flex items-center gap-1 rounded-md border px-2 py-1 text-xs ${d.id === docId ? "border-primary-600 bg-primary-50 text-primary-700" : "border-gray-200 text-gray-700 hover:bg-gray-50"}`}>
                          <FileText className="size-3.5" />{d.label}{d.required ? " *" : ""}{!d.available || !d.intact ? " ⚠" : ""}
                        </button>
                      </li>
                    ))}
                  </ul>
                  {doc && (
                    <div>
                      <div className="mb-2 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-600">
                        <span>{doc.name} · {doc.mime ?? "unknown type"} · {kb(doc.size_bytes)} · uploaded {dateTime(doc.uploaded_at)}</span>
                        <span className="flex gap-2">
                          {doc.available && <a href={`${doc.url}?inline=1`} target="_blank" rel="noopener" className="inline-flex items-center gap-1 font-semibold text-primary-600 hover:underline"><ExternalLink className="size-3.5" />Open</a>}
                          {doc.available && <a href={doc.url} download={doc.name} className="inline-flex items-center gap-1 font-semibold text-primary-600 hover:underline"><Download className="size-3.5" />Download</a>}
                        </span>
                      </div>
                      {!doc.available ? <p className="rounded-md bg-red-50 p-3 text-sm text-red-800">The file is missing from storage.</p>
                        : !doc.intact ? <p className="rounded-md bg-red-50 p-3 text-sm text-red-800">The file failed its integrity check (changed since upload).</p>
                          : !doc.viewable ? <p className="rounded-md bg-gray-50 p-3 text-sm text-gray-700">This file type cannot be previewed; download it to review.</p>
                            : doc.mime === "application/pdf"
                              ? <iframe key={doc.id} src={`${doc.url}?inline=1`} title={doc.label} style={{ height: 560 }} className="w-full rounded-md border border-gray-200" />
                              // eslint-disable-next-line @next/next/no-img-element
                              : <img key={doc.id} src={`${doc.url}?inline=1`} alt={doc.label} style={{ maxHeight: 560 }} className="w-full rounded-md border border-gray-200 object-contain" />}
                    </div>
                  )}
                </>
              )}
            </section>
          </div>

          {open && mode === "approve" && (
            <label className="flex items-start gap-2 rounded-lg border border-primary-200 bg-primary-50 p-3 text-sm text-primary-900">
              <input type="checkbox" checked={reviewed} onChange={(e) => setReviewed(e.target.checked)} className="mt-0.5 size-4 rounded-sm border-gray-300 text-primary-600 focus:ring-primary-600" />
              <span>I have reviewed the submitted information and every document above, and they match. Approving marks the organisation <strong>verified</strong>, is recorded in the audit history under my name, and notifies the applicant.</span>
            </label>
          )}
          {open && (mode === "reject" || mode === "request_resubmission") && (
            <label className="block text-sm">
              <span className="mb-2 block text-sm/6 font-medium text-gray-900">
                {mode === "reject" ? "Reason for rejection (the applicant sees this)" : "What must be corrected (the applicant sees this)"}
              </span>
              <textarea value={reason} onChange={(e) => setReason(e.target.value)} rows={3} required minLength={5} className={inputClass} />
            </label>
          )}

          <section className="rounded-lg border border-gray-200 p-4">
            <h3 className="mb-2 text-sm font-semibold text-gray-900">Verification history</h3>
            <div className="grid gap-4 md:grid-cols-2">
              <div>
                <p className="mb-1 text-xs font-medium text-gray-500">Submissions</p>
                <ul className="space-y-1 text-xs">
                  {sub.history.submissions.map((h) => (
                    <li key={h.id} className="flex flex-wrap items-center gap-1.5">
                      <span className="font-mono">#{h.id}</span><KycBadge status={h.status} /><span className="text-gray-500">{dateTime(h.submitted_at)}</span>
                      {h.reviewer_name && <span className="text-gray-500">· {h.reviewer_name}</span>}
                      {h.reason && <span className="w-full text-gray-600">“{h.reason}”</span>}
                    </li>
                  ))}
                </ul>
              </div>
              <div>
                <p className="mb-1 text-xs font-medium text-gray-500">Audit log</p>
                {sub.history.audit.length === 0 ? <p className="text-xs text-gray-500">No recorded events.</p> : (
                  <ul className="max-h-48 space-y-1 overflow-y-auto text-xs">
                    {sub.history.audit.map((e) => (
                      <li key={e.id}>
                        <span className="text-gray-500">{dateTime(e.created_at)}</span> · <strong>{e.summary}</strong>{e.actor_name ? ` · ${e.actor_name}` : ""}
                        {(e.payload.previous_status || e.payload.previous_org_state) ? <span className="text-gray-500"> ({String(e.payload.previous_status ?? e.payload.previous_org_state ?? e.payload.from ?? "")} → {String(e.payload.new_status ?? e.payload.new_org_state ?? e.payload.to ?? "")})</span> : null}
                        {e.payload.reason ? <span className="block text-gray-600">“{String(e.payload.reason)}”</span> : null}
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            </div>
          </section>
        </div>
      )}
    </Modal>
  );
}
