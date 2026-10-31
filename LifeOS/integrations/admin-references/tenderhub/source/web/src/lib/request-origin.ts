import { NextRequest, NextResponse } from "next/server";

/** Cookie-authenticated mutations must originate from this application. */
export function rejectCrossOriginMutation(req: NextRequest) {
  if (["GET", "HEAD", "OPTIONS"].includes(req.method)) return null;
  const origin = req.headers.get("origin");
  const site = req.headers.get("sec-fetch-site");
  const configured = process.env.APP_BASE_URL || process.env.NEXT_PUBLIC_SITE_URL || process.env.SITE_URL;
  const allowed = new Set([req.nextUrl.origin]);
  if (configured) {
    try { allowed.add(new URL(configured).origin); } catch { /* invalid config grants no origin */ }
  }
  if (process.env.NODE_ENV === "production") allowed.add("https://tenderhub.lk");
  if (site === "cross-site" || (origin !== null && !allowed.has(origin))) {
    return NextResponse.json({ status: 403, reason: "cross_origin_forbidden", detail: "Cross-origin changes are not allowed." }, {
      status: 403, headers: { "Cache-Control": "private, no-store" },
    });
  }
  return null;
}
