/**
 * The ONLY public source designations (AGENTS.md §4, permanent brand rule).
 * Upstream URLs and scraper source names must never reach a user-facing view;
 * every surface that labels a notice's origin goes through this function.
 */
export function publicSourceLabel(
  n: { is_native?: boolean | null; source_label?: string | null },
  src?: string | null,
): string {
  // The API now sends only its computed public label; prefer it.
  if (n.source_label === "TenderHub e-Procurement" || n.source_label === "Official Gazette / National Press") {
    return n.source_label;
  }
  return n.is_native || /promise|e-procurement/i.test(src ?? "")
    ? "TenderHub e-Procurement"
    : "Official Gazette / National Press";
}
