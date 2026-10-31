"use client";

import { useCallback, useEffect, useState } from "react";
import Image from "next/image";
import { Alert, Badge, Card, CardBody, CardHeader, EmptyState, StatusBadge, buttonClass } from "@/components/console/ui";
import { Button, Field, Select, inputClass } from "@/components/console/interactive";

type Claim = { id: number; feature_key: string; content_type: string; locale: string; claim_text: string; evidence_ref: string; status: string; verified_by: string | null };
type Campaign = { id: number; name: string; content_type: string; headline: string; caption: string; poster_url: string | null; status: string; claim_text: string; claim_status: string; template_id: string; publish_channels: string; publish_at: string | null; cta_label: string; caption_style: string };
type Template = { id: string; name: string; built_in: boolean };
type Publication = { id: number; campaign_id: number; platform: string; status: string; scheduled_at: string; external_post_id: string | null; post_url: string | null; last_error: string | null };

const base = "/api/admin/marketing/benefits";
async function api<T>(path: string, method = "GET", body?: object): Promise<T> {
  const response = await fetch(`${base}${path}`, {
    method, cache: "no-store", headers: body ? { "Content-Type": "application/json" } : undefined,
    body: body ? JSON.stringify(body) : undefined,
  });
  const json = await response.json().catch(() => ({}));
  if (!response.ok || json.success === false) throw new Error(json.error || `Request failed (${response.status}).`);
  return json.data as T;
}

const primary = buttonClass("primary");
const secondary = buttonClass("secondary");
const TOPICS = [
  { value: "FEATURE_BENEFIT", label: "Product benefit" },
  { value: "HOW_IT_WORKS", label: "How it works" },
  { value: "PROBLEM_SOLUTION", label: "Problem and solution" },
  { value: "CUSTOMER_SERVICE", label: "Customer service" },
  { value: "QUALITY", label: "Quality and trust" },
];
const CTA_LABELS = ["Explore TenderHub", "Learn More", "Get Started"].map(v => ({ value: v, label: v }));
const CAPTION_STYLES = [{ value: "direct", label: "Direct" }, { value: "question", label: "Question" }, { value: "educational", label: "Educational" }];
const checkbox = "size-4 rounded-sm border-gray-300 text-primary-600 focus:ring-primary-600";

