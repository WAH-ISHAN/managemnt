import type { Notice } from "@/lib/types";

const TEST_NOTICE_PATTERNS = [
  "official test notice",
  "demo tender",
  "demo notice",
  "fixture notice",
  "sample tender",
  "test notice for production",
];

export function compactText(value?: string | null): string {
  return (value ?? "").replace(/\s+/g, " ").trim();
}

export function truncateText(value: string, max = 160): string {
  const clean = compactText(value);
  if (clean.length <= max) return clean;
  return `${clean.slice(0, max - 1).replace(/\s+\S*$/, "")}...`;
}

/** Keep result titles concise while distinguishing notices with identical headlines. */
export function buildNoticeTitle(n: Pick<Notice, "title" | "slug">): string {
  return `${truncateText(n.title, 44)} | ${n.slug.slice(-6)} | TenderHub`;
}

export function noticeAuthority(n: Pick<Notice, "buyer" | "authority_name" | "org_name" | "raw_organization">): string {
  return compactText(n.buyer || n.authority_name || n.org_name || n.raw_organization || "Official Procuring Authority");
}

export function isTestOrDemoNotice(n: Partial<Notice>): boolean {
  const haystack = compactText([
    n.title,
    n.summary,
    n.summary_teaser,
    n.description,
    n.description_teaser,
    n.reference,
  ].filter(Boolean).join(" ")).toLowerCase();

  return TEST_NOTICE_PATTERNS.some((pattern) => haystack.includes(pattern));
}

/**
 * Determines whether a tender notice or auction has legitimate public metadata and should be indexed.
 * Legitimate public notices that require login for documents/downloads (or have slugs/titles
 * referencing login) must NOT be excluded; they contain valuable procurement metadata.
 * Only synthetic mock/fixture notices or notices lacking a slug are excluded.
 */
export function isIndexableNotice(n: Partial<Notice>): boolean {
  if (!n.slug) return false;
  if (isTestOrDemoNotice(n)) return false;
  return true;
}

export function formatDateForSeo(value?: string | null): string {
  if (!value) return "not specified";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "not specified";
  return date.toLocaleDateString("en-LK", { year: "numeric", month: "short", day: "numeric" });
}

export function buildNoticeMetaDescription(n: Notice): string {
  const authority = noticeAuthority(n);
  const location = compactText(n.district || n.raw_location || "Sri Lanka");
  const category = compactText(n.category || n.raw_category || "procurement");
  const closing = formatDateForSeo(n.closing_at);
  const ref = compactText(n.reference);

  return truncateText(
    `${authority} ${category} tender in ${location}. Closing date ${closing}${ref ? `, reference ${ref}` : ""}. View verified TenderHub Sri Lanka tender details.`,
    165,
  );
}

export function buildTenderOverview(n: Notice): string {
  const authority = noticeAuthority(n);
  const category = compactText(n.category || n.raw_category || "general procurement");
  const location = compactText(n.district || n.raw_location || "Sri Lanka");
  const published = formatDateForSeo(n.published_at);
  const closing = formatDateForSeo(n.closing_at);
  const ref = compactText(n.reference);
  const source = n.is_native ? "TenderHub e-Procurement" : "Official Gazette / National Press";

  return compactText(
    `${authority} is inviting bids for ${n.title}. This ${category} opportunity is listed for ${location}. ` +
      `TenderHub verified the notice from ${source}; it was published on ${published} and closes on ${closing}.` +
      `${ref ? ` The tender reference is ${ref}.` : ""}`,
  );
}
