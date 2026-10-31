import { isTrackablePublicPath } from "@/components/analytics/google-analytics";

const DEFAULT_INDEXNOW_KEY = "cae4d38360bfa02c0d5d2090847eeb73"; // gitleaks:allow
const DEFAULT_HOST = "tenderhub.lk";
const PRIMARY_INDEXNOW_ENDPOINT = "https://api.indexnow.org/indexnow";
const FALLBACK_INDEXNOW_ENDPOINT = "https://www.bing.com/indexnow";

export interface IndexNowPayload {
  host: string;
  key: string;
  keyLocation: string;
  urlList: string[];
}

export interface IndexNowResult {
  success: boolean;
  submittedCount: number;
  statusCode?: number;
  message: string;
  endpointUsed?: string;
  rejectedUrls?: string[];
  submittedUrls?: string[];
}

/**
 * Returns the configured IndexNow verification key.
 */
export function getIndexNowKey(): string {
  const envKey = process.env.INDEXNOW_KEY?.trim();
  if (envKey && /^[a-zA-Z0-9]{8,128}$/.test(envKey)) {
    return envKey;
  }
  return DEFAULT_INDEXNOW_KEY;
}

/**
 * Returns the canonical website host.
 */
export function getIndexNowHost(): string {
  const baseUrl = process.env.NEXT_PUBLIC_SITE_URL || process.env.APP_BASE_URL || `https://${DEFAULT_HOST}`;
  try {
    return new URL(baseUrl).hostname.toLowerCase();
  } catch {
    return DEFAULT_HOST;
  }
}

/**
 * Sanitizes, validates and deduplicates URLs for IndexNow submission.
 * Enforces strict public boundaries: rejects all private/staff/auth URLs.
 */
export function sanitizeIndexNowUrls(urls: string[], allowedHost?: string): { valid: string[]; rejected: string[] } {
  const host = (allowedHost || getIndexNowHost()).toLowerCase();
  const validSet = new Set<string>();
  const rejected: string[] = [];

  for (const raw of urls) {
    if (!raw || typeof raw !== "string") {
      rejected.push(String(raw));
      continue;
    }

    try {
      const parsed = new URL(raw.trim(), `https://${host}`);
      // Hostname must match exactly
      if (parsed.hostname.toLowerCase() !== host) {
        rejected.push(raw);
        continue;
      }

      // Pathname must be public and indexable
      if (!isTrackablePublicPath(parsed.pathname)) {
        rejected.push(raw);
        continue;
      }

      // Canonical URL without sensitive parameters or hash fragments
      const cleanUrl = `https://${host}${parsed.pathname}`;
      validSet.add(cleanUrl);
    } catch {
      rejected.push(raw);
    }
  }

  return {
    valid: Array.from(validSet),
    rejected,
  };
}

/**
 * Submits public URLs to the IndexNow protocol (Microsoft Bing, Yandex, Naver, etc.).
 *
 * Implements exponential backoff for rate limits (429) and transient errors,
 * with endpoint failover from api.indexnow.org to bing.com/indexnow.
 */
export async function submitToIndexNow(
  urls: string[],
  options?: { key?: string; host?: string; maxRetries?: number },
): Promise<IndexNowResult> {
  const key = (options?.key || getIndexNowKey()).trim();
  const host = (options?.host || getIndexNowHost()).trim();
  const maxRetries = options?.maxRetries ?? 3;

  const { valid, rejected } = sanitizeIndexNowUrls(urls, host);

  if (valid.length === 0) {
    return {
      success: false,
      submittedCount: 0,
      message: rejected.length > 0
        ? `No valid public URLs to submit. ${rejected.length} URLs rejected (non-public or invalid host).`
        : "No URLs provided.",
      rejectedUrls: rejected,
    };
  }

  const keyLocation = `https://${host}/${key}.txt`;
  const endpoints = [PRIMARY_INDEXNOW_ENDPOINT, FALLBACK_INDEXNOW_ENDPOINT];

  // IndexNow allows up to 10,000 URLs per request
  const batch = valid.slice(0, 10000);

  const payload: IndexNowPayload = {
    host,
    key,
    keyLocation,
    urlList: batch,
  };

  let lastStatus = 0;
  let lastMessage = "";
  let successfulEndpoint = "";

  for (const endpoint of endpoints) {
    for (let attempt = 1; attempt <= maxRetries; attempt++) {
      try {
        const response = await fetch(endpoint, {
          method: "POST",
          headers: {
            "Content-Type": "application/json; charset=utf-8",
            "User-Agent": "TenderHub-IndexNow/1.0 (+https://tenderhub.lk)",
          },
          body: JSON.stringify(payload),
          signal: AbortSignal.timeout(10000),
        });

        lastStatus = response.status;

        // 200 OK: URLs submitted
        // 202 Accepted: Key pending validation
        if (response.status === 200 || response.status === 202) {
          successfulEndpoint = endpoint;
          return {
            success: true,
            submittedCount: batch.length,
            statusCode: response.status,
            message: response.status === 200
              ? `Successfully submitted ${batch.length} URLs to IndexNow via ${new URL(endpoint).hostname}.`
              : `IndexNow accepted ${batch.length} URLs (key verification pending) via ${new URL(endpoint).hostname}.`,
            endpointUsed: endpoint,
            rejectedUrls: rejected,
            submittedUrls: batch,
          };
        }

        // 429: Rate limited - wait with exponential backoff
        if (response.status === 429) {
          const waitMs = Math.min(1000 * Math.pow(2, attempt), 8000);
          await new Promise((r) => setTimeout(r, waitMs));
          continue;
        }

        // 400: Invalid format; 403: Forbidden / key invalid; 422: Unprocessable
        const responseText = await response.text().catch(() => "");
        lastMessage = `IndexNow HTTP ${response.status}: ${responseText || response.statusText}`;

        // Client errors (400, 403, 422) should not be blindly retried on the same endpoint
        if (response.status >= 400 && response.status < 500) {
          break;
        }
      } catch (err) {
        lastMessage = err instanceof Error ? err.message : "Network error";
        if (attempt < maxRetries) {
          await new Promise((r) => setTimeout(r, 1000 * attempt));
        }
      }
    }
  }

  return {
    success: false,
    submittedCount: 0,
    statusCode: lastStatus,
    message: `Failed to submit URLs to IndexNow. ${lastMessage || "All endpoints unreachable."}`,
    endpointUsed: successfulEndpoint || undefined,
    rejectedUrls: rejected,
  };
}
