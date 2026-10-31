import { NextRequest, NextResponse } from "next/server";
import { apiBase, apiUnavailable } from "@/lib/api";
import { AT } from "@/lib/session";
import { verifyAccessToken } from "@/lib/jwt";
import { clientIp } from "@/lib/rate-limit";
import { rejectCrossOriginMutation } from "@/lib/request-origin";

/**
 * BFF proxy — the company workspace, the bidder portal and the staff console.
 *
 * It forwards the CALLER'S OWN token so CodeIgniter decides authorisation.
 * Being on our own server is not authorisation. This comment is here because
 * it is the mistake this pattern invites, and the mistake that was made: the
 * previous version answered upstream failures from `workspace-mutations.ts`,
 * which ran privileged SQL with a hardcoded actor of user 1 and no role check
 * at all. Confirming a payment, verifying an organisation and countersigning a
 * bid opening were all reachable with any non-empty cookie value.
 *
 * Two things now stand in the way:
 *   1. The token is VERIFIED here before anything is forwarded. Presence of a
 *      cookie is not a credential.
 *   2. There is no fallback. An unreachable API produces 502/503.
 *
 * ONE DETAIL THAT MATTERS: the request body is forwarded as BYTES with the
 * caller's own Content-Type. Re-reading it as text and re-labelling it JSON
 * corrupts a multipart upload, because the boundary lives in that header — and
 * document upload goes through this proxy. Learned the hard way.
 */

export const dynamic = "force-dynamic";

function unauthenticated() {
  return NextResponse.json(
    { status: 401, reason: "unauthenticated", detail: "Sign in to continue." },
    {
      status: 401,
      headers: { "Content-Type": "application/problem+json", "Cache-Control": "private, no-store" },
    },
  );
}

/**
 * First path segments this proxy is allowed to reach on the API. Without it the
 * proxy forwarded ANY `/api/v1/*` path with the caller's token — including
 * `/api/v1/auth/login` and `/api/v1/auth/refresh`, which hand back raw
 * access/refresh tokens in the JSON body (bypassing the httpOnly-cookie model
 * and the auth proxy's brute-force rate limiting), and `partner`/`ingest`
 * routes. These are the only groups any authenticated portal call legitimately
 * uses (the staff console reaches `admin` through here and via the admin proxy).
 */
const ALLOWED_PREFIXES = new Set(["me", "authority", "account", "payments", "admin"]);

function notFound() {
  return NextResponse.json(
    { status: 404, reason: "not_found", detail: "Not found." },
    { status: 404, headers: { "Content-Type": "application/problem+json", "Cache-Control": "private, no-store" } },
  );
}

