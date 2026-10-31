"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { Card, CardBody } from "@/components/console/ui";
import { Button, ConfirmDialog } from "@/components/console/interactive";

/** Enable / disable the Organisation Portal. Both directions are confirmed. */
export function OrganisationPortalSwitch({ enabled, updatedAt, updatedBy, users }: {
  enabled: boolean; updatedAt: string | null; updatedBy: string | null; users: number;
}) {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [confirming, setConfirming] = useState(false);

  async function apply() {
    setBusy(true);
    setError(null);
    const res = await fetch("/api/admin/portals/organisation", {
      method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ enabled: !enabled }),
    });
    const json = await res.json().catch(() => ({}));
    setBusy(false);
    setConfirming(false);
    if (!res.ok) setError(json.detail ?? "Could not change the setting.");
    else router.refresh();
  }

  const changed = updatedAt
    ? `Last changed ${updatedAt} UTC${updatedBy ? ` by ${updatedBy}` : ""}.`
    : "Default setting: disabled until an administrator enables it.";

  return (
    <Card>
      <CardBody className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-sm font-semibold text-gray-900">Organisation Portal {enabled ? "ENABLED" : "DISABLED"}</h2>
          <p className="text-sm text-gray-500">{changed}</p>
          {error && <p role="alert" className="mt-1 text-sm text-red-600">{error}</p>}
        </div>
        <Button onClick={() => setConfirming(true)} busy={busy} aria-pressed={enabled}>{enabled ? "Disable portal" : "Enable portal"}</Button>
      </CardBody>
      <ConfirmDialog
        open={confirming}
        onClose={() => setConfirming(false)}
        onConfirm={() => void apply()}
        busy={busy}
        title={enabled ? "Disable the Organisation Portal?" : "Enable the Organisation Portal?"}
        confirmLabel={enabled ? "Disable portal" : "Enable portal"}
        message={enabled
          ? `${users} active organisation user${users === 1 ? "" : "s"} will see the “temporarily unavailable” page on their next request. No account or data is changed, and you can enable it again at any time.`
          : `${users} active organisation user${users === 1 ? "" : "s"} regain access to the workspace and its features on their next request.`}
      />
    </Card>
  );
}
