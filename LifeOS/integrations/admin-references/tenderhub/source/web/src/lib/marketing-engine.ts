/**
 * Server-side calls to the internal marketing engine (never reachable from the
 * browser). Authenticated with the shared service key; `actor` is the verified
 * staff user id the engine records against every change.
 */
export const MARKETING_ENGINE_URL = process.env.MARKETING_ENGINE_URL || "http://127.0.0.1:4000";

export async function engineFetch(path: string, init: { method?: string; body?: unknown; actor?: string } = {}): Promise<Response> {
  const key = process.env.MARKETING_API_KEY;
  if (!key) throw new Error("Marketing service authentication is not configured.");
  return fetch(`${MARKETING_ENGINE_URL}/api/v1${path}`, {
    method: init.method ?? "GET",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      "x-marketing-key": key,
      ...(init.actor ? { "x-marketing-actor": init.actor } : {}),
    },
    body: init.body === undefined ? undefined : JSON.stringify(init.body),
    cache: "no-store",
    signal: AbortSignal.timeout(60_000),
  });
}
