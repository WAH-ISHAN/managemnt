"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { Alert, Badge, Card, CardBody } from "@/components/console/ui";
import { Button, ConfirmDialog } from "@/components/console/interactive";

/**
 * Public tender publishing switch. The API enforces it on every publish path
 * (collection, sync, import, staff publish, authority publish); this panel
 * only shows and changes the stored setting.
 */
export type PublishingState = {
  enabled: boolean;
  held_count: number;
  unverified_count: number;
  public_count: number;
  setting: { updated_by: number | null; updated_at: string | null };
};

export function PublishingControl({ initial }: { initial: PublishingState }) {
  const router = useRouter();
  const [state, setState] = useState(initial);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ text: string; ok: boolean } | null>(null);
  const [confirming, setConfirming] = useState<"off" | "release" | null>(null);

  async function call(path: string, body?: object) {
    setBusy(true);
    setMessage(null);
    try {
      const res = await fetch(path, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: body ? JSON.stringify(body) : undefined,
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(json.detail || "The request failed.");
      setState(json.data);
      return json.data;
    } catch (error) {
      setMessage({ text: error instanceof Error ? error.message : "The request failed.", ok: false });
      return null;
    } finally {
      setBusy(false);
    }
  }

  function toggle() {
    if (state.enabled) setConfirming("off");
    else void applyToggle();
  }

  async function applyToggle() {
    setConfirming(null);
    const next = !state.enabled;
    const data = await call("/api/admin/admin/publishing", { enabled: next });
    if (data) {
      setMessage({ text: next ? "Public publishing is ON." : "Public publishing is OFF. New tenders are held internally.", ok: true });
      router.refresh();
    }
  }

  async function release() {
    setConfirming(null);
    const data = await call("/api/admin/admin/publishing/release-held");
    if (data) {
      setMessage({ text: `${data.released} held tender(s) published.`, ok: true });
      router.refresh();
    }
  }

  return (
    <Card>
      <CardBody>
        <section aria-labelledby="publishing-heading">
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div className="max-w-2xl min-w-0">
              <h2 id="publishing-heading" className="flex flex-wrap items-center gap-2 text-base font-semibold text-gray-900">
                Public tender publishing
                <Badge tone={state.enabled ? "green" : "yellow"} dot>{state.enabled ? "ON" : "OFF"}</Badge>
              </h2>
              <p className="mt-1 text-sm text-gray-500">
                {state.enabled
                  ? "New tenders become public as they are collected or published by staff and buyers."
                  : "New tenders are collected and held internally (visible here to staff only). Every publish action is refused. Tenders that were already public stay public; nothing is deleted."}
              </p>
              <p className="mt-2 text-sm text-gray-500">
                Public now: <strong className="text-gray-900">{state.public_count}</strong> · Held: <strong className="text-gray-900">{state.held_count}</strong> · Awaiting review: <strong className="text-gray-900">{state.unverified_count}</strong>
                {state.setting?.updated_at ? ` · Last changed ${state.setting.updated_at} UTC by staff #${state.setting.updated_by}` : ""}
              </p>
            </div>
            <div className="flex flex-wrap gap-2">
              <Button variant={state.enabled ? "secondary" : "primary"} onClick={toggle} disabled={busy} aria-pressed={state.enabled}>
                {state.enabled ? "Switch publishing OFF" : "Switch publishing ON"}
              </Button>
              {state.enabled && state.held_count > 0 && (
                <Button onClick={() => setConfirming("release")} disabled={busy}>
                  Publish {state.held_count} held tender(s)
                </Button>
              )}
            </div>
          </div>
          {message && (
            <div className="mt-4"><Alert tone={message.ok ? "success" : "error"} title={message.text} /></div>
          )}
        </section>
      </CardBody>
      <ConfirmDialog
        open={confirming === "off"}
        onClose={() => setConfirming(null)}
        onConfirm={() => void applyToggle()}
        busy={busy}
        title="Switch public tender publishing OFF?"
        message="New tenders will be collected but held internally, and nobody can publish until it is switched on again. Tenders already public stay public."
        confirmLabel="Switch OFF"
      />
      <ConfirmDialog
        open={confirming === "release"}
        onClose={() => setConfirming(null)}
        onConfirm={() => void release()}
        busy={busy}
        destructive={false}
        title={`Publish all ${state.held_count} held tender(s) now?`}
        message="They become public and are included in the next 06:00 alert email."
        confirmLabel="Publish now"
      />
    </Card>
  );
}