export async function forward(req: NextRequest, path: string[], method: string) {
  const crossOrigin = rejectCrossOriginMutation(req);
  if (crossOrigin) return crossOrigin;
  const requestId = crypto.randomUUID();
  const startedAt = performance.now();
  const token = req.cookies.get(AT)?.value;
  if (!token) return unauthenticated();

  // Reject anything outside the allow-list before touching the token or the
  // upstream — a generic 404 that does not reveal what the proxy can reach.
  if (path.length === 0 || !ALLOWED_PREFIXES.has(path[0])) {
    return notFound();
  }

  // Verify signature and expiry locally before spending an upstream round trip
  // on a token we can already tell is invalid. CodeIgniter re-verifies it and
  // applies the real filter chain — this is a cheap gate, not the gate.
  const claims = await verifyAccessToken(token);
  if (!claims) return unauthenticated();
  if (path[0] === "admin" && claims.grp !== "staff") {
    return NextResponse.json(
      { status: 403, reason: "forbidden", detail: "Staff access is required." },
      { status: 403, headers: { "Cache-Control": "private, no-store" } },
    );
  }

  // Reject path traversal in the proxied segments before they reach the API.
  if (path.some((p) => p === ".." || p === "." || /[\\/\0?#]/.test(p))) {
    return NextResponse.json(
      { status: 400, reason: "bad_path", detail: "Malformed request path." },
      { status: 400, headers: { "Content-Type": "application/problem+json", "Cache-Control": "private, no-store" } },
    );
  }

  const headers = new Headers();
  headers.set("Authorization", `Bearer ${token}`);
  headers.set("Accept", "application/json");
  headers.set("X-Forwarded-For", clientIp(req));
  headers.set("X-Request-ID", requestId);

  const ct = req.headers.get("content-type");
  if (ct) headers.set("Content-Type", ct);

  const isIngestion = path[0] === "admin" && (path.includes("ingest") || path.includes("collect") || path.includes("run"));
  // WorkSuite answers slowly (measured 9-15 s per call); a sync may make two calls.
  const isWorkSuite = path[0] === "account" && path[1] === "integrations" && path[2] === "worksuite";
  const timeoutMs = isIngestion ? 55_000 : isWorkSuite ? 60_000 : 30_000;
  const init: RequestInit = { method, headers, cache: "no-store", signal: AbortSignal.timeout(timeoutMs) };

  // apiBase() resolution is INSIDE the try: in production with API_BASE unset
  // it throws by design, and that must surface as a clean fail-closed 503 —
  // not an uncaught 500 — exactly as an unreachable upstream does.
  let upstream: Response;
  let buf: ArrayBuffer;
  try {
    if (method !== "GET" && method !== "HEAD") {
      init.body = Buffer.from(await req.arrayBuffer()); // bytes, not text
    }
    const url = `${apiBase()}/api/v1/${path.map(encodeURIComponent).join("/")}${req.nextUrl.search}`;
    upstream = await fetch(url, init);
    // A disconnect after headers or a body timeout must also return 503.
    buf = await upstream.arrayBuffer();
  } catch (e) {
    console.error(JSON.stringify({ event: "bff_upstream_error", request_id: requestId, method, path: path.join("/"), error: (e as Error)?.message }));
    const unavailable = apiUnavailable("api_unavailable", "The service is temporarily unavailable. Please try again shortly.");
    unavailable.headers.set("X-Request-ID", requestId);
    return unavailable;
  }

  const durationMs = Math.round(performance.now() - startedAt);
  if (upstream.status >= 500 || durationMs >= 1500) {
    console.info(JSON.stringify({ event: "bff_request", request_id: requestId, method, path: path.join("/"), status: upstream.status, duration_ms: durationMs }));
  }

  return new NextResponse([204, 205, 304].includes(upstream.status) || method === "HEAD" ? null : buf, {
    status: upstream.status,
    headers: {
      "Content-Type": upstream.headers.get("content-type") ?? "application/json",
      // Authenticated data is never publicly cacheable, and varies by session.
      "Cache-Control": "private, no-store",
      Vary: "Cookie",
      "X-Request-ID": requestId,
      ...(upstream.headers.get("content-disposition") ? { "Content-Disposition": upstream.headers.get("content-disposition")! } : {}),
      ...(upstream.headers.get("retry-after") ? { "Retry-After": upstream.headers.get("retry-after")! } : {}),
    },
  });
}

export async function GET(req: NextRequest, ctx: { params: Promise<{ path: string[] }> }) {
  return forward(req, (await ctx.params).path, "GET");
}
export async function POST(req: NextRequest, ctx: { params: Promise<{ path: string[] }> }) {
  return forward(req, (await ctx.params).path, "POST");
}
export async function PUT(req: NextRequest, ctx: { params: Promise<{ path: string[] }> }) {
  return forward(req, (await ctx.params).path, "PUT");
}
export async function PATCH(req: NextRequest, ctx: { params: Promise<{ path: string[] }> }) {
  return forward(req, (await ctx.params).path, "PATCH");
}
export async function DELETE(req: NextRequest, ctx: { params: Promise<{ path: string[] }> }) {
  return forward(req, (await ctx.params).path, "DELETE");
}
