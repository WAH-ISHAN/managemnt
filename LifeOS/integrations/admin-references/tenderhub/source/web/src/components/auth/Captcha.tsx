"use client";

import { useEffect, useRef, useState } from "react";

type TurnstileApi = {
  render: (element: HTMLElement, options: Record<string, unknown>) => string;
  reset: (id: string) => void;
  remove: (id: string) => void;
};
type TurnstileConfig = { enabled: boolean; site_key: string | null };
declare global {
  interface Window {
    turnstile?: TurnstileApi;
    tenderhubTurnstileReady?: () => void;
  }
}

let scriptReady: Promise<void> | undefined;
let configReady: Promise<TurnstileConfig> | undefined;
let cachedConfig: { value: TurnstileConfig; expiresAt: number } | undefined;

function getTurnstileConfig(): Promise<TurnstileConfig> {
  if (cachedConfig && cachedConfig.expiresAt > Date.now()) return Promise.resolve(cachedConfig.value);
  if (!configReady) {
    configReady = fetch("/api/v1/auth/captcha", {
      cache: "no-store",
      signal: AbortSignal.timeout(6000),
    }).then(async (response) => {
      if (!response.ok) throw new Error("Security configuration is temporarily unavailable.");
      const envelope = await response.json();
      const data = envelope?.data;
      if (!data || typeof data.enabled !== "boolean") throw new Error("Security configuration is invalid.");
      if (data.enabled && (typeof data.site_key !== "string" || data.site_key.length === 0)) {
        throw new Error("Security verification is not configured.");
      }
      const value: TurnstileConfig = { enabled: data.enabled, site_key: data.site_key ?? null };
      cachedConfig = { value, expiresAt: Date.now() + 60_000 };
      return value;
    }).finally(() => {
      configReady = undefined;
    });
  }
  return configReady;
}

function loadTurnstile() {
  if (window.turnstile) return Promise.resolve();
  if (!scriptReady) {
    scriptReady = new Promise<void>((resolve, reject) => {
      const script = document.createElement("script");
      script.dataset.tenderhubTurnstile = "true";
      const finish = (error?: Error) => {
        window.clearTimeout(timer);
        window.tenderhubTurnstileReady = undefined;
        if (error) {
          script.remove();
          scriptReady = undefined;
          reject(error);
        } else {
          resolve();
        }
      };
      const timer = window.setTimeout(() => finish(new Error("Security script timed out.")), 10_000);
      window.tenderhubTurnstileReady = () => {
        finish();
      };
      script.src = "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=tenderhubTurnstileReady";
      script.async = true;
      script.onerror = () => finish(new Error("Security script could not load."));
      document.head.appendChild(script);
    }).catch((error) => {
      scriptReady = undefined;
      throw error;
    });
  }
  return scriptReady;
}

export function Captcha({ action }: { action: "login" | "register" | "password-reset" | "contact" | "otp" }) {
  const container = useRef<HTMLDivElement>(null);
  const tokenInput = useRef<HTMLInputElement>(null);
  const statusInput = useRef<HTMLInputElement>(null);
  const widgetRef = useRef<string | undefined>(undefined);
  const [error, setError] = useState("");
  const [retry, setRetry] = useState(0);
  useEffect(() => {
    let disposed = false;
    const element = container.current;
    const form = element?.closest("form");
    const setToken = (token: string) => {
      if (tokenInput.current) tokenInput.current.value = token;
    };
    const reset = () => {
      setToken("");
      if (widgetRef.current !== undefined) window.turnstile?.reset(widgetRef.current);
    };
    form?.addEventListener("captcha-reset", reset);
    if (statusInput.current) statusInput.current.value = "loading";
    (async () => {
      const data = await getTurnstileConfig();
      if (!data.enabled) {
        if (statusInput.current) statusInput.current.value = "disabled";
        return;
      }
      if (statusInput.current) statusInput.current.value = "enabled";
      await loadTurnstile();
      if (disposed || !element) return;
      widgetRef.current = window.turnstile!.render(element, {
        sitekey: data.site_key!,
        action,
        size: element.clientWidth < 300 ? "compact" : "flexible",
        theme: "light",
        callback: (token: string) => {
          setToken(token);
          setError("");
        },
        "error-callback": () => {
          setToken("");
          setError("The security check could not complete. Try again.");
        },
        "expired-callback": reset,
      });
    })().catch(() => {
      if (!disposed) {
        if (statusInput.current) statusInput.current.value = "unavailable";
        setError("Security verification is unavailable. Check your connection and retry.");
      }
    });
    return () => {
      disposed = true;
      form?.removeEventListener("captcha-reset", reset);
      if (widgetRef.current !== undefined) {
        window.turnstile?.remove(widgetRef.current);
        widgetRef.current = undefined;
      }
    };
  }, [action, retry]);

  return (
    <div>
      <div ref={container} />
      <input ref={tokenInput} type="hidden" name="cf-turnstile-response" />
      <input ref={statusInput} type="hidden" name="captcha-widget-status" value="loading" readOnly />
      {error && (
        <div className="mt-2 flex items-center gap-2 text-xs text-red-700">
          <p role="alert">{error}</p>
          <button type="button" className="font-semibold underline" onClick={() => {
            if (widgetRef.current !== undefined) {
              window.turnstile?.remove(widgetRef.current);
              widgetRef.current = undefined;
            }
            setError("");
            setRetry((value) => value + 1);
          }}>Retry</button>
        </div>
      )}
    </div>
  );
}

export async function captchaFetch(form: HTMLFormElement, url: string, options: RequestInit) {
  const token = new FormData(form).get("cf-turnstile-response");
  if (typeof token !== "string" || token === "") {
    form.dispatchEvent(new Event("captcha-reset"));
    try {
      const widgetStatus = new FormData(form).get("captcha-widget-status");
      const config = widgetStatus === "enabled" || widgetStatus === "disabled"
        ? { enabled: widgetStatus === "enabled" }
        : await getTurnstileConfig();
      if (config.enabled) {
        return Response.json(
          { status: 403, reason: "captcha_failed", detail: "Please complete the security check and try again." },
          { status: 403, headers: { "Content-Type": "application/problem+json" } },
        );
      }
    } catch {
      return Response.json(
        { status: 503, reason: "captcha_unavailable", detail: "Security verification is temporarily unavailable. Please try again shortly." },
        { status: 503, headers: { "Content-Type": "application/problem+json" } },
      );
    }
    return fetch(url, options);
  }

  let body: BodyInit;
  const headers = new Headers(options.headers);
  if (options.body instanceof FormData) {
    options.body.set("captcha_token", token);
    headers.delete("Content-Type");
    body = options.body;
  } else {
    const json = JSON.parse(String(options.body));
    json.captcha_token = token;
    body = JSON.stringify(json);
  }

  try {
    return await fetch(url, { ...options, headers, body });
  } finally {
    form.dispatchEvent(new Event("captcha-reset"));
  }
}
