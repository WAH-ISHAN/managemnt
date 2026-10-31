import React, { createContext, useContext, useState, useEffect, useCallback, useRef } from 'react';
import { fetchPublishedContent } from '../utils/contentEngine';
import publishedSnapshot from '../../data/content.json';
import { ALL_PROJECTS } from '../data/projectsData';
import { SELECTED_WEDDINGS, WEDDING_TIMELINE_STAGES, WEDDING_APPROACH_PRINCIPLES } from '../data/weddingData';
import type { ContentStatus, GalleryInput, GalleryMedia, ProjectCase, TeamMember } from '../types';
import { detectMediaKind, toGalleryItems } from '../types';

// ─── Default Content (fallback if no server data) ────────────────────────────
// REFERENCE SNAPSHOT: site-specific default content and team/business data omitted.
// Supply the target site schema/defaults before using this implementation.
export const DEFAULT_CONTENT = {} as Record<string, unknown>;

export type SiteContent = typeof DEFAULT_CONTENT;

/** Migrates legacy fields (portfolio.items, missing home ids) into the current shape. */
export function migrateContent(raw: unknown): SiteContent {
  const merged = deepMerge(DEFAULT_CONTENT, raw) as SiteContent & { portfolio?: { items?: { id: string; isWedding?: boolean }[] } };

  // If the new `home.selectedWorkIds` was never saved but legacy portfolio items exist,
  // convert them once into project references.
  const legacyItems = merged.portfolio?.items || [];
  const hasLegacy = legacyItems.length > 0;
  const homeAny = merged.home as unknown as { _migrated?: boolean };
  if (hasLegacy && !homeAny._migrated) {
    const ids = legacyItems
      .map((it: { id: string; isWedding?: boolean }) => (it.isWedding ? '__weddings__' : it.id))
      .filter(id => id === '__weddings__' || merged.projects.some(p => p.id === id));
    if (ids.length > 0) {
      merged.home = { ...merged.home, selectedWorkIds: ids };
    }
  }

  // FORCE hardcoded services to avoid stale Render API overrides
  // Preserve published admin service edits; defaults are already merged above.

  // Ensure default wedding stories stay in sync with curated code defaults if gallery is older
  if (Array.isArray(merged.weddingStories) && Array.isArray(DEFAULT_CONTENT.weddingStories)) {
    const upgraded = DEFAULT_CONTENT.weddingStories.map(defStory => {
      const existing = (merged.weddingStories as typeof DEFAULT_CONTENT.weddingStories).find(s => s.id === defStory.id);
      if (!existing) return defStory;
      if (!existing.gallery || existing.gallery.length < defStory.gallery.length) {
        return { ...existing, gallery: defStory.gallery };
      }
      return existing;
    });
    const customStories = (merged.weddingStories as typeof DEFAULT_CONTENT.weddingStories).filter(
      s => !DEFAULT_CONTENT.weddingStories.some(def => def.id === s.id)
    );
    merged.weddingStories = [...upgraded, ...customStories];
  }
    
  return merged as SiteContent;
}

// ─── Context ──────────────────────────────────────────────────────────────────
interface ContentContextType {
  content: SiteContent;
  /** Updates local state immediately and queues a server save. */
  updateContent: (path: string[], value: unknown) => Promise<void>;
  /** Immediately saves any queued changes to the shared server copy. */
  saveDraft: () => void;
  /** Saves the current content to the server so every device receives it. */
  publishContent: () => Promise<boolean>;
  resetContent: () => Promise<void>;
  isLoading: boolean;
  isSaving: boolean;
  /** True while there are local changes waiting for server confirmation. */
  hasDraft: boolean;
  lastSaved: Date | null;
}

const ContentContext = createContext<ContentContextType>({
  content: DEFAULT_CONTENT,
  updateContent: async () => {},
  saveDraft: () => {},
  publishContent: async () => false,
  resetContent: async () => {},
  isLoading: false,
  isSaving: false,
  hasDraft: false,
  lastSaved: null,
});

// Helper: set nested value by path array
function setNestedValue(obj: unknown, path: string[], value: unknown): unknown {
  if (path.length === 0) return value;
  const [head, ...rest] = path;
  const idx = parseInt(head, 10);
  if (!isNaN(idx) && Array.isArray(obj)) {
    const arr = [...obj];
    arr[idx] = setNestedValue(arr[idx], rest, value);
    return arr;
  }
  return { ...(obj as Record<string, unknown>), [head]: setNestedValue((obj as Record<string, unknown>)?.[head], rest, value) };
}

