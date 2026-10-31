"use client";

import { useState } from "react";
import { Alert, Card, CardBody, CardHeader } from "@/components/console/ui";

type Config = { enabled: boolean; hour: number; max_urls: number; batch_size: number; delay_ms: number; max_load_ratio: number; max_memory_ratio: number };
type Run = { id: number; run_date: string; status: string; candidate_count: number; submitted_count: number; message: string | null; completed_at: string | null };

export function IndexNowControl({ initialConfig, initialRuns }: { initialConfig: Config; initialRuns: Run[] }) {
  const [config, setConfig] = useState(initialConfig);
  const [runs, setRuns] = useState(initialRuns);
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  async function save() {
    setBusy(true); setMessage(null);
    try {
      const response = await fetch("/api/admin/seo/indexnow", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(config) });
      const body = await response.json();
      if (!response.ok) throw new Error(body.detail || "Could not save IndexNow settings.");
      setConfig(body.data); setMessage("IndexNow automation settings saved.");
    } catch (error) { setMessage(error instanceof Error ? error.message : "Could not save settings."); }
    finally { setBusy(false); }
  }
  async function refresh() {
    const response = await fetch("/api/admin/seo/indexnow", { cache: "no-store" });
    if (!response.ok) return;
    const body = await response.json(); setConfig(body.data.config); setRuns(body.data.recent_runs ?? []);
  }
  return <Card>
    <CardHeader title="IndexNow automation" description="Only changed, published tender URLs are submitted. The worker is capped and pauses itself when CPU or RAM pressure is high." />
    <CardBody>
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <label className="flex items-center gap-2 text-sm font-medium"><input type="checkbox" checked={config.enabled} onChange={e => setConfig({ ...config, enabled: e.target.checked })} /> Enable daily submission</label>
        <label className="text-sm">Colombo hour<input className="mt-1 block w-full rounded border p-2" type="number" min={0} max={23} value={config.hour} onChange={e => setConfig({ ...config, hour: Number(e.target.value) })} /></label>
        <label className="text-sm">Daily URL cap<input className="mt-1 block w-full rounded border p-2" type="number" min={1} max={5000} value={config.max_urls} onChange={e => setConfig({ ...config, max_urls: Number(e.target.value) })} /></label>
        <label className="text-sm">Delay between batches (ms)<input className="mt-1 block w-full rounded border p-2" type="number" min={50} max={5000} value={config.delay_ms} onChange={e => setConfig({ ...config, delay_ms: Number(e.target.value) })} /></label>
      </div>
      <div className="mt-4 flex flex-wrap gap-2"><button type="button" disabled={busy} onClick={save} className="rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{busy ? "Saving…" : "Save settings"}</button><button type="button" onClick={refresh} className="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700">Refresh run history</button></div>
      {message ? <div className="mt-3"><Alert tone={message.includes("saved") ? "success" : "error"} title={message} /></div> : null}
      <div className="mt-6 overflow-x-auto"><table className="w-full text-left text-sm"><thead><tr className="border-b"><th className="p-2">Date</th><th className="p-2">Status</th><th className="p-2">Submitted</th><th className="p-2">Result</th></tr></thead><tbody>{runs.map(run => <tr key={run.id} className="border-b"><td className="p-2">{run.run_date}</td><td className="p-2">{run.status}</td><td className="p-2">{run.submitted_count}/{run.candidate_count}</td><td className="p-2">{run.message ?? "—"}</td></tr>)}{runs.length === 0 ? <tr><td className="p-2" colSpan={4}>No IndexNow runs recorded yet.</td></tr> : null}</tbody></table></div>
    </CardBody>
  </Card>;
}
