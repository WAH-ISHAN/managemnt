import { randomBytes } from "crypto";
import { NextRequest, NextResponse } from "next/server";
import { requireLiveAdministrator } from "@/lib/admin-access";
import { oauthSiteUrl } from "@/lib/oauth";
import { engineFetch } from "@/lib/marketing-engine";

export const dynamic = "force-dynamic";

/** httpOnly cookie binding the OAuth state to this administrator's browser. */
const STATE_COOKIE = "th_social_oauth";
const COOKIE_PATH = "/api/admin/social-oauth";
const PLATFORMS = new Set(["facebook", "instagram", "tiktok"]);
const LABEL: Record<string, string> = { facebook: "Facebook", instagram: "Instagram", tiktok: "TikTok" };

type Ctx = { params: Promise<{ platform: string; action: string }> };

function back(req: NextRequest, params: Record<string, string>) {
  const url = new URL("/console/marketing", oauthSiteUrl(req));
  url.searchParams.set("tab", "connections");
  // Engine errors are sanitized before they reach this route. Keep enough of
  // the diagnostic to identify the Facebook profile/token/Page failure.
  for (const [k, v] of Object.entries(params)) url.searchParams.set(k, v.slice(0, 600));
  const res = NextResponse.redirect(url);
  res.cookies.set(STATE_COOKIE, "", { path: COOKIE_PATH, maxAge: 0 });
  return res;
}

/**
 * Facebook Login / Instagram Login / TikTok Login Kit for the Social Marketing
 * panel. The administrator types their password on the platform's own login
 * page; TenderHub never receives it, only a revocable token.
 *
 *   GET start     -> platform consent screen (state nonce in an httpOnly cookie)
 *   GET callback  -> state checked against the cookie, code exchanged by the
 *                    marketing engine (which holds the app secret and seals the
 *                    tokens), then back to Console -> Social Marketing.
 *
 * Only an owner/admin staff session may connect an account; the session is
 * re-verified on the callback, so a callback link alone connects nothing.
 */
export async function GET(req: NextRequest, { params }: Ctx) {
  const { platform, action } = await params;
  if (!PLATFORMS.has(platform) || !["start", "callback"].includes(action)) {
    return NextResponse.json({ status: 404, reason: "not_found", detail: "Not found." }, { status: 404 });
  }

  // Reject unsolicited callbacks before touching the API. The state cookie is
  // the browser-bound CSRF proof; a missing or mismatched value can never be a
  // valid callback, even when the API is slow or unavailable. Authorisation is
  // still re-verified immediately afterwards for every valid callback.
  const callbackQuery = action === "callback" ? req.nextUrl.searchParams : null;
  let callbackMode = "facebook";
  if (callbackQuery) {
    const expected = req.cookies.get(STATE_COOKIE)?.value ?? "";
    const [cookiePlatform, mode, nonce] = expected.split(".");
    if (!expected || cookiePlatform !== platform || !nonce || nonce !== (callbackQuery.get("state") ?? "")) {
      return back(req, { social_error: "The connection request expired or did not start in this browser. Try again." });
    }
    callbackMode = mode;
  }

  const access = await requireLiveAdministrator();
  if (access.response) return access.response;
  const session = access.session!;
  const redirectUri = `${oauthSiteUrl(req)}/api/admin/social-oauth/${platform}/callback`;
  const actor = String(session.user.id);

  if (action === "start") {
    const mode = platform === "instagram" && req.nextUrl.searchParams.get("mode") === "direct" ? "direct" : "facebook";
    const nonce = randomBytes(24).toString("base64url");
    let url: string | undefined;
    let error = "The marketing engine is unreachable.";
    try {
      const res = await engineFetch(`/social/oauth/${platform}/authorize-url`, { method: "POST", body: { redirect_uri: redirectUri, state: nonce, mode }, actor });
      const json = await res.json().catch(() => ({}));
      url = res.ok ? json.url : undefined;
      error = json.error || error;
    } catch (e) {
      error = (e as Error).message;
    }
    if (!url) return back(req, { social_error: error });
    const res = NextResponse.redirect(url);
    res.cookies.set(STATE_COOKIE, `${platform}.${mode}.${nonce}`, {
      httpOnly: true, sameSite: "lax", path: COOKIE_PATH, maxAge: 600, secure: redirectUri.startsWith("https://"),
    });
    return res;
  }

  const q = callbackQuery!;
  const mode = callbackMode;
  if (q.get("error") || !q.get("code")) {
    // Provider descriptions are untrusted and can contain URLs or credentials.
    const permission = platform === "instagram" ? "Instagram and Page access" : platform === "facebook" ? "Page access" : "the requested permissions";
    return back(req, { social_error: `${LABEL[platform]} login was cancelled or required permissions were declined. Try again and grant ${permission}.` });
  }
  try {
    const res = await engineFetch(`/social/oauth/${platform}/exchange`, { method: "POST", body: { code: q.get("code"), redirect_uri: redirectUri, mode }, actor });
    const json = await res.json().catch(() => ({}));
    if (!res.ok || !json.success) return back(req, { social_error: json.error || `Connection failed (HTTP ${res.status}).` });
  } catch (e) {
    return back(req, { social_error: (e as Error).message });
  }
  return back(req, { social_connected: platform });
}
