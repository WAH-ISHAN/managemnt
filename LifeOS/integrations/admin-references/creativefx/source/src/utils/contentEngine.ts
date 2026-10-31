// Public content always comes from the server. No browser-cache fallback.
export async function fetchPublishedContent(base: string, signal: AbortSignal) {
  const response = await fetch(`${base}/api/content?fresh=${Date.now()}`, {
    signal,
    cache: 'no-store',
    headers: { 'Cache-Control': 'no-cache', Pragma: 'no-cache' },
  });
  if (!response.ok) throw new Error(`Content request failed (${response.status})`);
  const document = await response.json();
  if (!document || typeof document !== 'object' || Array.isArray(document) || document.offline) {
    throw new Error('Invalid content response');
  }
  return document;
}

export function hasAdminDraft() {
  try {
    return !!sessionStorage.getItem('cfx_admin_token') &&
      Number(localStorage.getItem('cfx_site_content_v6_ts')) >
      Number(localStorage.getItem('cfx_published_ts'));
  } catch { return false; }
}

export async function clearLegacyPublicCache() {
  if (!hasAdminDraft()) {
    try { localStorage.removeItem('cfx_site_content_v6'); } catch {}
  }
  // Clear only this origin's app cache once per tab; preserve admin drafts.
  try {
    if (sessionStorage.getItem('cfx_cache_cleanup_v1')) return;
    if ('caches' in window) {
      const keys = await caches.keys();
      await Promise.all(keys.map(key => caches.delete(key)));
    }
    sessionStorage.setItem('cfx_cache_cleanup_v1', '1');
  } catch { /* Storage APIs can be unavailable in private browsing. */ }
}
