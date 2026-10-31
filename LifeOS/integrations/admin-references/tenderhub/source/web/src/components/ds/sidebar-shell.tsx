"use client";

/**
 * Tailwind Plus "Sidebar layout" application shell (light sidebar variant).
 *
 *   Desktop (lg+):  fixed left sidebar (brand · portal · nav · switcher) + a
 *                   sticky top bar over the content.
 *   Mobile:         sticky top bar with a menu button that opens an off-canvas
 *                   navigation drawer (dialog: backdrop, Escape, focus trap,
 *                   focus restore, body scroll lock).
 *
 * This component owns ONLY navigation chrome. Everything functional — the user
 * menu, density toggle, verification banner — is passed in from the server
 * shell as slots, so their existing wiring is untouched.
 */

import Link, { useLinkStatus } from "next/link";
import Image from "next/image";
import type { Route } from "next";
import { usePathname } from "next/navigation";
import { useEffect, useRef, useState, type ReactNode } from "react";
import { CommandPalette } from "@/components/console/interactive";
import {
  Activity, AlertTriangle, BarChart3, Bell, Briefcase, Building2, Calendar, ClipboardList, CreditCard,
  Database, FileText, Gavel, Globe, Inbox, LayoutDashboard, Lock, Mail, Megaphone, Menu, Rss, Scale,
  ShieldCheck, UserCheck, Users, X, FolderOpen, LoaderCircle, Hourglass, Plug, LifeBuoy, MailOpen, UserCog, ToggleRight, type LucideIcon,
} from "lucide-react";

export type NavItem = { href: Route; label: string };
/** Optional sectioned navigation (used by the admin console). */
export type NavGroup = { name: string; items: NavItem[] };
export type Portal = "app" | "workspace" | "console";

/** Icons resolved from the route, so the three layouts keep their plain {href,label} nav. */
export const NAV_ICONS: Record<string, LucideIcon> = {
  "/console": LayoutDashboard,
  "/console/automation": BarChart3,
  "/console/ingestion": Database,
  "/console/marketing": Megaphone,
  "/console/campaigns": Calendar,
  "/console/notices": FileText,
  "/console/blog": FileText,
  "/console/organisations": Building2,
  "/console/organisation-portal": ToggleRight,
  "/console/users": Users,
  "/console/kyc": UserCheck,
  "/console/trials": Hourglass,
  "/console/worksuite": Plug,
  "/console/support": LifeBuoy,
  "/console/email-analytics": MailOpen,
  "/console/payments": CreditCard,
  "/console/risk": AlertTriangle,
  "/console/security": Lock,
  "/console/email": Mail,
  "/console/seo": Globe,
  "/console/acquisition": BarChart3,
  "/console/legal-holds": Scale,
  "/workspace": Briefcase,
  "/workspace/planning": ClipboardList,
  "/workspace/contracts": FileText,
  "/workspace/calendar": Calendar,
  "/workspace/analytics": BarChart3,
  "/workspace/auctions": Gavel,
  "/workspace/suppliers": Users,
  "/workspace/team": UserCheck,
  "/workspace/kyc": ShieldCheck,
  "/app": Rss,
  "/app/alerts": Bell,
  "/app/pipeline": ClipboardList,
  "/app/vault": FolderOpen,
  "/app/kyc": ShieldCheck,
  "/app/notifications": Inbox,
  "/app/privacy": Lock,
  "/app/profile": UserCog,
  "/app/integrations": Plug,
  "/workspace/profile": UserCog,
  "/workspace/integrations": Plug,
};

