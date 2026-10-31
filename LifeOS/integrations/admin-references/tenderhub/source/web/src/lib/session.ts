import { cookies } from "next/headers";
import type { Session } from "./types";
import { verifyAccessToken, type AccessClaims } from "./jwt";

export const AT = "th_at";
export const RT = "th_rt";
/** Display-only profile cookie. HMAC-signed, and never a source of authority. */
export const PROF = "th_prof";
export const VIEWS = "th_views";

/**
 * Identity comes from the signed access token and nowhere else.
 *
 * The previous implementation stored the whole session as unsigned base64 JSON
 * and trusted it. That let anyone pick their own role, group, organisation and
 * plan by writing a cookie — no login required. Every security-relevant field
 * below is now read from verified JWT claims; the profile cookie contributes
 * only labels (e-mail, organisation name, renewal date), and if it is missing,
 * stale or tampered with, the session still resolves correctly from the token.
 */

interface ProfileExtras {
  email?: string;
  org_name?: string;
  org_type?: string;
  renews_at?: string | null;
  verify_state?: string;
  free_views_used?: number;
  email_verified?: boolean;
}

// ---------------------------------------------------------------------------
// Signed profile cookie
// ---------------------------------------------------------------------------

function profileSecret(): string {
  const s = process.env.SESSION_SECRET ?? process.env.AUTH_JWT_SECRET ?? "";
  if (s.length < 32) {
    throw new Error("SESSION_SECRET (or AUTH_JWT_SECRET) is missing or shorter than 32 characters.");
  }
  return s;
}

function b64u(bytes: Uint8Array): string {
  let bin = "";
  for (const b of bytes) bin += String.fromCharCode(b);
  return btoa(bin).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
}

function b64uToBytes(s: string): Uint8Array {
  const b64 = s.replace(/-/g, "+").replace(/_/g, "/") + "=".repeat((4 - (s.length % 4)) % 4);
  const bin = atob(b64);
  const out = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
  return out;
}

function timingSafeEqual(a: Uint8Array, b: Uint8Array): boolean {
  if (a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i++) diff |= a[i] ^ b[i];
  return diff === 0;
}

async function hmac(payload: string): Promise<Uint8Array> {
  const key = await crypto.subtle.importKey(
    "raw",
    new TextEncoder().encode(profileSecret()),
    { name: "HMAC", hash: "SHA-256" },
    false,
    ["sign"],
  );
  return new Uint8Array(await crypto.subtle.sign("HMAC", key, new TextEncoder().encode(payload)));
}

/** `<base64url(json)>.<base64url(hmac)>` — bound to the user it describes. */
export async function encodeProfile(userId: number, extras: ProfileExtras): Promise<string> {
  const body = b64u(new TextEncoder().encode(JSON.stringify({ uid: userId, ...extras })));
  return `${body}.${b64u(await hmac(body))}`;
}

async function decodeProfile(raw: string | undefined, userId: number): Promise<ProfileExtras> {
  if (!raw) return {};
  const dot = raw.lastIndexOf(".");
  if (dot <= 0) return {};
  const body = raw.slice(0, dot);
  const sig = raw.slice(dot + 1);

  try {
    if (!timingSafeEqual(await hmac(body), b64uToBytes(sig))) return {};
    const parsed = JSON.parse(new TextDecoder().decode(b64uToBytes(body)));
    // A validly-signed cookie for a *different* user is still not this user's.
    if (parsed?.uid !== userId) return {};
    const { uid: _uid, ...extras } = parsed;
    return extras as ProfileExtras;
  } catch {
    return {};
  }
}

// ---------------------------------------------------------------------------
// Session
// ---------------------------------------------------------------------------

/** Build a Session from verified claims. Labels degrade; authority does not. */
export function sessionFromClaims(c: AccessClaims, extras: ProfileExtras = {}): Session {
  return {
    user: {
      id: c.sub,
      name: c.nm ?? "",
      email: extras.email ?? "",
      role: c.role,
      group: c.grp,
      free_views_used: extras.free_views_used ?? 0,
      email_verified: c.ev ?? extras.email_verified ?? false,
    },
    org: {
      id: c.org,
      name: extras.org_name ?? "",
      type: extras.org_type ?? c.grp,
      plan: c.plan,
      sub_status: c.st,
      renews_at: extras.renews_at ?? null,
      verify_state: extras.verify_state ?? "unverified",
    },
  };
}

/** Null whenever the token is absent, malformed, unsigned, expired or foreign. */
export async function readSession(): Promise<Session | null> {
  const jar = await cookies();
  const claims = await verifyAccessToken(jar.get(AT)?.value ?? "");
  if (!claims) return null;
  return sessionFromClaims(claims, await decodeProfile(jar.get(PROF)?.value, claims.sub));
}

/** Verified claims without the display wrapper — for actor attribution. */
export async function readClaims(): Promise<AccessClaims | null> {
  const jar = await cookies();
  return verifyAccessToken(jar.get(AT)?.value ?? "");
}

const PAID_PLANS = ["business", "publish", "enterprise", "staff"];

export function isPaid(s: Session | null): boolean {
  if (!s) return false;
  return PAID_PLANS.includes(s.org.plan) && s.org.sub_status !== "expired";
}

/** Portal eligibility, derived from the token's group claim. */
export function isStaff(s: Session | null): boolean {
  return s?.user.group === "staff";
}

export function isCompany(s: Session | null): boolean {
  return s?.user.group === "company";
}

export function isBidder(s: Session | null): boolean {
  return s?.user.group === "bidder";
}
