"use client";

/**
 * The portal shell, matching WorkSuite's module layout (the Admin Center for
 * the staff console, the same rail for the bidder and workspace dashboards):
 * a white module rail on the left (tile header, "Find a setting", folding
 * uppercase sections, 36px rows with a left accent bar on the selected one,
 * a collapse-to-icons button at its foot) and the page on a slate canvas.
 * Below md the same rail opens as a drawer.
 */

import Link from "next/link";
import type { Route } from "next";
import { usePathname, useRouter } from "next/navigation";
import { useCallback, useEffect, useMemo, useState, type ReactNode } from "react";
import {
  Activity, ChevronDown, ChevronsLeft, ExternalLink, LayoutGrid, Menu, Search, ShieldCheck, X, Briefcase, Rss, type LucideIcon,
} from "lucide-react";
import { NAV_ICONS, type NavGroup, type NavItem } from "./sidebar-shell";

/** What a portal's rail shows in its header and as its first row. */
export interface RailModule {
  title: string;
  subtitle: string;
  /** The portal's home page: the rail's first row and the header link. */
  home: string;
  homeLabel: string;
  /** Icon name (a string, so a server layout can pass it to this client shell). */
  icon: keyof typeof TILE_ICONS;
  /** The header tile's flat colour (a whole Tailwind class). */
  tile: string;
  /** Where the rail remembers being narrowed and its folded sections. */
  storageKey: string;
}

const TILE_ICONS = { shield: ShieldCheck, briefcase: Briefcase, rss: Rss } as const;

function TileIcon({ name }: { name: keyof typeof TILE_ICONS }) {
  const Icon = TILE_ICONS[name];
  return <Icon className="size-4" aria-hidden />;
}

export const ADMIN_MODULE: RailModule = {
  title: "Admin Center", subtitle: "Platform administration", home: "/console", homeLabel: "Overview",
  icon: "shield", tile: "bg-slate-700", storageKey: "th.admin.rail",
};

interface RailPrefs { min: boolean; closed: string[] }

function readPrefs(key: string): RailPrefs {
  try {
    const v = JSON.parse(localStorage.getItem(key) ?? "{}") as Partial<RailPrefs>;
    return { min: !!v.min, closed: Array.isArray(v.closed) ? v.closed.filter((t) => typeof t === "string") : [] };
  } catch {
    return { min: false, closed: [] };
  }
}

function savePrefs(key: string, p: RailPrefs) {
  try { localStorage.setItem(key, JSON.stringify(p)); } catch { /* private window */ }
}

const ROOTS = new Set(["/console", "/app", "/workspace"]);

function isActive(pathname: string, href: string) {
  return ROOTS.has(href) ? pathname === href : pathname === href || pathname.startsWith(`${href}/`);
}

function RailItem({ item, active, collapsed, onNavigate }: { item: NavItem; active: boolean; collapsed: boolean; onNavigate?: () => void }) {
  const Icon: LucideIcon = NAV_ICONS[item.href] ?? Activity;
  return (
    <div
      className={`group relative flex items-center rounded-lg transition before:absolute before:left-0 before:top-1/2 before:h-5 before:w-1 before:-translate-y-1/2 before:rounded-r-full before:bg-blue-600 before:transition ${
        active ? "bg-blue-50 before:opacity-100" : "before:opacity-0 hover:bg-slate-50"
      }`}
    >
      <Link
        href={item.href}
        onClick={onNavigate}
        title={collapsed ? item.label : undefined}
        aria-current={active ? "page" : undefined}
        className={`flex h-9 min-w-0 flex-1 items-center gap-x-3 rounded-lg text-sm font-medium no-underline ${
          collapsed ? "justify-center px-0" : "px-2.5"
        } ${active ? "text-blue-700" : "text-slate-700 group-hover:text-blue-700"}`}
      >
        <Icon aria-hidden className={`size-4 shrink-0 ${active ? "text-blue-700" : "text-slate-400 group-hover:text-blue-700"}`} />
        <span className={collapsed ? "sr-only" : "flex-1 truncate"}>{item.label}</span>
      </Link>
    </div>
  );
}

function RailSection({ title, open, closed, collapsed, onToggle, children }: {
  title: string; open: boolean; closed: boolean; collapsed: boolean; onToggle: () => void; children: ReactNode;
}) {
  if (collapsed) {
    return (
      <div>
        <div role="separator" aria-label={title} className="mx-3 my-2.5 h-px bg-slate-200" />
        <div className="space-y-0.5 px-2">{children}</div>
      </div>
    );
  }
  const shut = !open && closed;
  return (
    <div>
      <button type="button" aria-expanded={!shut} onClick={onToggle} className="group/section flex w-full items-center gap-1 px-5 pb-1 pt-4 text-left">
        <span className="flex-1 truncate text-[11px] font-semibold uppercase tracking-wider text-slate-500 group-hover/section:text-slate-700">{title}</span>
        <ChevronDown aria-hidden className={`size-3.5 shrink-0 text-slate-400 transition-transform group-hover/section:text-slate-600 ${shut ? "-rotate-90" : ""}`} />
      </button>
      {!shut && <div className="space-y-0.5 px-3">{children}</div>}
    </div>
  );
}