/** Sidebar colour themes. "brand" = Tailwind Plus "Brand sidebar with header" (admin console). */
const THEMES = {
  light: {
    panel: "border-r border-gray-200 bg-white",
    drawer: "bg-white ring-1 ring-gray-900/10",
    item: "text-gray-700 hover:bg-gray-50 hover:text-brand-700",
    itemActive: "bg-gray-50 text-brand-700",
    icon: "text-gray-400 group-hover:text-brand-700",
    iconActive: "text-brand-700",
    heading: "text-gray-400",
    label: "text-gray-500",
    initial: "border-gray-200 bg-white text-gray-400 group-hover:border-brand-600 group-hover:text-brand-700",
    initialActive: "border-brand-600 bg-white text-brand-700",
    spinner: "text-brand-600",
    logo: "/logo/2.png",
  },
  brand: {
    panel: "bg-primary-600",
    drawer: "bg-primary-600",
    item: "text-primary-100 hover:bg-primary-700 hover:text-white",
    itemActive: "bg-primary-700 text-white",
    icon: "text-primary-200 group-hover:text-white",
    iconActive: "text-white",
    heading: "text-primary-200",
    label: "text-primary-200",
    initial: "border-primary-400 bg-primary-500 text-white",
    initialActive: "border-white bg-primary-500 text-white",
    spinner: "text-white",
    logo: "/logo/logo-white.png",
  },
} as const;
export type ShellTheme = keyof typeof THEMES;
type Theme = (typeof THEMES)[ShellTheme];

const PORTAL_ROOTS = new Set(["/", "/console", "/workspace", "/app"]);

function isActive(pathname: string, href: string) {
  return PORTAL_ROOTS.has(href) ? pathname === href : pathname === href || pathname.startsWith(`${href}/`);
}

/** Next owns pending state, including cancelled and interrupted navigation. */
function NavigationPending({ t }: { t: Theme }) {
  const { pending } = useLinkStatus();
  return (
    <span className="ml-auto flex size-4 shrink-0 items-center justify-center" role="status">
      {pending ? (
        <>
          <LoaderCircle aria-hidden="true" className={`size-4 motion-safe:animate-spin ${t.spinner}`} />
          <span className="sr-only">Opening panel</span>
        </>
      ) : null}
    </span>
  );
}

function NavList({ nav, pathname, onNavigate, t }: { nav: NavItem[]; pathname: string; onNavigate?: () => void; t: Theme }) {
  return (
    <ul role="list" className="-mx-2 space-y-1">
      {nav.map((n) => {
        const Icon = NAV_ICONS[n.href] ?? Activity;
        const active = isActive(pathname, n.href);
        return (
          <li key={n.href}>
            <Link
              href={n.href}
              onClick={onNavigate}
              aria-current={active ? "page" : undefined}
              className={`group flex items-center gap-x-3 rounded-md p-2 text-sm/6 font-semibold transition-colors ${
                active ? t.itemActive : t.item
              }`}
            >
              <Icon aria-hidden="true" className={`size-5 shrink-0 ${active ? t.iconActive : t.icon}`} />
              <span className="truncate">{n.label}</span>
              <NavigationPending t={t} />
            </Link>
          </li>
        );
      })}
    </ul>
  );
}

function GroupedNav({ groups, pathname, onNavigate, t }: { groups: NavGroup[]; pathname: string; onNavigate?: () => void; t: Theme }) {
  return (
    <div className="space-y-6">
      {groups.map((g) => (
        <div key={g.name}>
          <div className={`text-xs/6 font-semibold ${t.heading}`}>{g.name}</div>
          <div className="mt-1"><NavList nav={g.items} pathname={pathname} onNavigate={onNavigate} t={t} /></div>
        </div>
      ))}
    </div>
  );
}

