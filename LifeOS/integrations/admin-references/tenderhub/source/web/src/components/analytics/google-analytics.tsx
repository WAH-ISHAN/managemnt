"use client";

import { useEffect, useRef, useState, Suspense } from "react";
import { usePathname, useSearchParams } from "next/navigation";
import Script from "next/script";

declare global {
  interface Window {
    dataLayer?: unknown[];
    gtag?: (...args: unknown[]) => void;
  }
}

export const ANALYTICS_CONSENT_EVENT = "tenderhub:analytics-consent-change";
export type AnalyticsConsentState = "unset" | "granted" | "denied" | "browser-denied";

/**
 * Prefixes for private, authenticated, administrative, or sensitive areas of TenderHub.
 * Traffic within these areas is strictly NEVER tracked by Google Analytics to protect
 * tender submissions, bidder details, internal operations, and administrative data.
 */
export const SENSITIVE_PATH_PREFIXES = [
  "/console",
  "/admin",
  "/workspace",
  "/app",
  "/dashboard",
  "/favorites",
  "/settings",
  "/related-tenders",
  "/bidder",
  "/company",
  "/login",
  "/register",
  "/forgot-password",
  "/reset-password",
  "/verify-email",
  "/subscription",
  "/invitations",
  "/social-media",
  "/api",
  "/forbidden",
] as const;

/**
 * Whitelist of safe public query parameters allowed in page view reporting.
 * All unrecognized or potentially sensitive parameters are dropped.
 */
export const ALLOWED_PUBLIC_QUERY_PARAMS = new Set([
  "q",
  "query",
  "category",
  "province",
  "sector",
  "type",
  "status",
  "page",
  "limit",
  "sort",
  "order",
  "filter",
  "utm_source",
  "utm_medium",
  "utm_campaign",
  "utm_term",
  "utm_content",
  "gclid",
  "fbclid",
]);

/**
 * Disallowed parameter keywords and sensitive tokens.
 */
export const SENSITIVE_PARAM_NAMES = new Set([
  "token",
  "access_token",
  "refresh_token",
  "id_token",
  "auth",
  "key",
  "api_key",
  "secret",
  "password",
  "pwd",
  "pass",
  "credential",
  "session",
  "code",
  "email",
  "user_email",
  "username",
  "phone",
  "mobile",
  "name",
  "full_name",
  "first_name",
  "last_name",
  "nic",
  "br_number",
  "vat_number",
  "tax_id",
  "card",
  "cvv",
  "account",
  "account_number",
  "bank",
  "order_id",
  "payment_id",
  "reference_id",
  "invite",
  "invitation",
  "otp",
  "state",
]);

/**
 * Validates GA4 measurement ID format (e.g. G-KGXSNYKTGZ).
 */
export function isValidMeasurementId(id: string | null | undefined): boolean {
  if (!id || typeof id !== "string") return false;
  return /^G-[A-Z0-9]+$/i.test(id.trim());
}

/**
 * Check if the given pathname is a public page eligible for analytics tracking.
 */
export function isTrackablePublicPath(pathname: string | null | undefined): boolean {
  if (!pathname || typeof pathname !== "string") return false;
  const cleanPath = pathname.trim().toLowerCase();
  if (!cleanPath.startsWith("/")) return false;

  for (const prefix of SENSITIVE_PATH_PREFIXES) {
    if (cleanPath === prefix || cleanPath.startsWith(`${prefix}/`)) {
      return false;
    }
  }

  return true;
}

/**
 * Strips all sensitive query parameters, PII, email addresses, and security tokens.
 * Only retains allowed public discovery and marketing attribution tags.
 */
export function sanitizeSearchQuery(searchParams: URLSearchParams | null | undefined): string {
  if (!searchParams) return "";
  const sanitized = new URLSearchParams();

  searchParams.forEach((value, key) => {
    const lowerKey = key.trim().toLowerCase();
    if (!ALLOWED_PUBLIC_QUERY_PARAMS.has(lowerKey)) return;
    if (SENSITIVE_PARAM_NAMES.has(lowerKey)) return;

    // Reject values containing email (@), JWT headers (eyJ), or exceeding 120 chars
    const trimmedVal = value.trim();
    if (trimmedVal.includes("@") || trimmedVal.includes("eyJ") || trimmedVal.length > 120) {
      return;
    }

    sanitized.append(lowerKey, trimmedVal);
  });

  const queryString = sanitized.toString();
  return queryString ? `?${queryString}` : "";
}

/**
 * Determines whether the visitor has signaled privacy / consent denial via DNT, GPC, or local storage.
 */
export function analyticsConsentState(): AnalyticsConsentState {
  if (typeof window === "undefined") return "unset";

  let stored: string | null = null;
  try {
    stored = localStorage.getItem("th_analytics_consent");
  } catch {
    // Storage failure cannot imply consent.
  }

  const nav = navigator as unknown as { globalPrivacyControl?: boolean; doNotTrack?: string };
  if (nav.globalPrivacyControl === true) return "browser-denied";
  if (nav.doNotTrack === "1" || (window as unknown as { doNotTrack?: string }).doNotTrack === "1") {
    return "browser-denied";
  }
  if (stored === "denied") return "denied";
  return stored === "granted" ? "granted" : "unset";
}

export function isAnalyticsConsentDenied(): boolean {
  return analyticsConsentState() !== "granted";
}

/**
 * Public helper to update analytics consent state dynamically from user settings or consent banners.
 */