// ─── API base URL (same origin via Vite proxy in dev/mobile, or custom env) ──
export const API_BASE =
  (typeof import.meta !== 'undefined' && (import.meta as { env?: Record<string, string> }).env?.VITE_API_URL) || '';

try {
  [
    'cfx_site_content', 'cfx_site_content_v2', 'cfx_site_content_v3', 'cfx_site_content_v3_ts',
    'cfx_site_content_v4', 'cfx_site_content_v4_ts', 'cfx_site_content_v5', 'cfx_site_content_v5_ts',
    'cfx_site_content_v6', 'cfx_site_content_v6_ts', 'cfx_published_ts',
  ].forEach(k => localStorage.removeItem(k));
} catch (e) {}

function getInitialContent(): SiteContent {
  return migrateContent(publishedSnapshot);
}

export const ContentProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [content, setContent] = useState<SiteContent>(getInitialContent);
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [lastSaved, setLastSaved] = useState<Date | null>(null);

  // The shared server copy is the source of truth. This flag only represents
  // edits that are still waiting for server confirmation in this tab.
  const [hasDraft, setHasDraft] = useState(false);
  const contentRef = useRef(content);
  const hasDraftRef = useRef(false);
  const editVersionRef = useRef(0);
  const autoSaveTimerRef = useRef<number | null>(null);
  const publishContentRef = useRef<(doc: SiteContent, version: number) => Promise<boolean>>(async () => false);

  useEffect(() => {
    contentRef.current = content;
  }, [content]);

  // Apply SEO metadata + brand accent to the document whenever content changes
  useEffect(() => {
    const seo = { ...DEFAULT_CONTENT.seo, ...(content.seo || {}) };


    // A route-level useSeo() owns the title when one is active (e.g. a project
    // detail page) — the global default must not win the race against it.
    const routeOwnsTitle = (globalThis as unknown as { __cfxRouteTitle?: { active: boolean } }).__cfxRouteTitle?.active;
    if (!routeOwnsTitle) document.title = seo.title;

    const setMeta = (attr: string, key: string, val: string) => {
      let el = document.head.querySelector(`meta[${attr}="${key}"]`) as HTMLMetaElement | null;
      if (!el) {
        el = document.createElement('meta');
        el.setAttribute(attr, key);
        document.head.appendChild(el);
      }
      el.setAttribute('content', val);
    };
    setMeta('name', 'description', seo.description);
    setMeta('name', 'keywords', seo.keywords);
    setMeta('name', 'robots', seo.allowIndexing ? 'index, follow' : 'noindex, nofollow');

    // Open Graph requires ABSOLUTE image URLs — absolutize against canonical origin
    let origin = '';
    try { origin = seo.canonicalUrl ? new URL(seo.canonicalUrl).origin : ''; } catch { origin = ''; }
    const abs = (url: string) => {
      if (!url) return url;
      if (/^https?:\/\//i.test(url)) return url;
      return `${origin}${url}`;
    };

    setMeta('property', 'og:title', seo.title);
    setMeta('property', 'og:description', seo.description);
    setMeta('property', 'og:image', abs(seo.ogImage));
    setMeta('property', 'og:url', seo.canonicalUrl || window.location.href);
    setMeta('name', 'twitter:card', 'summary_large_image');
    setMeta('name', 'twitter:title', seo.title);
    setMeta('name', 'twitter:description', seo.description);
    setMeta('name', 'twitter:image', abs(seo.ogImage));
    if (seo.searchConsoleId) setMeta('name', 'google-site-verification', seo.searchConsoleId);
    if (seo.bingWebmasterId) setMeta('name', 'msvalidate.01', seo.bingWebmasterId);

    let canonical = document.head.querySelector('link[rel="canonical"]') as HTMLLinkElement | null;
    if (!canonical && seo.canonicalUrl) {
      canonical = document.createElement('link');
      canonical.setAttribute('rel', 'canonical');
      document.head.appendChild(canonical);
    }
    if (canonical && seo.canonicalUrl) canonical.setAttribute('href', seo.canonicalUrl);

    // Brand theme & colors → dynamic CSS variables
    const theme = { ...DEFAULT_CONTENT.theme, ...(content.theme || {}) };
    const root = document.documentElement;

    if (theme.accentColor && /^#[0-9a-fA-F]{3,8}$/.test(theme.accentColor)) {
      root.style.setProperty('--fx-yellow', theme.accentColor);
      root.style.setProperty('--fx-accent', theme.accentColor);
    }
    if (theme.backgroundColor && /^#[0-9a-fA-F]{3,8}$/.test(theme.backgroundColor)) {
      root.style.setProperty('--fx-black', theme.backgroundColor);
    }
    if (theme.textColor && /^#[0-9a-fA-F]{3,8}$/.test(theme.textColor)) {
      root.style.setProperty('--fx-white', theme.textColor);
    }
    if (theme.textMutedColor && /^#[0-9a-fA-F]{3,8}$/.test(theme.textMutedColor)) {
      root.style.setProperty('--fx-gray', theme.textMutedColor);
    }
    if (theme.borderColor) {
      root.style.setProperty('--fx-border-dark', theme.borderColor);
    }

    // Font families. Sanitize the font-family name so a value can't break out of
    // the CSS variable declaration (M-2) — keep letters, numbers, spaces, hyphens.
    const cleanFontName = (name: string) => String(name).replace(/[^a-zA-Z0-9 _-]/g, '').trim().slice(0, 64);
    if (theme.fontDisplay) {
      root.style.setProperty('--fx-font-display', `'${cleanFontName(theme.fontDisplay)}', 'Forum', 'Cinzel', serif`);
    } else {
      root.style.setProperty('--fx-font-display', `'Forum', 'Cinzel', serif`);
    }
    if (theme.fontBody) {
      root.style.setProperty('--fx-font-body', `'${cleanFontName(theme.fontBody)}', 'JetBrains Mono', monospace`);
    } else {
      root.style.setProperty('--fx-font-body', `'JetBrains Mono', monospace`);
    }
    if (theme.fontMono) {
      root.style.setProperty('--fx-font-mono', `'${cleanFontName(theme.fontMono)}', 'JetBrains Mono', monospace`);
    }

    // Dynamic Google Fonts loader — restricted to the official Google Fonts host
    // so this can't be pointed at an arbitrary external stylesheet (M-2).
    const isTrustedFontUrl = (url: string) => {
      try {
        const u = new URL(url);
        return u.protocol === 'https:' && (u.hostname === 'fonts.googleapis.com' || u.hostname === 'fonts.gstatic.com');
      } catch { return false; }
    };
    if (theme.customGoogleFontUrl && isTrustedFontUrl(theme.customGoogleFontUrl)) {
      let fontLink = document.getElementById('cfx-custom-google-fonts') as HTMLLinkElement | null;
      if (!fontLink) {
        fontLink = document.createElement('link');
        fontLink.id = 'cfx-custom-google-fonts';
        fontLink.rel = 'stylesheet';
        document.head.appendChild(fontLink);
      }
      fontLink.href = theme.customGoogleFontUrl;
    }

    // Custom CSS code injector
    let customStyle = document.getElementById('cfx-custom-user-styles') as HTMLStyleElement | null;
    if (!customStyle) {
      customStyle = document.createElement('style');
      customStyle.id = 'cfx-custom-user-styles';
      document.head.appendChild(customStyle);
    }
    customStyle.textContent = content.customCss || '';
  }, [content]);

  // Always hydrate from the shared server copy on mount. The bundled snapshot
  // is only a first-paint fallback while this request is in flight.
  useEffect(() => {
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 8000);

    fetchPublishedContent(API_BASE, controller.signal)
      .then(serverData => {
        if (!serverData || typeof serverData !== 'object' || Object.keys(serverData).length === 0) {
          setIsLoading(false);
          return;
        }

        // Do not replace an edit made while the initial request was in flight.
        if (hasDraftRef.current) return;

        // The server is authoritative for every new admin/public session.
        const migrated = migrateContent(serverData);
        contentRef.current = migrated;
        setContent(migrated);
        setHasDraft(false);
        setIsLoading(false);
      })
      .catch(() => {
        console.warn('[ContentContext] Server not reachable; cached public content is disabled.');
        setIsLoading(false);
      })
      .finally(() => window.clearTimeout(timeout));

    return () => {
      window.clearTimeout(timeout);
      controller.abort();
    };
  }, []);

  // Update instantly in the editor, then debounce the shared server save so
  // typing does not produce one network request per keystroke.
  const updateContent = useCallback(async (path: string[], value: unknown) => {
    const next = setNestedValue(contentRef.current, path, value) as SiteContent;
    contentRef.current = next;
    setContent(next);
    hasDraftRef.current = true;
    setHasDraft(true);
    const version = ++editVersionRef.current;
    if (autoSaveTimerRef.current !== null) window.clearTimeout(autoSaveTimerRef.current);
    autoSaveTimerRef.current = window.setTimeout(() => {
      autoSaveTimerRef.current = null;
      void publishContentRef.current(contentRef.current, version);
    }, 800);
  }, []);

  // Flush any queued autosave immediately.
  const saveDraft = useCallback(() => {
    if (autoSaveTimerRef.current !== null) window.clearTimeout(autoSaveTimerRef.current);
    autoSaveTimerRef.current = null;
    void publishContentRef.current(contentRef.current, editVersionRef.current);
  }, []);


  const persistContent = useCallback(async (docToPublish: SiteContent, version: number) => {
    const token = sessionStorage.getItem('cfx_admin_token');
    if (!token) {
      console.error('[ContentContext] Save blocked: admin session is missing.');
      return false;
    }

    setIsSaving(true);
    try {
      const res = await fetch(`${API_BASE}/api/content`, {
        method: 'POST',
        cache: 'no-store',
        headers: {
          'Content-Type': 'application/json',
          'x-admin-token': token,
          'Cache-Control': 'no-cache',
        },
        body: JSON.stringify(docToPublish),
      });

      if (res.status === 401) {
        sessionStorage.removeItem('cfx_admin_token');
        if (new URLSearchParams(window.location.search).get('admin') === '1') {
          window.location.reload();
        }
        return false;
      }

      if (res.ok) {
        const responseData = await res.json();
        if (responseData.ok !== true) throw new Error('Server did not confirm the save');
        if (version === editVersionRef.current) {
          hasDraftRef.current = false;
          setHasDraft(false);
          setLastSaved(new Date(responseData._updatedAt || Date.now()));
        }
        return true;
      } else {
        console.error('[ContentContext] Save failed', res.status, await res.text());
        return false;
      }
    } catch (e) {
      console.error('[ContentContext] Save error', e);
      return false;
    } finally {
      setIsSaving(false);
    }
  }, []);

  publishContentRef.current = persistContent;

  const publishContent = useCallback(async () => {
    if (autoSaveTimerRef.current !== null) window.clearTimeout(autoSaveTimerRef.current);
    autoSaveTimerRef.current = null;
    return persistContent(contentRef.current, editVersionRef.current);
  }, [persistContent]);

  useEffect(() => {
    const syncFromServer = async () => {
      if (hasDraftRef.current || document.visibilityState === 'hidden') return;
      const controller = new AbortController();
      try {
        const serverData = await fetchPublishedContent(API_BASE, controller.signal);
        if (!serverData || typeof serverData !== 'object') return;
        const currentTs = (contentRef.current as unknown as { _updatedAt?: number })._updatedAt || 0;
        const serverTs = (serverData as { _updatedAt?: number })._updatedAt || 0;
        if (serverTs > currentTs) {
          const migrated = migrateContent(serverData);
          contentRef.current = migrated;
          setContent(migrated);
          setLastSaved(new Date(serverTs));
        }
      } catch {
        // The next interval or focus event retries without replacing visible content.
      }
    };
    const interval = window.setInterval(syncFromServer, 15000);
    const onFocus = () => void syncFromServer();
    window.addEventListener('focus', onFocus);
    document.addEventListener('visibilitychange', onFocus);
    return () => {
      window.clearInterval(interval);
      window.removeEventListener('focus', onFocus);
      document.removeEventListener('visibilitychange', onFocus);
    };
  }, []);

  const resetContent = useCallback(async () => {
    contentRef.current = DEFAULT_CONTENT;
    setContent(DEFAULT_CONTENT);
    setIsSaving(true);
    try {
      await fetch(`${API_BASE}/api/content/reset`, {
        method: 'POST',
        headers: { 'x-admin-token': sessionStorage.getItem('cfx_admin_token') || '' },
      });
      setLastSaved(new Date());
    } catch (e) {
      console.error('[ContentContext] Reset failed', e);
    } finally {
      setIsSaving(false);
    }
  }, []);

  return (
    <ContentContext.Provider value={{ content, updateContent, saveDraft, publishContent, resetContent, isLoading, isSaving, hasDraft, lastSaved }}>
      {children}
    </ContentContext.Provider>
  );
};