function Rail({ module: m, groups, collapsed, prefs, toggleSection, toggleCollapsed, onNavigate, className = "" }: {
  module: RailModule;
  groups: NavGroup[];
  collapsed: boolean;
  prefs: RailPrefs;
  toggleSection: (t: string) => void;
  toggleCollapsed?: () => void;
  onNavigate?: () => void;
  className?: string;
}) {
  const pathname = usePathname();
  const router = useRouter();
  const [q, setQ] = useState("");
  const query = collapsed ? "" : q.trim().toLowerCase();
  const all = useMemo(() => groups.flatMap((g) => g.items.map((i) => ({ ...i, group: g.name }))), [groups]);
  const hits = query ? all.filter((i) => `${i.label} ${i.group}`.toLowerCase().includes(query)) : [];

  return (
    <nav aria-label={m.title} className={`flex shrink-0 flex-col overflow-hidden border-r border-slate-200 bg-white transition-[width] duration-200 ${collapsed ? "w-14" : "w-60"} ${className}`}>
      {/* Header: the module tile, title and subtitle, then "Find a setting". */}
      <div className={`shrink-0 pb-1 pt-3 ${collapsed ? "" : "px-3"}`}>
        <div className={`flex h-10 items-center ${collapsed ? "justify-center" : "px-1"}`}>
          <Link href={m.home as Route} onClick={onNavigate} title={m.title} className={`flex min-w-0 flex-1 items-center gap-2.5 text-slate-900 no-underline ${collapsed ? "justify-center" : ""}`}>
            <span className={`flex size-8 shrink-0 items-center justify-center rounded-lg text-white ${m.tile}`}>
              <TileIcon name={m.icon} />
            </span>
            {collapsed ? <span className="sr-only">{m.title}</span> : (
              <span className="flex min-w-0 flex-1 flex-col leading-tight">
                <span className="truncate text-sm font-semibold text-slate-900">{m.title}</span>
                <span className="truncate text-xs text-slate-500">{m.subtitle}</span>
              </span>
            )}
          </Link>
        </div>
        {!collapsed && (
          <div className="relative mt-2 flex items-center">
            <Search className="pointer-events-none absolute left-2.5 size-4 text-slate-400" aria-hidden />
            <input
              type="search"
              aria-label="Find a setting"
              placeholder="Find a setting"
              value={q}
              autoComplete="off"
              onChange={(e) => setQ(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === "Escape") setQ("");
                if (e.key === "Enter" && hits[0]) { setQ(""); onNavigate?.(); router.push(hits[0].href); }
              }}
              className="h-9 w-full rounded-lg border border-slate-200 bg-white pl-8 pr-2.5 text-sm text-slate-900 placeholder:text-slate-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20"
            />
          </div>
        )}
      </div>

      {/* Rows. */}
      <div className="flex min-h-0 flex-1 flex-col overflow-y-auto pb-3">
        {query ? (
          <div className="space-y-0.5 px-3 pt-2">
            {hits.map((h) => (
              <RailItem key={h.href} item={{ href: h.href, label: `${h.group} › ${h.label}` }} active={isActive(pathname, h.href)} collapsed={false} onNavigate={() => { setQ(""); onNavigate?.(); }} />
            ))}
            {hits.length === 0 && (
              <p className="px-2.5 py-4 text-[13px] leading-normal text-slate-500">
                No setting matches “{q}”.{" "}
                <button type="button" onClick={() => setQ("")} className="font-semibold text-blue-700">Clear</button>
              </p>
            )}
          </div>
        ) : (
          <>
            <div className={`space-y-0.5 pt-2 ${collapsed ? "px-2" : "px-3"}`}>
              <RailItem item={{ href: m.home as Route, label: m.homeLabel }} active={pathname === m.home} collapsed={collapsed} onNavigate={onNavigate} />
            </div>
            {groups.map((g) => {
              const items = g.items.filter((i) => i.href !== m.home);
              if (items.length === 0) return null;
              return (
                <RailSection
                  key={g.name}
                  title={g.name}
                  open={items.some((i) => isActive(pathname, i.href))}
                  closed={prefs.closed.includes(g.name)}
                  collapsed={collapsed}
                  onToggle={() => toggleSection(g.name)}
                >
                  {items.map((i) => (
                    <RailItem key={i.href} item={i} active={isActive(pathname, i.href)} collapsed={collapsed} onNavigate={onNavigate} />
                  ))}
                </RailSection>
              );
            })}
          </>
        )}
      </div>

      {/* Foot: the public site, then collapse. */}
      <div className="shrink-0 border-t border-slate-200 pb-[env(safe-area-inset-bottom)]">
        <div className={`pt-2 ${collapsed ? "flex justify-center" : "px-3"}`}>
          <Link href="/tenders" title="Public catalogue" className={`flex h-9 items-center gap-2.5 rounded-lg text-sm text-slate-700 no-underline hover:bg-slate-50 ${collapsed ? "w-9 justify-center" : "px-2.5"}`}>
            <ExternalLink aria-hidden className="size-4 shrink-0 text-slate-400" />
            {collapsed ? <span className="sr-only">Public catalogue</span> : <span className="flex-1 truncate font-medium">Public catalogue</span>}
          </Link>
        </div>
        {toggleCollapsed && (
          <div className={`py-2 ${collapsed ? "flex justify-center" : "px-3"}`}>
            <button
              type="button"
              onClick={toggleCollapsed}
              aria-label={collapsed ? "Expand menu" : "Collapse menu"}
              title={`${collapsed ? "Expand menu" : "Collapse menu"} (Ctrl+\\)`}
              className={`flex h-8 items-center gap-2 rounded-lg text-[13px] font-medium text-slate-500 transition hover:bg-slate-50 hover:text-slate-700 ${collapsed ? "w-9 justify-center" : "w-full px-2.5"}`}
            >
              <ChevronsLeft aria-hidden className={`size-4 shrink-0 ${collapsed ? "-scale-x-100" : ""}`} />
              {!collapsed && (
                <>
                  <span aria-hidden className="flex-1 text-left">Collapse</span>
                  <kbd aria-hidden className="rounded border border-slate-200 px-1 text-[10px] font-medium text-slate-400">Ctrl+\</kbd>
                </>
              )}
            </button>
          </div>
        )}
      </div>
    </nav>
  );
}