export function updateAnalyticsConsent(granted: boolean) {
  if (typeof window === "undefined") return;

  try {
    localStorage.setItem("th_analytics_consent", granted ? "granted" : "denied");
  } catch {
    // ignore storage access error
  }

  if (typeof window.gtag === "function") {
    window.gtag("consent", "update", {
      analytics_storage: granted ? "granted" : "denied",
    });
  }

  window.dispatchEvent(new CustomEvent(ANALYTICS_CONSENT_EVENT, { detail: { granted } }));
}

/**
 * Route listener component that dispatches page_view events on client-side navigation.
 * Encapsulated in a separate component to allow clean Suspense boundary wrapping.
 */
function GoogleAnalyticsRouteTracker({ measurementId }: { measurementId: string }) {
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const lastTrackedRef = useRef<string | null>(null);
  const [consentRevision, setConsentRevision] = useState(0);

  useEffect(() => {
    const onConsentChange = () => setConsentRevision((value) => value + 1);
    window.addEventListener(ANALYTICS_CONSENT_EVENT, onConsentChange);
    const onStorageChange = (event: StorageEvent) => {
      if (event.key === "th_analytics_consent") onConsentChange();
    };
    window.addEventListener("storage", onStorageChange);
    return () => {
      window.removeEventListener(ANALYTICS_CONSENT_EVENT, onConsentChange);
      window.removeEventListener("storage", onStorageChange);
    };
  }, []);

  useEffect(() => {
    if (isAnalyticsConsentDenied()) {
      lastTrackedRef.current = null;
      return;
    }
    if (!isTrackablePublicPath(pathname)) {
      return;
    }

    const safeQuery = sanitizeSearchQuery(searchParams);
    const sanitizedPath = `${pathname}${safeQuery}`;

    // Prevent duplicate dispatches if path & query haven't changed
    if (lastTrackedRef.current === sanitizedPath) {
      return;
    }

    lastTrackedRef.current = sanitizedPath;

    if (typeof window !== "undefined") {
      window.dataLayer = window.dataLayer || [];
      if (!window.gtag) {
        window.gtag = function (...args: unknown[]) {
          window.dataLayer?.push(args);
        };
      }

      const pageLocation = `${window.location.origin}${sanitizedPath}`;
      window.gtag("event", "page_view", {
        page_title: typeof document !== "undefined" ? document.title : "",
        page_location: pageLocation,
        page_path: sanitizedPath,
        send_to: measurementId,
      });
    }
  }, [pathname, searchParams, measurementId, consentRevision]);

  return null;
}

interface GoogleAnalyticsProps {
  measurementId?: string;
}

/**
 * Google Analytics 4 (gtag.js) implementation for TenderHub Sri Lanka.
 *
 * Invariants:
 * 1. Only loads on valid GA4 measurement IDs (defaulting to G-KGXSNYKTGZ).
 * 2. Strict Privacy Boundary: Never tracks administrative consoles (/console), workspaces (/workspace),
 *    portals (/app, /bidder, /company), or auth routes (/login, /register, /reset-password).
 * 3. Never loads external tracking scripts on visits confined strictly to private areas.
 * 4. Sanitizes all query strings, stripping PII, emails, tokens, and submission details.
 * 5. Google Consent Mode v2 integrated with DNT & GPC signal support.
 * 6. Prevents duplicate page_view events via single-tag management and client-route change tracking.
 */
export function GoogleAnalytics({ measurementId }: GoogleAnalyticsProps) {
  const pathname = usePathname();
  const id = (measurementId || process.env.NEXT_PUBLIC_GA_MEASUREMENT_ID || "G-KGXSNYKTGZ").trim();
  const [hasEnteredPublic, setHasEnteredPublic] = useState(() => isTrackablePublicPath(pathname));
  const [consentGranted, setConsentGranted] = useState(false);

  useEffect(() => {
    const refreshConsent = () => setConsentGranted(!isAnalyticsConsentDenied());
    refreshConsent();
    window.addEventListener(ANALYTICS_CONSENT_EVENT, refreshConsent);
    const onStorageChange = (event: StorageEvent) => {
      if (event.key === "th_analytics_consent") refreshConsent();
    };
    window.addEventListener("storage", onStorageChange);
    return () => {
      window.removeEventListener(ANALYTICS_CONSENT_EVENT, refreshConsent);
      window.removeEventListener("storage", onStorageChange);
    };
  }, []);

  if (!hasEnteredPublic && isTrackablePublicPath(pathname)) {
    setHasEnteredPublic(true);
  }

  // Validate GA4 measurement ID format (e.g., G-XXXXXXXXXX)
  if (!isValidMeasurementId(id)) {
    return null;
  }

  // If the visitor has only navigated through private/staff/sensitive areas, never load the GA4 scripts
  if (!hasEnteredPublic || !consentGranted) {
    return null;
  }

  return (
    <>
      <Script
        id="ga4-src"
        strategy="afterInteractive"
        src={`https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(id)}`}
      />
      <Script
        id="ga4-init"
        strategy="afterInteractive"
        dangerouslySetInnerHTML={{
          __html: `
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('consent', 'default', {
              'analytics_storage': 'granted',
              'ad_storage': 'denied',
              'ad_user_data': 'denied',
              'ad_personalization': 'denied'
            });
            gtag('js', new Date());
            gtag('config', '${id}', {
              send_page_view: false,
              anonymize_ip: true,
              restricted_data_processing: true
            });
          `,
        }}
      />
      <Suspense fallback={null}>
        <GoogleAnalyticsRouteTracker measurementId={id} />
      </Suspense>
    </>
  );
}
