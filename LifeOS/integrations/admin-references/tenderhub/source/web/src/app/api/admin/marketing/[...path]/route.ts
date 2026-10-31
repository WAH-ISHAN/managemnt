import { NextRequest, NextResponse } from "next/server";
import { requireLiveAdministrator } from "@/lib/admin-access";
import { rejectCrossOriginMutation } from "@/lib/request-origin";

const MARKETING_ENGINE_URL = process.env.MARKETING_ENGINE_URL || "http://127.0.0.1:4000";
type RouteContext = { params: Promise<{ path: string[] }> };

async function proxyRequest(req: NextRequest, { params }: { params: Promise<{ path: string[] }> }) {
  const crossOrigin = rejectCrossOriginMutation(req);
  if (crossOrigin) return crossOrigin;
  const access = await requireLiveAdministrator();
  if (access.response) return access.response;
  const session = access.session!;

  const { path } = await params;
  // Each decoded segment must be a plain name; "..", "/", "\\" or "?" would
  // otherwise re-target the request inside the marketing engine.
  if (!Array.isArray(path) || path.length === 0 || path.some((p) => !/^[A-Za-z0-9._-]+$/.test(p) || p === "." || p === "..")) {
    return NextResponse.json({ success: false, error: "Invalid marketing endpoint." }, { status: 400 });
  }
  const pathStr = path.map(encodeURIComponent).join("/");
  const searchParams = req.nextUrl.search;
  const targetUrl = `${MARKETING_ENGINE_URL}/api/v1/${pathStr}${searchParams}`;
  const marketingKey = process.env.MARKETING_API_KEY;
  if (!marketingKey) {
    return NextResponse.json({ success: false, error: "Marketing service authentication is not configured." }, { status: 503 });
  }

  try {
    let body: unknown = null;
    if (req.method !== "GET" && req.method !== "HEAD") {
      try {
        body = await req.json();
      } catch {
        body = null;
      }
    }

    const res = await fetch(targetUrl, {
      method: req.method,
      headers: {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "x-marketing-key": marketingKey,
        "x-marketing-actor": String(session.user.id),
        "x-marketing-role": String(session.user.role),
      },
      body: body ? JSON.stringify(body) : undefined,
      cache: "no-store",
      signal: AbortSignal.timeout(60_000),
    });

    if (res.status === 204) return new NextResponse(null, { status: 204 });
    const data = await res.json().catch(() => null);
    if (!data || typeof data !== "object") {
      return NextResponse.json({ success: false, error: "Marketing engine returned an invalid response." }, { status: 502 });
    }
    return NextResponse.json(data, { status: res.status });
  } catch (err: unknown) {
    console.error("[marketing] engine unreachable:", err instanceof Error ? err.message : err);
    return NextResponse.json({
      success: false,
      error: "Marketing engine is offline or unreachable.",
    }, { status: 503 });
  }
}

export async function GET(req: NextRequest, ctx: RouteContext) {
  return proxyRequest(req, ctx);
}

export async function POST(req: NextRequest, ctx: RouteContext) {
  return proxyRequest(req, ctx);
}

export async function PUT(req: NextRequest, ctx: RouteContext) {
  return proxyRequest(req, ctx);
}

export async function DELETE(req: NextRequest, ctx: RouteContext) {
  return proxyRequest(req, ctx);
}
