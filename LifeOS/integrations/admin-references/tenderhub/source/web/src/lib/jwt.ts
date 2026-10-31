/**
 * HS256 verification for the access tokens CodeIgniter issues.
 *
 * This exists so the BFF can decide *render or redirect* without a round trip.
 * It is NOT authorisation. Every request that returns data still forwards the
 * caller's own token to CodeIgniter, which re-verifies it and applies the
 * auth-jwt → tenant → group → entitlement chain. A token that passes here and
 * fails there must still be refused — that is the point of the split.
 *
 * Web Crypto rather than node:crypto: middleware may run on the Edge runtime,
 * where node:crypto is not available. This module has to work in both.
 */

export interface AccessClaims {
  sub: number; // user id
  org: number; // organisation id
  role: string;
  grp: string; // bidder | company | staff
  st: string; // org.sub_status
  plan: string;
  nm: string; // display name
  ev?: boolean; // email verified
  vs?: string; // organisation KYC verify_state
  iss: string;
  iat: number;
  nbf: number;
  exp: number;
}

const ISSUER = "tenderhub.lk";

/** Base64url → bytes, without throwing on the padding variants. */
function b64uToBytes(s: string): Uint8Array {
  const b64 = s.replace(/-/g, "+").replace(/_/g, "/") + "=".repeat((4 - (s.length % 4)) % 4);
  const bin = atob(b64);
  const out = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
  return out;
}

function bytesToUtf8(b: Uint8Array): string {
  return new TextDecoder().decode(b);
}

/** Constant-time comparison. Length is not secret; content is. */
function timingSafeEqual(a: Uint8Array, b: Uint8Array): boolean {
  if (a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i++) diff |= a[i] ^ b[i];
  return diff === 0;
}

let keyPromise: Promise<CryptoKey> | null = null;
let keyForSecret: string | null = null;

function importKey(secret: string): Promise<CryptoKey> {
  if (keyPromise && keyForSecret === secret) return keyPromise;
  keyForSecret = secret;
  keyPromise = crypto.subtle.importKey(
    "raw",
    new TextEncoder().encode(secret),
    { name: "HMAC", hash: "SHA-256" },
    false,
    ["sign"],
  );
  return keyPromise;
}

/**
 * The signing secret, shared with CodeIgniter's `auth.jwtSecret`.
 *
 * Fails loudly rather than falling back to a default. A BFF that verifies
 * tokens against a guessable secret is not verifying anything, and the failure
 * mode is silent — so it has to be an exception, not a warning.
 */
export function jwtSecret(): string {
  const secret = process.env.AUTH_JWT_SECRET ?? "";
  if (secret.length < 32) {
    throw new Error(
      "AUTH_JWT_SECRET is missing or shorter than 32 characters. " +
        "It must match the API's auth.jwtSecret exactly.",
    );
  }
  return secret;
}

/**
 * Verify signature, issuer and time window. Returns null for anything that
 * does not check out — callers must treat null as unauthenticated, never as
 * "carry on without claims".
 *
 * `nowMs` is injectable so tests can assert expiry without sleeping.
 */
export async function verifyAccessToken(
  token: string,
  nowMs: number = Date.now(),
): Promise<AccessClaims | null> {
  if (!token || typeof token !== "string") return null;

  const parts = token.split(".");
  if (parts.length !== 3) return null;
  const [headerB64, payloadB64, sigB64] = parts;

  let header: { alg?: string; typ?: string };
  let claims: Partial<AccessClaims>;
  try {
    header = JSON.parse(bytesToUtf8(b64uToBytes(headerB64)));
    claims = JSON.parse(bytesToUtf8(b64uToBytes(payloadB64)));
  } catch {
    return null;
  }

  // Pin the algorithm. Accepting whatever the token names is how "alg: none"
  // and RS256→HS256 confusion both work.
  if (header.alg !== "HS256") return null;

  let expected: ArrayBuffer;
  try {
    const key = await importKey(jwtSecret());
    expected = await crypto.subtle.sign(
      "HMAC",
      key,
      new TextEncoder().encode(`${headerB64}.${payloadB64}`),
    );
  } catch {
    return null;
  }

  if (!timingSafeEqual(new Uint8Array(expected), b64uToBytes(sigB64))) return null;

  const nowSec = Math.floor(nowMs / 1000);
  if (typeof claims.exp !== "number" || claims.exp <= nowSec) return null;
  if (typeof claims.nbf === "number" && claims.nbf > nowSec + 60) return null;
  if (claims.iss !== ISSUER) return null;

  // Shape check. A token that verifies but carries no subject or org is not a
  // usable identity, and letting it through produces an actor of `undefined`.
  if (typeof claims.sub !== "number" || claims.sub <= 0) return null;
  if (typeof claims.org !== "number" || claims.org <= 0) return null;
  if (typeof claims.grp !== "string" || claims.grp === "") return null;
  if (typeof claims.role !== "string" || claims.role === "") return null;

  return claims as AccessClaims;
}