function PortalSwitcher({ portal, pathname: _p, onNavigate, t }: { portal: Portal; pathname: string; onNavigate?: () => void; t: Theme }) {
  const items: { href: Route; label: string; key: Portal | "public" }[] = [
    { href: "/console", label: "Staff console", key: "console" },
    { href: "/workspace", label: "Procurement workspace", key: "workspace" },
    { href: "/app", label: "Bidder portal", key: "app" },
    { href: "/tenders", label: "Public catalogue ↗", key: "public" },
  ];
  return (
    <div>
      <div className={`text-xs/6 font-semibold ${t.label}`}>Switch portal</div>
      <ul role="list" className="-mx-2 mt-2 space-y-1">
        {items.map((i) => {
          const active = i.key === portal;
          return (
            <li key={i.href}>
              <Link
                href={i.href}
                onClick={onNavigate}
                aria-current={active ? "page" : undefined}
                className={`group flex items-center gap-x-3 rounded-md p-2 text-sm/6 font-semibold ${
                  active ? t.itemActive : t.item
                }`}
              >
                <span
                  aria-hidden="true"
                  className={`flex size-6 shrink-0 items-center justify-center rounded-lg border text-[0.625rem] font-medium ${
                    active ? t.initialActive : t.initial
                  }`}
                >
                  {i.label[0]}
                </span>
                <span className="truncate">{i.label}</span>
                <NavigationPending t={t} />
              </Link>
            </li>
          );
        })}
      </ul>
    </div>
  );
}

function Brand({ label, t }: { label: string; t: Theme }) {
  return (
    <Link href="/" className="flex flex-col gap-0.5 justify-center py-1">
      <Image
        src={t.logo}
        alt="TenderHub.lk"
        width={125}
        height={32}
        className="h-6 w-auto object-contain"
        priority
      />
      <span className={`pl-0.5 text-xs font-semibold ${t.label}`}>{label}</span>
    </Link>
  );
}

