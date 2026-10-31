"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { Card, CardBody } from "@/components/console/ui";
import { Button, ConfirmDialog } from "@/components/console/interactive";

export function WorkSuiteAdminSwitch({ enabled, updatedAt }: { enabled: boolean; updatedAt: string | null }) {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [confirmOff, setConfirmOff] = useState(false);

  async function apply() {
    setBusy(true);
    setError(null);
    const res = await fetch("/api/admin/integrations/worksuite", {
      method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ enabled: !enabled }),
    });
    const json = await res.json().catch(() => ({}));
    setBusy(false);
    setConfirmOff(false);
    if (!res.ok) setError(json.detail ?? "Could not change the setting.");
    else router.refresh();
  }

  function toggle() {
    if (enabled) { setConfirmOff(true); return; }
    void apply();
  }

  return (
    <Card>
      <CardBody className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-sm font-semibold text-gray-900">Integration {enabled ? "ON" : "OFF"}</h2>
          <p className="text-sm text-gray-500">{updatedAt ? `Last changed ${updatedAt} UTC.` : "Default setting."}</p>
          {error && <p role="alert" className="mt-1 text-sm text-red-600">{error}</p>}
        </div>
        <Button onClick={toggle} busy={busy} aria-pressed={enabled}>{enabled ? "Switch off" : "Switch on"}</Button>
      </CardBody>
      <ConfirmDialog open={confirmOff} onClose={() => setConfirmOff(false)} onConfirm={() => void apply()} busy={busy}
        title="Switch the WorkSuite integration off?" confirmLabel="Switch off"
        message="Users keep their connections but cannot connect or send tenders until it is switched on." />
    </Card>
  );
}