export function BenefitsMarketingClient() {
  const [claims, setClaims] = useState<Claim[]>([]);
  const [campaigns, setCampaigns] = useState<Campaign[]>([]);
  const [templates, setTemplates] = useState<Template[]>([]);
  const [publications, setPublications] = useState<Publication[]>([]);
  const [geminiConfigured, setGeminiConfigured] = useState(false);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [claimKey, setClaimKey] = useState("");
  const [claimKind, setClaimKind] = useState("FEATURE_BENEFIT");
  const [claimText, setClaimText] = useState("");
  const [evidence, setEvidence] = useState("");
  const [name, setName] = useState("");
  const [claimId, setClaimId] = useState("");
  const [kind, setKind] = useState("FEATURE_BENEFIT");
  const [template, setTemplate] = useState("benefits");
  const [cta, setCta] = useState("https://tenderhub.lk/");
  const [ctaLabel, setCtaLabel] = useState("Explore TenderHub");
  const [captionStyle, setCaptionStyle] = useState("direct");
  const [useGemini, setUseGemini] = useState(false);
  const [channels, setChannels] = useState<string[]>([]);
  const [publishAt, setPublishAt] = useState("");
  const [templateName, setTemplateName] = useState("");
  const [templateFile, setTemplateFile] = useState<File | null>(null);

  const refresh = useCallback(async () => {
    setLoading(true);
    try {
      const [c, p, t, pubs, config] = await Promise.all([api<Claim[]>("/claims"), api<Campaign[]>("/campaigns"), api<Template[]>("/templates"), api<Publication[]>("/publications"), api<{ gemini_configured: boolean }>("/config")]);
      setClaims(c); setCampaigns(p); setTemplates(t); setPublications(pubs); setGeminiConfigured(config.gemini_configured); setError("");
    } catch (e) { setError(e instanceof Error ? e.message : "Could not load benefits marketing."); }
    finally { setLoading(false); }
  }, []);
  useEffect(() => { const timer = setTimeout(() => { void refresh(); }, 0); return () => clearTimeout(timer); }, [refresh]);

  async function action(task: () => Promise<unknown>, success: string) {
    setBusy(true); setError(""); setNotice("");
    try { await task(); setNotice(success); await refresh(); }
    catch (e) { setError(e instanceof Error ? e.message : "Action failed."); }
    finally { setBusy(false); }
  }

  const verified = claims.filter(c => c.status === "VERIFIED");
  const selectedClaim = verified.find(c => String(c.id) === claimId);
  const toggleChannel = (channel: string) => setChannels(current => current.includes(channel) ? current.filter(c => c !== channel) : [...current, channel]);
  async function uploadTemplate() {
    if (!templateFile) return;
    if (templateFile.size > 8 * 1024 * 1024) { setError("Template image must be 8 MB or less."); return; }
    const image = await new Promise<string>((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve(String(reader.result)); reader.onerror = () => reject(new Error("Could not read image."));
      reader.readAsDataURL(templateFile);
    });
    await action(() => api("/templates", "POST", { name: templateName, image }), "Background template added to the library.");
    setTemplateFile(null); setTemplateName("");
  }
  const posterPath = (url: string) => `/api/admin/marketing-storage/${url.replace("/storage/", "")}`;
  return <div className="space-y-6">
    <Card><CardBody>
      <h2 className="text-base font-semibold text-gray-900">TenderHub benefits posters</h2>
      <p className="mt-1 text-sm text-gray-500">Tender notice automation continues separately. Campaign drafts are prepared Tuesday and Friday after 9:00 AM Sri Lanka time when verified claims exist. A campaign goes to Facebook or Instagram only after you approve its poster.</p>
      <button className={`${primary} mt-3`} disabled={busy || loading || !claims.some(c => c.status === 'VERIFIED')} onClick={() => void action(() => api('/generate', 'POST'), 'Campaign draft generated from your verified claims. Review the poster and caption below.')}>Generate campaign now</button>
      <p className="mt-2 text-xs text-gray-500">Automatically chooses a product fact and matching template. Uses Gemini when configured. You can generate now without waiting for the weekly schedule.</p>
    </CardBody></Card>
    {error && <Alert tone="error" title={error} actions={<Button variant="secondary" size="sm" onClick={() => void refresh()}>Retry</Button>} />}
    {notice && <Alert tone="success" title={notice} />}
    {loading ? <p role="status" className="text-sm text-gray-500">Loading verified claims and campaigns...</p> : <>
      <div className="grid gap-6 xl:grid-cols-2">
        <form className="space-y-4 rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-900/5 sm:p-6" onSubmit={e => { e.preventDefault(); void action(() => api("/claims", "POST", { feature_key: claimKey, content_type: claimKind, claim_text: claimText, evidence_ref: evidence, locale: "en-LK" }), "Claim submitted for verification."); }}>
          <h3 className="text-base font-semibold text-gray-900">1. Record a product fact</h3>
          <p className="text-xs text-gray-500">Reference real code, product documentation or approved business policy. Owner verification is required before use.</p>
          <Field label="Feature key" htmlFor="bm-claim-key"><input id="bm-claim-key" className={inputClass} value={claimKey} onChange={e => setClaimKey(e.target.value)} placeholder="example_feature" required /></Field>
          <Select label="Topic" value={claimKind} onChange={setClaimKind} options={TOPICS} />
          <Field label="Exact approved wording" htmlFor="bm-claim-text"><textarea id="bm-claim-text" className={inputClass} value={claimText} onChange={e => setClaimText(e.target.value)} rows={3} maxLength={150} required /></Field>
          <Field label="Evidence reference" htmlFor="bm-evidence"><textarea id="bm-evidence" className={inputClass} value={evidence} onChange={e => setEvidence(e.target.value)} rows={2} placeholder="Route, code path, documentation or policy" required /></Field>
          <button className={primary} disabled={busy}>Submit claim</button>
        </form>

        <form className="space-y-4 rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-900/5 sm:p-6" onSubmit={e => { e.preventDefault(); void action(() => api("/campaigns", "POST", { name, claim_id: Number(claimId), content_type: kind, template_id: template, cta_url: cta, cta_label: ctaLabel, caption_style: captionStyle, use_gemini: useGemini, publish_channels: channels, publish_at: publishAt ? new Date(publishAt).toISOString() : null }), "Poster draft created. Review the artwork and caption before approval."); }}>
          <h3 className="text-base font-semibold text-gray-900">2. Make a colorful poster draft</h3>
          <Field label="Campaign name" htmlFor="bm-name"><input id="bm-name" className={inputClass} value={name} onChange={e => setName(e.target.value)} required /></Field>
          <Select label="Verified claim" value={claimId} onChange={setClaimId} placeholder="Select a verified claim" options={verified.map(c => ({ value: String(c.id), label: c.claim_text }))} />
          <Select label="Post theme" value={kind} onChange={setKind} options={TOPICS} />
          <Select label="Poster layout" value={template} onChange={setTemplate} options={templates.map(t => ({ value: t.id, label: `${t.name}${t.built_in ? " · built in" : " · uploaded"}` }))} />
          <Field label="TenderHub landing page" htmlFor="bm-cta"><input id="bm-cta" className={inputClass} type="url" value={cta} onChange={e => setCta(e.target.value)} required /></Field>
          <div className="grid gap-3 sm:grid-cols-2"><Select label="Poster CTA" value={ctaLabel} onChange={setCtaLabel} options={CTA_LABELS} />
            <Select label="Caption style" value={captionStyle} onChange={setCaptionStyle} options={CAPTION_STYLES} /></div>
          <label className="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" className={checkbox} checked={useGemini} disabled={!geminiConfigured} onChange={e => setUseGemini(e.target.checked)} />Use Gemini to write an original headline and caption opening</label>
          {!geminiConfigured && <p className="text-xs text-gray-500">Gemini is not configured on the server. A fixed headline will be used.</p>}
          {selectedClaim && <p className="rounded-md bg-primary-50 p-3 text-xs text-gray-700"><strong>Poster fact:</strong> {selectedClaim.claim_text}<br /><strong>Caption:</strong> {captionStyle === "question" ? "Want to learn more about TenderHub? · " : captionStyle === "educational" ? "Get to know TenderHub. · " : ""}{selectedClaim.claim_text} · {ctaLabel}: {cta}</p>}
          <fieldset className="space-y-2 rounded-md border border-gray-200 p-3"><legend className="px-1 text-sm font-medium text-gray-900">Publish after approval</legend>
            <div className="flex flex-wrap gap-5 text-sm text-gray-700"><label className="flex items-center gap-2"><input type="checkbox" className={checkbox} checked={channels.includes("facebook")} onChange={() => toggleChannel("facebook")} />Facebook</label><label className="flex items-center gap-2"><input type="checkbox" className={checkbox} checked={channels.includes("instagram")} onChange={() => toggleChannel("instagram")} />Instagram</label></div>
            <p className="text-xs text-gray-500">Leave both off for a private poster. Selected channels queue automatically when you approve the draft.</p>
            {channels.length > 0 && <Field label="Optional publish time (your local time)" htmlFor="bm-publish-at" hint="Empty means publish as soon as approved."><input id="bm-publish-at" className={inputClass} type="datetime-local" value={publishAt} onChange={e => setPublishAt(e.target.value)} /></Field>}
          </fieldset>
          <button className={primary} disabled={busy || verified.length === 0 || !claimId}>Create draft poster</button>
          {verified.length === 0 && <p className="text-xs text-yellow-800">Verify a claim first. No claim is assumed true by default.</p>}
        </form>
      </div>

      <Card><CardHeader title="Poster template library" description="Upload a text-free 1080 × 1350 PNG or JPEG background. TenderHub logo, verified claim and CTA are added by the renderer." /><CardBody>
        <div className=" flex flex-wrap gap-2">{templates.map(t => <button key={t.id} type="button" className={template === t.id ? buttonClass("soft", "sm") : buttonClass("secondary", "sm")} onClick={() => setTemplate(t.id)}>{t.name}</button>)}</div>
        <div className="mt-4 grid gap-4 sm:grid-cols-[1fr_1fr_auto]">
          <Field label="Template name" htmlFor="bm-template-name"><input id="bm-template-name" className={inputClass} value={templateName} onChange={e => setTemplateName(e.target.value)} maxLength={100} placeholder="Business background" /></Field>
          <Field label="Background image" htmlFor="bm-template-file"><input id="bm-template-file" className={inputClass} type="file" accept="image/png,image/jpeg" onChange={e => setTemplateFile(e.target.files?.[0] || null)} /></Field>
          <button className={`${secondary} self-end`} disabled={busy || !templateFile || templateName.trim().length < 3} onClick={() => void uploadTemplate().catch(e => setError(e instanceof Error ? e.message : "Upload failed."))}>Add template</button>
        </div>
      </CardBody></Card>

      <Card><CardHeader title="Claims library" />
        {claims.length === 0 ? <EmptyState title="No claims have been submitted yet." /> : <ul className="divide-y divide-gray-200 px-4 sm:px-6">{claims.map(c => <li key={c.id} className="flex flex-wrap items-start justify-between gap-3 py-3 text-sm">
          <div className="min-w-0"><span className="font-medium text-gray-900">{c.claim_text}</span><p className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs break-words text-gray-500"><StatusBadge status={c.status.toLowerCase()} /> {c.content_type.replaceAll("_", " ")} · {c.feature_key} · Evidence: {c.evidence_ref}</p></div>
          {c.status === "PENDING" && <button className={secondary} disabled={busy} onClick={() => void action(() => api(`/claims/${c.id}/verify`, "POST"), "Claim verified by owner.")}>Verify claim</button>}
        </li>)}</ul>}
      </Card>

      <section className="space-y-4"><div className="flex flex-wrap items-center justify-between gap-3"><h3 className="text-base font-semibold text-gray-900">Campaign poster drafts</h3><button className={secondary} disabled={busy} onClick={() => void refresh()}>Refresh publishing status</button></div>
        {campaigns.length === 0 ? <Card><EmptyState title="No benefit campaigns yet." /></Card> : campaigns.map(c =>
          <article key={c.id} className="grid gap-5 rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-900/5 sm:p-6 lg:grid-cols-[220px_1fr]">
            {c.poster_url ? <Image unoptimized width={1080} height={1350} className="aspect-[4/5] w-full rounded-md border border-gray-200 object-cover" src={posterPath(c.poster_url)} alt={`TenderHub poster for ${c.name}`} /> : <div className="aspect-[4/5] rounded-md bg-gray-100" />}
            <div className="min-w-0 space-y-3">
              <div><h4 className="font-semibold break-words text-gray-900">{c.name}</h4><p className="mt-1 flex flex-wrap items-center gap-2 text-xs text-gray-500">{c.content_type.replaceAll("_", " ")} <StatusBadge status={c.status.toLowerCase()} /> <Badge tone={c.claim_status === "VERIFIED" ? "green" : "yellow"}>Claim {c.claim_status.toLowerCase()}</Badge></p></div>
              <p className="rounded-md bg-gray-50 p-3 text-sm break-words whitespace-pre-wrap text-gray-800">{c.caption}</p>
              <div className="flex flex-wrap gap-2">
                {c.poster_url && <a className={secondary} href={posterPath(c.poster_url)} download={`tenderhub-campaign-${c.id}.jpg`}>Download poster</a>}
                <button className={secondary} onClick={() => void navigator.clipboard.writeText(c.caption).then(() => setNotice("Caption copied.")).catch(() => setError("Could not copy caption."))}>Copy caption</button>
              </div>
              {c.status === "DRAFT" && <div className="flex flex-wrap gap-2">
                <button className={primary} disabled={busy || c.claim_status !== "VERIFIED"} onClick={() => void action(() => api(`/campaigns/${c.id}/approve`, "POST"), JSON.parse(c.publish_channels || "[]").length ? "Poster approved and selected channels queued for publishing." : "Poster approved for internal use.")}>{JSON.parse(c.publish_channels || "[]").length ? "Approve & publish selected channels" : "Approve private poster"}</button>
                <button className={secondary} disabled={busy} onClick={() => void action(() => api(`/campaigns/${c.id}/regenerate`, "POST", { template_id: template, use_gemini: useGemini }), "Poster regenerated with the selected layout.")}>Regenerate with selected layout</button>
                <button className={secondary} disabled={busy} onClick={() => void action(() => api(`/campaigns/${c.id}/reject`, "POST"), "Campaign draft rejected.")}>Reject draft</button>
              </div>}
              <p className="text-xs text-gray-600">Template: {c.template_id} · CTA: {c.cta_label} · Channels: {JSON.parse(c.publish_channels || "[]").join(", ") || "private only"}{c.publish_at ? ` · Scheduled: ${c.publish_at} UTC` : ""}</p>
              {publications.filter(p => p.campaign_id === c.id).map(p => <div key={p.id} className="rounded-md border border-gray-200 p-2 text-xs break-words text-gray-700"><strong>{p.platform}: {p.status}</strong>{p.last_error && <p className="mt-1 text-red-700">{p.last_error}</p>}{p.post_url && <a className="mt-1 block text-primary-600 underline hover:text-primary-500" href={p.post_url} target="_blank" rel="noopener noreferrer">View published post</a>}{p.external_post_id && <p>Post ID: {p.external_post_id}</p>}</div>)}
              {c.status === "APPROVED" && <div className="space-y-2"><p className="text-xs font-medium text-green-700">Approved artwork is locked.</p>
                {channels.length > 0 && <button className={secondary} disabled={busy || channels.every(channel => publications.some(p => p.campaign_id === c.id && p.platform === channel))} onClick={() => void action(() => api(`/campaigns/${c.id}/publish`, "POST", { publish_channels: channels, publish_at: publishAt ? new Date(publishAt).toISOString() : null }), "Selected channels queued. Existing publications were not duplicated.")}>Publish approved poster to selected channels</button>}
                <p className="text-xs text-gray-500">To add a channel to an approved poster, select it in the form above. A channel already queued or published cannot be queued twice.</p>
              </div>}
            </div>
          </article>
        )}
      </section>
    </>}
  </div>;
}