export function SidebarShell({
  portal,
  label,
  nav,
  groups,
  theme = "light",
  showSwitcher,
  topRight,
  banner,
  children,
}: {
  portal: Portal;
  label: string;
  nav: NavItem[];
  groups?: NavGroup[];
  theme?: ShellTheme;
  showSwitcher: boolean;
  topRight: ReactNode;
  banner?: ReactNode;
  children: ReactNode;
}) {
  const pathname = usePathname();
  const t = THEMES[theme];
  const [open, setOpen] = useState(false);
  const drawerRef = useRef<HTMLDivElement>(null);
  const restoreRef = useRef<HTMLElement | null>(null);

  // Off-canvas drawer a11y: Escape closes, Tab is trapped, body cannot scroll,
  // focus moves in on open and is restored on close.
  useEffect(() => {
    if (!open) return;
    restoreRef.current = document.activeElement as HTMLElement;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") setOpen(false);
      if (e.key === "Tab" && drawerRef.current) {
        const f = drawerRef.current.querySelectorAll<HTMLElement>('a[href],button,[tabindex]:not([tabindex="-1"])');
        if (!f.length) return;
        const first = f[0], last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    };
    document.addEventListener("keydown", onKey);
    document.body.style.overflow = "hidden";
    setTimeout(() => drawerRef.current?.querySelector<HTMLElement>("a[href],button")?.focus(), 20);
    return () => {
      document.removeEventListener("keydown", onKey);
      document.body.style.overflow = "";
      restoreRef.current?.focus();
    };
  }, [open]);

  // Close the drawer on route change.
  useEffect(() => {
    const timer = window.setTimeout(() => setOpen(false), 0);
    return () => window.clearTimeout(timer);
  }, [pathname]);

  return (
    <div className="min-h-screen bg-gray-50">
      {/* ---- Mobile off-canvas drawer ------------------------------------- */}
      {open ? (
        <div className="relative z-50 lg:hidden" role="dialog" aria-modal="true" aria-label="Navigation">
          <div className="fixed inset-0 bg-gray-900/80 animate-fadeIn" onClick={() => setOpen(false)} aria-hidden="true" />
          <div className="fixed inset-0 flex">
            <div ref={drawerRef} className="relative mr-16 flex w-full max-w-xs flex-1 animate-slideRight">
              <div className="absolute top-0 left-full flex w-16 justify-center pt-5">
                <button type="button" onClick={() => setOpen(false)} className="-m-2.5 p-2.5 text-white" aria-label="Close navigation">
                  <X aria-hidden="true" className="size-6" />
                </button>
              </div>
              <div className={`flex grow flex-col gap-y-5 overflow-y-auto px-6 pb-4 ${t.drawer}`}>
                <div className="flex h-16 shrink-0 items-center"><Brand label={label} t={t} /></div>
                <nav className="flex flex-1 flex-col" aria-label="Portal">
                  <ul role="list" className="flex flex-1 flex-col gap-y-7">
                    <li>{groups ? <GroupedNav groups={groups} pathname={pathname} onNavigate={() => setOpen(false)} t={t} /> : <NavList nav={nav} pathname={pathname} onNavigate={() => setOpen(false)} t={t} />}</li>
                    {showSwitcher ? <li><PortalSwitcher portal={portal} pathname={pathname} onNavigate={() => setOpen(false)} t={t} /></li> : null}
                  </ul>
                </nav>
              </div>
            </div>
          </div>
        </div>
      ) : null}

      {/* ---- Desktop static sidebar --------------------------------------- */}
      <div className="hidden lg:fixed lg:inset-y-0 lg:z-40 lg:flex lg:w-72 lg:flex-col">
        <div className={`flex grow flex-col gap-y-5 overflow-y-auto px-6 pb-4 ${t.panel}`}>
          <div className="flex h-16 shrink-0 items-center"><Brand label={label} t={t} /></div>
          <nav className="flex flex-1 flex-col" aria-label="Portal">
            <ul role="list" className="flex flex-1 flex-col gap-y-7">
              <li>{groups ? <GroupedNav groups={groups} pathname={pathname} t={t} /> : <NavList nav={nav} pathname={pathname} t={t} />}</li>
              {showSwitcher ? <li><PortalSwitcher portal={portal} pathname={pathname} t={t} /></li> : null}
              <li className="mt-auto -mx-2">
                <Link href="/tenders" className={`group flex items-center gap-x-3 rounded-md p-2 text-sm/6 font-semibold ${t.item}`}>
                  <Globe aria-hidden="true" className={`size-5 shrink-0 ${t.icon}`} />
                  Public catalogue
                </Link>
              </li>
            </ul>
          </nav>
        </div>
      </div>

      {/* ---- Top bar + content ------------------------------------------- */}
      <div className="lg:pl-72">
        <div className="sticky top-0 z-30 flex h-16 shrink-0 items-center gap-x-4 border-b border-gray-200 bg-white px-4 shadow-xs sm:gap-x-6 sm:px-6 lg:px-8">
          <button type="button" onClick={() => setOpen(true)} className="-m-2.5 p-2.5 text-gray-700 lg:hidden" aria-label="Open navigation" aria-haspopup="dialog" aria-expanded={open}>
            <Menu aria-hidden="true" className="size-6" />
          </button>
          <div aria-hidden="true" className="h-6 w-px bg-gray-900/10 lg:hidden" />
          <div className="flex flex-1 items-center gap-x-4 self-stretch lg:gap-x-6">
            <div className="flex items-center gap-2 lg:hidden">
              <span className="text-sm font-bold text-gray-900">TenderHub</span>
              <span className="rounded-md bg-gray-50 px-1.5 py-0.5 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-500/10">{label}</span>
            </div>
            {groups ? (
              <div className="hidden flex-1 sm:block">
                <CommandPalette items={groups.flatMap((g) => g.items.map((i) => ({ ...i, group: g.name, icon: NAV_ICONS[i.href] ?? Activity })))} />
              </div>
            ) : null}
            <div className="ml-auto flex items-center gap-x-3 sm:gap-x-4">
              {topRight}
            </div>
          </div>
        </div>

        {banner}

        <main className="py-6 sm:py-8">
          <div className="mx-auto max-w-[1400px] px-4 sm:px-6 lg:px-8">{children}</div>
        </main>
      </div>
    </div>
  );
}
