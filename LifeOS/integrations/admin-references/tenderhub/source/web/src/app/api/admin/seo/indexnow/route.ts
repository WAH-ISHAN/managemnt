import { NextRequest, NextResponse } from "next/server";
import { timingSafeEqual } from "node:crypto";
import { requireLiveAdministrator } from "@/lib/admin-access";
import { rejectCrossOriginMutation } from "@/lib/request-origin";
import { apiFetch } from "@/lib/api";
import { submitToIndexNow, getIndexNowKey, getIndexNowHost } from "@/lib/indexnow";
import { isIndexableNotice } from "@/lib/seo";
import type { Notice } from "@/lib/types";

/**
 * Authorize either an active staff session or a secure API key header (e.g. from background cron/worker).
 */
async function authorize(req: NextRequest): Promise<NextResponse | null> {
  const keyHeader = req.headers.get("x-seo-key");
  const expectedKey = process.env.INGEST_SECRET_KEY || process.env.SEO_AUDIT_KEY;
  if (expectedKey && keyHeader && Buffer.byteLength(keyHeader) === Buffer.byteLength(expectedKey)
      && timingSafeEqual(Buffer.from(keyHeader), Buffer.from(expectedKey))) {
    return null;
  }
  const crossOrigin = rejectCrossOriginMutation(req);
  if (crossOrigin) return crossOrigin;
  return (await requireLiveAdministrator()).response;
}

export async function GET(req: NextRequest) {
  const denied = await authorize(req);
  if (denied) return denied;

  const key = getIndexNowKey();
  const host = getIndexNowHost();

  return NextResponse.json({
    success: true,
    data: {
      host,
      key,
      keyLocation: `https://${host}/${key}.txt`,
      endpoints: ["https://api.indexnow.org/indexnow", "https://www.bing.com/indexnow"],
      description: "IndexNow allows instant search indexing for Bing, Yandex, and participating search engines.",
    },
  });
}

export async function POST(req: NextRequest) {
  const denied = await authorize(req);
  if (denied) return denied;

  let body: { urls?: string[]; submitRecent?: boolean; limit?: number } = {};
  try {
    body = await req.json();
  } catch {
    body = {};
  }

  const host = getIndexNowHost();
  let urlsToSubmit: string[] = Array.isArray(body.urls) ? body.urls : [];

  // If submitRecent requested, discover the latest published notices from the API
  if (body.submitRecent || urlsToSubmit.length === 0) {
    const limit = Math.min(Math.max(1, Number(body.limit) || 20), 100);
    try {
      const res = await apiFetch<Notice[]>(`/api/v1/notices?kind=tender&limit=${limit}&sort=newest`);
      if (res.ok && Array.isArray(res.body?.data)) {
        const discovered = (res.body.data as Notice[])
          .filter(isIndexableNotice)
          .map((n) => `https://${host}/tenders/${encodeURIComponent(n.slug)}`);
        urlsToSubmit = Array.from(new Set([...urlsToSubmit, ...discovered]));
      }
    } catch {
      // Best effort
    }
  }

  if (urlsToSubmit.length === 0) {
    return NextResponse.json({
      success: false,
      error: "No URLs provided and no recent notices available to submit.",
    }, { status: 400 });
  }

  const result = await submitToIndexNow(urlsToSubmit, { host });
  const httpStatus = result.success ? 200 : (result.statusCode && result.statusCode >= 400 ? result.statusCode : 502);

  return NextResponse.json({
    success: result.success,
    data: result,
  }, { status: httpStatus });
}
