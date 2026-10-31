import { cookies } from "next/headers";
import { NextResponse } from "next/server";
import type { Envelope } from "./types";

/**
 * The authoritative backend.
 *
 * In production this MUST be set explicitly. The old default of
 * `http://127.0.0.1:8080` meant that inside a container the BFF pointed at
 * itself, every call failed, and every failure was answered from a local
 * fabrication layer — which is how the mock became the product. Booting
 * without it configured is now a startup error rather than a silent downgrade.
 */
let _apiBase: string | null = null;

/**
 * Resolved lazily, not at module load. `next build` evaluates route modules to
 * collect page data, and throwing there would break the build even though no
 * request is being served — the check we want is "fail at server startup / on
 * first request in production", not "fail at build". So the build phase gets a
 * harmless placeholder and the throw happens the first time a request actually
 * needs the backend.
 */
export function apiBase(): string {
  if (_apiBase) return _apiBase;

  const configured = process.env.API_BASE;
  if (configured) return (_apiBase = configured.replace(/\/+$/, ""));

  // During `next build`, page-data collection imports these modules; don't fail
  // the build for a value only needed at request time.
  if (process.env.NEXT_PHASE === "phase-production-build") {
    return "http://127.0.0.1:8080";
  }

  if (process.env.NODE_ENV === "production") {
    throw new Error(
      "API_BASE is not set. The BFF has no authoritative backend to forward to. " +
        "Set API_BASE to the internal API origin (e.g. http://api:9000/_api).",
    );
  }
  return (_apiBase = "http://127.0.0.1:8080");
}

/** How long we wait on the API before treating it as unreachable. */
const UPSTREAM_TIMEOUT_MS = Number(process.env.API_TIMEOUT_MS ?? 10_000);

/**
 * RFC 9457 problem response for an unreachable or broken upstream.
 *
 * 502/503 — never 200 with substitute content. A page that cannot reach its
 * data source has to say so; rendering "no results" for "the backend is down"
 * is how staff came to be shown an empty payments queue that had simply never
 * been read.
 */
export function apiUnavailable(reason = "api_unavailable", detail = "The service is temporarily unavailable.") {
  const status = reason === "bad_gateway" ? 502 : 503;
  return NextResponse.json(
    { status, reason, detail },
    {
      status,
      headers: {
        "Content-Type": "application/problem+json",
        "Cache-Control": "private, no-store",
        "Retry-After": "30",
      },
    },
  );
}

export interface ApiResult<T> {
  ok: boolean;
  status: number;
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  body: Envelope<T> | any;
  /** True when the API could not be reached at all, as opposed to refusing. */
  unreachable: boolean;
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
export async function apiFetch<T = any>(
  path: string,
  opts: RequestInit & { token?: string | null; revalidate?: number } = {},
): Promise<ApiResult<T>> {
  const { token, revalidate, ...init } = opts;
  const headers = new Headers(init.headers);
  headers.set("Accept", "application/json");
  if (token) headers.set("Authorization", `Bearer ${token}`);
  if (init.body && !headers.has("Content-Type")) headers.set("Content-Type", "application/json");

  try {
    const res = await fetch(`${apiBase()}${path}`, {
      ...init,
      headers,
      // Anonymous catalogue reads are identical for every visitor and may opt
      // into a short shared cache. Authenticated reads remain request-bound.
      cache: revalidate && !token ? "force-cache" : (init.cache ?? "no-store"),
      ...(revalidate && !token ? { next: { revalidate } } : {}),
      signal: AbortSignal.timeout(UPSTREAM_TIMEOUT_MS),
    });

    const text = await res.text();
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    let body: any;
    try {
      body = JSON.parse(text);
    } catch {
      console.error(`[api] non-JSON response from ${path} (status ${res.status})`);
      return {
        ok: false,
        status: 502,
        unreachable: true,
        body: { status: 502, reason: "bad_gateway", detail: "The service returned an unexpected response." },
      };
    }

    return { ok: res.ok, status: res.status, body, unreachable: false };
  } catch (e) {
    // Unreachable is reported as unreachable. There is no fallback data source
    // and there must not be one: a frontend that answers from its own database
    // when the API is down is a second backend with none of the first one's
    // authorisation.
    console.warn(`[api] unreachable: ${path} —`, (e as Error)?.message);
    return {
      ok: false,
      status: 503,
      unreachable: true,
      body: {
        status: 503,
        reason: "api_unavailable",
        detail: "The service is temporarily unavailable. Please try again shortly.",
      },
    };
  }
}

export async function token(): Promise<string | null> {
  const c = await cookies();
  return c.get("th_at")?.value ?? null;
}

/** Server-side fetch that forwards the caller's own token. */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
export async function authed<T = any>(path: string, opts: RequestInit = {}) {
  return apiFetch<T>(path, { ...opts, token: await token() });
}
