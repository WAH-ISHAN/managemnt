"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { Alert, Card, CardBody, CardHeader } from "@/components/console/ui";
import { Button, inputClass } from "@/components/console/interactive";

type Provider = "payhere" | "stripe";
type GatewayStatus = { provider: Provider; configured: boolean; enabled: boolean; mode: string; source: string; updated_at: string | null; refund_configured: boolean };
export type GatewayReport = { can_manage: boolean; payhere: GatewayStatus; stripe: GatewayStatus };

export function PaymentGatewaySettings({ initial }: { initial: GatewayReport }) {
  const router = useRouter();
  const [provider, setProvider] = useState<Provider>("payhere");
  const [report, setReport] = useState(initial);
  const [mode, setMode] = useState<"sandbox" | "live">("sandbox");
  const [merchantId, setMerchantId] = useState("");
  const [secret, setSecret] = useState("");
  const [webhookSecret, setWebhookSecret] = useState("");
  const [appId, setAppId] = useState("");
  const [appSecret, setAppSecret] = useState("");
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ text: string; error: boolean } | null>(null);
  const status = report[provider];

  function choose(next: Provider) {
    setProvider(next);
    setMode(report[next].mode === "live" ? "live" : "sandbox");
    setMerchantId(""); setSecret(""); setWebhookSecret(""); setAppId(""); setAppSecret(""); setMessage(null);
  }

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true); setMessage(null);
    try {
      const response = await fetch(`/api/admin/payments/gateways/${provider}`, {
        method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ mode, merchant_id: merchantId, secret, webhook_secret: webhookSecret, app_id: appId, app_secret: appSecret }),
      });
      const body = await response.json().catch(() => ({}));
      if (!response.ok) {
        setMessage({ text: body.detail || "Gateway credentials could not be saved.", error: true });
        return;
      }
      setReport((current) => ({ ...current, [provider]: body.data }));
      setSecret(""); setWebhookSecret(""); setAppSecret("");
      setMessage({ text: `${provider === "payhere" ? "PayHere" : "Stripe"} checkout enabled. Complete a sandbox payment and verify its webhook before using live mode.`, error: false });
      router.refresh();
    } catch {
      setMessage({ text: "Could not reach the payment service. Check gateway status before retrying.", error: true });
    } finally {
      setBusy(false);
    }
  }

  async function disable() {
    setBusy(true); setMessage(null);
    try {
      const response = await fetch(`/api/admin/payments/gateways/${provider}/disable`, { method: "POST" });
      const body = await response.json().catch(() => ({}));
      if (!response.ok) {
        setMessage({ text: body.detail || "Gateway could not be disabled.", error: true });
        return;
      }
      setReport((current) => ({ ...current, [provider]: body.data }));
      setMessage({ text: "New card checkouts are disabled. Existing payment notifications can still be verified.", error: false });
      router.refresh();
    } catch {
      setMessage({ text: "Could not reach the payment service. Check gateway status before retrying.", error: true });
    } finally {
      setBusy(false);
    }
  }

  return <Card className="mb-6">
    <CardHeader title="Online payment gateways" description="Configure credentials on the API server. The secrets are encrypted and never returned to this page." />
    <CardBody>
      <div className="mb-5 flex flex-wrap gap-2" role="group" aria-label="Payment provider">
        {(["payhere", "stripe"] as const).map((item) => <button key={item} type="button" onClick={() => choose(item)}
          aria-pressed={provider === item}
          className={`rounded-md border px-4 py-2 text-sm font-medium ${provider === item ? "border-primary-600 bg-primary-50 text-primary-800" : "border-gray-200 text-gray-700 hover:bg-gray-50"}`}>
          {item === "payhere" ? "PayHere" : "Stripe"} · {report[item].enabled ? "Enabled" : "Disabled"}
        </button>)}
      </div>
      <div className="mb-5 text-sm text-gray-700" role="status">
        <p><span className="font-semibold">{provider === "payhere" ? "PayHere" : "Stripe"}:</span> {status.enabled ? "Online checkout enabled" : status.configured ? "Configured, checkout disabled" : "No credentials saved"}</p>
        {status.configured && <p className="mt-1 text-gray-500">{status.mode} mode · {status.source === "server" ? "Server environment" : "Admin configuration"}{status.updated_at ? ` · Updated ${status.updated_at}` : ""}</p>}
        {status.configured && <p className="mt-1 text-gray-500">Refund API: {status.refund_configured ? "configured" : "not configured"}</p>}
      </div>
      {message && <div className="mb-5"><Alert tone={message.error ? "error" : "info"} title={message.error ? "Gateway update failed" : "Gateway updated"}>{message.text}</Alert></div>}
      {report.can_manage ? <form onSubmit={submit} className="max-w-xl space-y-4">
        <label className="block text-sm font-medium text-gray-900">Environment
          <select value={mode} onChange={(event) => setMode(event.target.value as "sandbox" | "live")} className={`mt-1 ${inputClass}`}>
            <option value="sandbox">Sandbox (test payments)</option><option value="live">Live (real payments)</option>
          </select>
        </label>
        {provider === "payhere" && <label className="block text-sm font-medium text-gray-900">Merchant ID
          <input required inputMode="numeric" autoComplete="off" value={merchantId} onChange={(event) => setMerchantId(event.target.value)} className={`mt-1 ${inputClass}`} />
        </label>}
        <label className="block text-sm font-medium text-gray-900">{provider === "payhere" ? "Merchant Secret" : "Stripe secret key"}
          <input required type="password" autoComplete="new-password" value={secret} onChange={(event) => setSecret(event.target.value)} className={`mt-1 ${inputClass}`} />
        </label>
        {provider === "stripe" && <label className="block text-sm font-medium text-gray-900">Webhook signing secret
          <input required type="password" autoComplete="new-password" value={webhookSecret} onChange={(event) => setWebhookSecret(event.target.value)} className={`mt-1 ${inputClass}`} />
        </label>}
        {provider === "payhere" && <div className="grid gap-4 sm:grid-cols-2">
          <label className="block text-sm font-medium text-gray-900">Merchant API App ID (refunds, optional)
            <input autoComplete="off" value={appId} onChange={(event) => setAppId(event.target.value)} className={`mt-1 ${inputClass}`} />
          </label>
          <label className="block text-sm font-medium text-gray-900">Merchant API App Secret (refunds, optional)
            <input type="password" autoComplete="new-password" value={appSecret} onChange={(event) => setAppSecret(event.target.value)} className={`mt-1 ${inputClass}`} />
          </label>
        </div>}
        <p className="text-xs leading-5 text-gray-600">{provider === "payhere"
          ? "Find Merchant ID and the tenderhub.lk Merchant Secret in PayHere → Integrations. PayHere must approve the domain before live payments work."
          : "Use a Stripe secret key and the signing secret for the tenderhub.lk webhook endpoint. Both must belong to the selected mode."}</p>
        <div className="flex flex-wrap gap-3"><Button type="submit" disabled={busy}>{busy ? "Saving…" : "Save credentials and enable"}</Button>
          {status.enabled && <Button type="button" variant="secondary" disabled={busy} onClick={disable}>Disable checkout</Button>}
        </div>
      </form> : <p className="text-sm text-gray-600">An active administrator can manage gateway credentials.</p>}
    </CardBody>
  </Card>;
}
