/**
 * Fixed-window rate limiting for the BFF auth path.
 *
 * HONEST LIMITATION: this store is in-process. It is correct for a single
 * Next.js instance (the docker-compose topology) and degrades on horizontally
 * scaled or serverless deployments, where each instance keeps its own counters
 * and the effective limit multiplies by instance count.
 *
 * It is deliberately not "good enough forever" — it is the floor. Before
 * scaling past one web instance, back `hit()` with Redis so counters are
 * shared. The interface is kept narrow so that swap is a one-file change.
 * The API's own Throttle filter has the same limitation (it uses CodeIgniter's
 * file cache) and needs the same treatment.
 */

interface Bucket {
  count: number;
  resetAt: number;
}

const buckets = new Map<string, Bucket>();

/** Bounded so a flood of distinct keys cannot grow the map without limit. */
const MAX_KEYS = 10_000;

function sweep(now: number) {
  if (buckets.size < MAX_KEYS) return;
  for (const [k, b] of buckets) {
    if (b.resetAt <= now) buckets.delete(k);
  }
  // Still full of live entries: drop the oldest-resetting half rather than
  // refusing to track anything new.
  if (buckets.size >= MAX_KEYS) {
    const sorted = [...buckets.entries()].sort((a, b) => a[1].resetAt - b[1].resetAt);
    for (let i = 0; i < Math.floor(sorted.length / 2); i++) buckets.delete(sorted[i][0]);
  }
}

export interface RateLimitResult {
  ok: boolean;
  remaining: number;
  retryAfterSec: number;
}

/**
 * @param key      identity of the caller for this limit (IP, or IP + e-mail)
 * @param limit    requests permitted per window
 * @param windowMs window length
 */
export function hit(key: string, limit: number, windowMs: number, now = Date.now()): RateLimitResult {
  sweep(now);

  const b = buckets.get(key);
  if (!b || b.resetAt <= now) {
    buckets.set(key, { count: 1, resetAt: now + windowMs });
    return { ok: true, remaining: limit - 1, retryAfterSec: 0 };
  }

  b.count += 1;
  if (b.count > limit) {
    return { ok: false, remaining: 0, retryAfterSec: Math.ceil((b.resetAt - now) / 1000) };
  }
  return { ok: true, remaining: limit - b.count, retryAfterSec: 0 };
}

/**
 * Best-effort client address.
 *
 * Prefers `X-Real-IP` — the edge sets it to `$remote_addr`, so a client cannot
 * append to it — over the FIRST entry of `X-Forwarded-For`, which a client can
 * seed (nginx uses `$proxy_add_x_forwarded_for`, appending to whatever arrived).
 * The old order trusted that spoofable first entry, so an attacker rotating
 * `X-Forwarded-For` got a fresh per-IP bucket every request and slipped the
 * login/register limits. The per-account (e-mail) limit is the other, unspoofable
 * gate; this restores the per-IP one.
 */
export function clientIp(req: Request): string {
  const h = req.headers;
  const real = h.get("x-real-ip");
  if (real && real.trim() !== "") return real.trim();
  const fwd = h.get("x-forwarded-for");
  if (fwd && fwd.trim() !== "") return fwd.split(",")[0].trim();
  return "unknown";
}

/** Exposed for tests so a suite can start from a known state. */
export function __resetAll() {
  buckets.clear();
}
