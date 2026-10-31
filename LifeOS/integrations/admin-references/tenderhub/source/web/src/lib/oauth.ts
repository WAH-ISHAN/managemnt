import type { NextRequest } from "next/server";

/** httpOnly cookie holding the per-flow OAuth state nonce. */
export const OAUTH_STATE_COOKIE = "th_oauth_state";

/**
 * The origin Google redirects back to. Production never derives it from the
 * Host / X-Forwarded-Proto headers a client controls; only local development
 * falls back to the request's own host.
 */
export function oauthSiteUrl(req: NextRequest): string {
  const configured = process.env.APP_BASE_URL || process.env.NEXT_PUBLIC_SITE_URL || process.env.SITE_URL;
  if (configured) return configured.replace(/\/+$/, "");
  if (process.env.NODE_ENV === "production") return "https://tenderhub.lk";
  const proto = req.headers.get("x-forwarded-proto");
  return proto ? `${proto}://${req.headers.get("host")}` : "http://localhost:3000";
}