export const useContent = () => useContext(ContentContext);

/**
 * Returns dynamic styles, fonts, and animation state for any section
 */
export function useSectionStyle(sectionKey: string) {
  const { content } = useContent();
  const styles = content.sectionStyles?.[sectionKey] || {};
  const masterAnim = content.settings?.animationsMasterEnabled ?? true;

  const inlineStyle: React.CSSProperties = {};
  if (styles.bg) inlineStyle.backgroundColor = styles.bg;
  if (styles.text) inlineStyle.color = styles.text;
  if (styles.font) inlineStyle.fontFamily = `'${styles.font}', sans-serif`;

  return {
    style: inlineStyle,
    bg: styles.bg || undefined,
    text: styles.text || undefined,
    accent: styles.accent || content.theme?.accentColor || '#fcbf13',
    headingScale: styles.headingScale ?? 1,
    bodyScale: styles.bodyScale ?? 1,
    animationsEnabled: masterAnim && (styles.animationsEnabled ?? true),
    font: styles.font || undefined,
  };
}

// ─── Master project helpers (single source of truth) ─────────────────────────
/**
 * Builds the authoritative project dataset: records edited in the Admin Panel
 * (data/content.json) win; static defaults fill any missing fields. Projects
 * created in the Admin Panel are included even though they have no static entry.
 */