export function AdminShell({ module: m = ADMIN_MODULE, groups, topRight, banner, children }: {
  module?: RailModule; groups: NavGroup[]; topRight: ReactNode; banner?: ReactNode; children: ReactNode;
}) {
  const pathname = usePathname();
  const [prefs, setPrefs] = useState<RailPrefs>({ min: false, closed: [] });
  const [drawer, setDrawer] = useState(false);

  useEffect(() => { const t = window.setTimeout(() => setPrefs(readPrefs(m.storageKey)), 0); return () => window.clearTimeout(t); }, [m.storageKey]);
  useEffect(() => { const t = window.setTimeout(() => setDrawer(false), 0); return () => window.clearTimeout(t); }, [pathname]);

  const update = useCallback((fn: (p: RailPrefs) => RailPrefs) => {
    setPrefs((now) => { const next = fn(now); savePrefs(m.storageKey, next); return next; });
  }, [m.storageKey]);
  const toggleCollapsed = useCallback(() => update((p) => ({ ...p, min: !p.min })), [update]);
  const toggleSection = useCallback((t: string) => update((p) => ({
    ...p, closed: p.closed.includes(t) ? p.closed.filter((x) => x !== t) : [...p.closed, t],
  })), [update]);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key === "\\") { e.preventDefault(); toggleCollapsed(); }
      if (e.key === "Escape") setDrawer(false);
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [toggleCollapsed]);

  const current = groups.flatMap((g) => g.items).find((i) => i.href !== m.home && isActive(pathname, i.href));

  return (
    <div className="flex h-screen min-h-0 flex-col bg-slate-50 md:flex-row">
      <Rail module={m} groups={groups} collapsed={prefs.min} prefs={prefs} toggleSection={toggleSection} toggleCollapsed={toggleCollapsed} className="hidden md:flex" />

      {drawer && (
        <div className="fixed inset-0 z-50 md:hidden" role="dialog" aria-modal="true" aria-label={`${m.title} menu`}>
          <div className="absolute inset-0 bg-slate-900/40" onClick={() => setDrawer(false)} aria-hidden="true" />
          <div className="absolute inset-y-0 left-0 flex w-64">
            <Rail module={m} groups={groups} collapsed={false} prefs={prefs} toggleSection={toggleSection} onNavigate={() => setDrawer(false)} className="h-full !w-64" />
            <button type="button" onClick={() => setDrawer(false)} aria-label={`Close the ${m.title} menu`}
              className="absolute -right-11 top-3 rounded-md bg-white/90 p-1.5 text-slate-600 shadow">
              <X className="size-5" />
            </button>
          </div>
        </div>
      )}

      <div className="flex min-h-0 min-w-0 flex-1 flex-col">
        <header className="flex h-12 shrink-0 items-center gap-2 border-b border-slate-200 bg-white px-3 sm:px-6">
          <button type="button" onClick={() => setDrawer(true)} aria-label={`Open the ${m.title} menu`}
            className="-ml-1 rounded-md p-2 text-slate-500 hover:bg-slate-100 md:hidden">
            <Menu className="size-6" />
          </button>
          <span className="flex min-w-0 flex-1 items-center gap-2 truncate text-sm font-semibold text-slate-800">
            <LayoutGrid aria-hidden className="hidden size-4 text-slate-400 sm:block" />
            {current?.label ?? (pathname === m.home ? m.homeLabel : m.title)}
          </span>
          <div className="ml-auto flex items-center gap-x-3">{topRight}</div>
        </header>
        <main className="min-h-0 flex-1 overflow-y-auto">
          {banner}
          <div className="mx-auto max-w-[1400px] px-4 py-6 sm:px-6">{children}</div>
        </main>
      </div>
    </div>
  );
}
