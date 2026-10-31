"use client";

import { useState } from "react";

type Maintenance = {
  available?: boolean;
  reason?: string;
  status?: {
    checked_at?: string;
    last_outcome?: string;
    last_detail?: string;
    disk_before_percent?: number;
    disk_after_percent?: number;
    safe_actions?: string[];
    blocked_actions?: string[];
  };
};

export function InfrastructureMaintenance({ initial }: { initial: Maintenance | null }) {
  const [data, setData] = useState(initial);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const status = data?.status;

  async function refresh() {
    const response = await fetch("/api/admin/infrastructure/maintenance", { cache: "no-store" });
    const body = await response.json();
    if (!response.ok) throw new Error(body.detail || "Unable to load maintenance status.");
    setData(body.data);
  }

  async function cleanup() {
    if (!window.confirm("Queue safe Docker cleanup? It removes dangling images and build cache older than 14 days only.")) return;
    setBusy(true); setMessage(null);
    try {
      const response = await fetch("/api/admin/infrastructure/maintenance/safe-cleanup", { method: "POST" });
      const body = await response.json();
      if (!response.ok) throw new Error(body.detail || "Could not queue Docker cleanup.");
      setMessage(body.data?.message || "Safe cleanup queued.");
      window.setTimeout(() => refresh().catch(() => undefined), 65_000);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Could not queue Docker cleanup.");
    } finally {
      setBusy(false);
    }
  }

  return <section className="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
    <div className="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h2 className="font-semibold text-gray-900">Safe Docker maintenance</h2>
        <p className="mt-1 text-sm text-gray-600">Only dangling image layers and BuildKit cache older than 14 days can be removed. Volumes, databases, uploads, backups, containers and logs are blocked.</p>
      </div>
      <button type="button" disabled={busy || !data?.available} onClick={cleanup} className="rounded-md bg-primary-700 px-3 py-2 text-sm font-medium text-white hover:bg-primary-800 disabled:cursor-not-allowed disabled:opacity-50">
        {busy ? "Queueing…" : "Queue safe cleanup"}
      </button>
    </div>
    {status ? <p className="mt-4 text-sm text-gray-700">Last host result: <strong>{status.last_outcome ?? "unknown"}</strong>{status.last_detail ? ` — ${status.last_detail}` : ""}</p> : <p className="mt-4 text-sm text-amber-800">{data?.reason ?? "The host maintenance engine has not reported yet."}</p>}
    {message ? <p role="status" className="mt-3 text-sm text-gray-700">{message}</p> : null}
    <button type="button" onClick={() => refresh().catch((error) => setMessage(error instanceof Error ? error.message : "Could not refresh."))} className="mt-3 text-sm font-medium text-primary-700 hover:text-primary-800">Refresh maintenance status</button>
  </section>;
}