function buildProjects(content: SiteContent): ProjectCase[] {
  const serverProjects: ProjectCase[] = Array.isArray(content.projects) ? content.projects : [];
  if (!serverProjects.length) return ALL_PROJECTS;

  const staticById = new Map(ALL_PROJECTS.map(p => [p.id, p]));
  const seen = new Set<string>();
  const out: ProjectCase[] = [];

  for (const sp of serverProjects) {
    seen.add(sp.id);
    const base = staticById.get(sp.id);
    out.push(base ? {
      ...base,
      ...sp,
      socialPosts: (sp.socialPosts && sp.socialPosts.length > 0) ? sp.socialPosts : base.socialPosts,
      socialUrl: sp.socialUrl || base.socialUrl,
      socialLabel: sp.socialLabel || base.socialLabel,
    } : { ...sp });
  }
  for (const [id, base] of staticById.entries()) {
    if (!seen.has(id)) {
      out.push(base);
    }
  }
  return out;
}

/** Every project record (including drafts/archived — for the Admin Panel). */
export const useAllProjects = (): ProjectCase[] => buildProjects(useContent().content);

const STATUS_ORDER: Record<ContentStatus, number> = { published: 0, draft: 1, archived: 2 };

/** Published only — what the public website is allowed to display. */
export function filterPublished<T extends { status?: ContentStatus }>(items: T[]): T[] {
  return items.filter(p => (p.status ?? 'published') === 'published');
}

