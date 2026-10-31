import type { ReactNode } from "react";
import Link from "next/link";
import type { Route } from "next";
import { ChevronRight } from "lucide-react";
import { DensityToggle } from "./nav";
import { SidebarShell, type NavGroup } from "./sidebar-shell";
import { AdminShell, type RailModule } from "./admin-shell";
import { UnverifiedBanner } from "@/components/portal/UnverifiedBanner";
import { UserMenu } from "@/components/portal/user-menu";

/**
 * The three-portal shell — Tailwind Plus sidebar application layout.
 *
 * Server component: it composes the client SidebarShell (navigation chrome) and
 * hands it the functional widgets as slots. The prop contract is unchanged, so
 * the console, workspace and bidder layouts continue to work as before.
 */
export function PortalShell({
  portal,
  nav,
  groups,
  orgName,
  userName,
  plan,
  isStaff,
  emailVerified,
  userEmail,
  children,
}: {
  portal: "app" | "workspace" | "console";
  nav: { href: string; label: string }[];
  /** Sectioned navigation + ⌘K palette. Only the admin console passes this. */
  groups?: NavGroup[];
  orgName: string;
  userName: string;
  plan?: string;
  isStaff?: boolean;
  emailVerified?: boolean;
  userEmail?: string;
  children: ReactNode;
}) {
  const label = {
    app: "Bidder Portal",
    workspace: "Workspace",
    console: "Staff Console",
  }[portal];
  const showSwitcher = Boolean(isStaff || plan === "staff" || orgName === "TenderHub");

  // Every portal uses the WorkSuite module layout: the staff console as the
  // Admin Center, the bidder and workspace dashboards with their own tile.
  if (groups) {
    const railModule: RailModule = portal === "console"
      ? { title: "Admin Center", subtitle: "Platform administration", home: "/console", homeLabel: "Overview", icon: "shield", tile: "bg-slate-700", storageKey: "th.admin.rail" }
      : portal === "workspace"
        ? { title: "Workspace", subtitle: orgName, home: "/workspace", homeLabel: "Tenders", icon: "briefcase", tile: "bg-blue-600", storageKey: "th.workspace.rail" }
        : { title: "Bidder Portal", subtitle: orgName, home: "/app", homeLabel: "Feed", icon: "rss", tile: "bg-blue-600", storageKey: "th.app.rail" };
    return (
      <AdminShell
        module={railModule}
        groups={groups}
        topRight={<UserMenu orgName={orgName} userName={userName} plan={plan} isStaff={isStaff} userEmail={userEmail} />}
        banner={emailVerified === false && !isStaff ? <UnverifiedBanner email={userEmail} /> : null}
      >
        {children}
      </AdminShell>
    );
  }

  return (
    <SidebarShell
      portal={portal}
      label={label}
      nav={nav}
      groups={groups}
      theme={portal === "console" ? "brand" : "light"}
      showSwitcher={showSwitcher}
      topRight={
        <>
          <DensityToggle />
          <div aria-hidden="true" className="hidden h-6 w-px bg-gray-900/10 sm:block" />
          <UserMenu orgName={orgName} userName={userName} plan={plan} isStaff={isStaff} userEmail={userEmail} />
        </>
      }
      banner={emailVerified === false && !isStaff ? <UnverifiedBanner email={userEmail} /> : null}
    >
      {children}
    </SidebarShell>
  );
}

/**
 * Clean Page Heading Component aligned with Tailwind UI Page Headings
 */
export function PageHead({
  title,
  sub,
  right,
  crumbs,
}: {
  title: string;
  sub?: ReactNode;
  right?: ReactNode;
  crumbs?: { href: string; label: string }[];
}) {
  return (
    <div className="mb-6 border-b border-slate-200/80 pb-5">
      {crumbs?.length ? (
        <nav aria-label="Breadcrumb" className="mb-2.5">
          <ol role="list" className="flex flex-wrap items-center gap-x-1.5 text-xs text-slate-500 font-medium">
            {crumbs.map((c, i) => (
              <li key={c.href} className="flex items-center gap-x-1.5">
                {i > 0 ? <ChevronRight aria-hidden="true" className="size-3.5 shrink-0 text-slate-400" /> : null}
                <Link href={c.href as Route} className="hover:text-slate-800 transition-colors">{c.label}</Link>
              </li>
            ))}
          </ol>
        </nav>
      ) : null}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div className="min-w-0 flex-1">
          <h1 className="text-xl sm:text-2xl font-bold tracking-tight text-slate-900">
            {title}
          </h1>
          {sub ? (
            <p className="mt-1 text-xs sm:text-sm text-slate-500 max-w-3xl leading-relaxed">
              {sub}
            </p>
          ) : null}
        </div>
        {right && <div className="flex items-center gap-2.5 shrink-0">{right}</div>}
      </div>
    </div>
  );
}