export const usePublishedProjects = (): ProjectCase[] => filterPublished(buildProjects(useContent().content));

/**
 * Legacy hook name kept for compatibility. Returns ALL records merged with
 * defaults (used by Admin); prefer usePublishedProjects() on the public site.
 */
export const useProjects = (): ProjectCase[] => buildProjects(useContent().content);

/** Resolves `home.selectedWorkIds` (and legacy featured flags) to project cards. */
export function resolveSelectedWork(content: SiteContent): { projects: ProjectCase[]; weddingsTile: boolean } {
  const all = buildProjects(content);
  const published = filterPublished(all);
  const ids = content.home?.selectedWorkIds?.length
    ? content.home.selectedWorkIds
    : (content.portfolio?.items || []).map(it => (it.isWedding ? '__weddings__' : it.id));

  const weddingsTile = content.home?.showWeddingsTile ?? ids.includes('__weddings__');
  const projects = ids
    .filter((id): id is string => id !== '__weddings__')
    .map(id => published.find(p => p.id === id))
    .filter((p): p is ProjectCase => !!p);
  return { projects, weddingsTile };
}

/** Featured Work for the homepage pinning scroll — max 6, order follows list. */
export function resolveFeaturedWork(content: SiteContent): ProjectCase[] {
  const published = filterPublished(buildProjects(content));
  const explicit = (content.home?.featuredProjectIds || [])
    .map(id => published.find(p => p.id === id))
    .filter((p): p is ProjectCase => !!p);
  if (explicit.length > 0) return explicit.slice(0, 6);

  // Fallback to the original beautiful 6 photo editorial collection:
  const defaultPhotoIds = ['photo-01', 'photo-02', 'photo-03', 'photo-04', 'photo-05', 'photo-06'];
  const defaultCollection = defaultPhotoIds
    .map(id => published.find(p => p.id === id))
    .filter((p): p is ProjectCase => !!p);
  if (defaultCollection.length > 0) return defaultCollection;

  return published.filter(p => p.featured).slice(0, 6);
}

export { toGalleryItems, detectMediaKind };
export type { GalleryMedia, GalleryInput };

// Sort helper used by admin lists
export function sortByStatusThenOrder<T extends { status?: ContentStatus }>(list: T[]): T[] {
  return [...list].sort((a, b) => (STATUS_ORDER[a.status ?? 'published'] - STATUS_ORDER[b.status ?? 'published']));
}
