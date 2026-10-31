/**
 * Admin console UI kit — Tailwind Plus Application UI v4 patterns, adapted.
 *
 * Server-safe (no hooks, no handlers). Shared by the admin console and the user
 * panels (/app, /workspace); public pages keep using components/ds. Every component renders exactly the
 * data its caller passes — nothing here invents values.
 *
 * Matches the WorkSuite UI kit: blue primary, slate neutrals, fixed-height
 * `rounded-md` controls, `rounded-2xl` bordered surfaces, uppercase list headers.
 */
import type { ReactNode } from "react";
import Link from "next/link";
import type { Route } from "next";
import { AlertTriangle, CheckCircle2, ChevronLeft, ChevronRight, Info, Inbox, XCircle, type LucideIcon } from "lucide-react";

const ALIGN = { left: "text-left", right: "text-right", center: "text-center" } as const;
const cx = (...c: (string | false | null | undefined)[]) => c.filter(Boolean).join(" ");

/* ---------------------------------------------------------------- Buttons */

export type ButtonVariant = "primary" | "secondary" | "soft" | "danger" | "ghost";
export type ButtonSize = "xs" | "sm" | "md" | "lg";

const BTN_VARIANT: Record<ButtonVariant, string> = {
  primary: "bg-blue-600 text-white hover:bg-blue-500 focus-visible:outline-blue-600",
  secondary: "border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 focus-visible:outline-blue-600",
  soft: "bg-blue-50 text-blue-700 hover:bg-blue-100 focus-visible:outline-blue-600",
  danger: "border border-red-200 bg-white text-red-600 hover:bg-red-50 focus-visible:outline-red-600",
  ghost: "text-slate-600 hover:bg-slate-100 focus-visible:outline-blue-600",
};
const BTN_SIZE: Record<ButtonSize, string> = {
  xs: "h-7 rounded-sm px-2.5 text-xs gap-x-1",
  sm: "h-8 rounded-sm px-3 text-sm",
  md: "h-10 rounded-md px-4 text-sm",
  lg: "h-11 rounded-md px-5 text-sm",
};

export function buttonClass(variant: ButtonVariant = "primary", size: ButtonSize = "md", extra = "") {
  return cx(
    "inline-flex shrink-0 items-center justify-center gap-x-2 font-medium whitespace-nowrap transition duration-150",
    "focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-50",
    BTN_VARIANT[variant], BTN_SIZE[size], extra,
  );
}

export function ButtonLink({ href, children, variant = "primary", size = "md", className = "" }: {
  href: Route; children: ReactNode; variant?: ButtonVariant; size?: ButtonSize; className?: string;
}) {
  return <Link href={href} className={buttonClass(variant, size, className)}>{children}</Link>;
}

/** Tailwind Plus input styling — server-safe so server pages can style native fields. */
export const inputClass =
  "block w-full min-h-10 rounded-md bg-white px-3 py-2 text-sm text-slate-900 outline-1 -outline-offset-1 outline-slate-300 " +
  "placeholder:text-slate-400 focus:outline-2 focus:-outline-offset-2 focus:outline-blue-600 transition duration-150 " +
  "disabled:cursor-not-allowed disabled:opacity-50";

/* ----------------------------------------------------------------- Badges */

export type Tone = "gray" | "primary" | "green" | "yellow" | "red" | "purple";

const BADGE: Record<Tone, string> = {
  gray: "bg-slate-50 text-slate-600 ring-slate-500/10",
  primary: "bg-blue-50 text-blue-700 ring-blue-700/10",
  green: "bg-green-50 text-green-700 ring-green-600/20",
  yellow: "bg-yellow-50 text-yellow-800 ring-yellow-600/20",
  red: "bg-red-50 text-red-700 ring-red-600/10",
  purple: "bg-purple-50 text-purple-700 ring-purple-700/10",
};
const DOT: Record<Tone, string> = {
  gray: "fill-slate-400", primary: "fill-blue-500", green: "fill-green-500",
  yellow: "fill-yellow-500", red: "fill-red-500", purple: "fill-purple-500",
};

export function Badge({ children, tone = "gray", dot = false }: { children: ReactNode; tone?: Tone; dot?: boolean }) {
  return (
    <span className={cx("inline-flex items-center gap-x-1.5 rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset", BADGE[tone])}>
      {dot ? <svg viewBox="0 0 6 6" aria-hidden="true" className={cx("size-1.5", DOT[tone])}><circle r={3} cx={3} cy={3} /></svg> : null}
      {children}
    </span>
  );
}

/** Lifecycle states that exist in the platform → tone + label. Unknown values pass through. */
const STATUS: Record<string, [Tone, string]> = {
  live: ["green", "Live"], closing_soon: ["yellow", "Closing soon"], closed: ["gray", "Closed"],
  draft: ["gray", "Draft"], published: ["green", "Published"], withdrawn: ["red", "Withdrawn"],
  cancelled: ["red", "Cancelled"], unverified: ["yellow", "Awaiting review"],
  pending: ["yellow", "Pending"], approved: ["green", "Approved"], rejected: ["red", "Rejected"],
  verified: ["green", "Verified"], suspended: ["red", "Suspended"],
  none: ["gray", "None"], active: ["green", "Active"], expired: ["red", "Expired"],
  claimed: ["yellow", "Awaiting review"], confirmed: ["green", "Confirmed"], paid: ["green", "Paid"],
  failed: ["red", "Failed"], refunded: ["gray", "Refunded"], completed: ["green", "Completed"],
  delivered: ["green", "Delivered"], skipped: ["gray", "Skipped"], queued: ["yellow", "Queued"],
  healthy: ["green", "Healthy"], idle: ["gray", "Idle"], failing: ["red", "Failing"], paused: ["gray", "Paused"],
  in_progress: ["yellow", "In progress"], success: ["green", "Success"], error: ["red", "Error"],
  open: ["primary", "Open"], resolved: ["green", "Resolved"],
};

export function StatusBadge({ status }: { status: string }) {
  const [tone, label] = STATUS[status] ?? ["gray", status.replace(/_/g, " ")];
  return <Badge tone={tone} dot>{label}</Badge>;
}

/* --------------------------------------------------------------- Headings */

export function PageHeading({ title, description, actions, crumbs, meta }: {
  title: string; description?: ReactNode; actions?: ReactNode;
  crumbs?: { href: string; label: string }[]; meta?: ReactNode;
}) {
  return (
    <div className="mb-6">
      {crumbs?.length ? (
        <nav aria-label="Breadcrumb" className="mb-3">
          <ol role="list" className="flex flex-wrap items-center gap-x-2 text-sm font-medium text-slate-500">
            {crumbs.map((c, i) => (
              <li key={c.href} className="flex items-center gap-x-2">
                {i > 0 ? <ChevronRight aria-hidden="true" className="size-4 shrink-0 text-slate-400" /> : null}
                <Link href={c.href as Route} className="hover:text-slate-700">{c.label}</Link>
              </li>
            ))}
          </ol>
        </nav>
      ) : null}
      <div className="md:flex md:items-center md:justify-between md:gap-x-6">
        <div className="min-w-0 flex-1">
          <h1 className="text-2xl font-semibold tracking-tight text-slate-900 sm:truncate">{title}</h1>
          {description ? <p className="mt-2 max-w-3xl text-sm text-slate-500">{description}</p> : null}
          {meta ? <div className="mt-2 flex flex-wrap items-center gap-x-6 gap-y-1 text-sm text-slate-500">{meta}</div> : null}
        </div>
        {actions ? <div className="mt-4 flex shrink-0 flex-wrap items-center gap-3 md:mt-0">{actions}</div> : null}
      </div>
    </div>
  );
}

export function SectionHeading({ title, description, actions }: { title: string; description?: ReactNode; actions?: ReactNode }) {
  return (
    <div className="border-b border-slate-200 pb-4 sm:flex sm:items-center sm:justify-between">
      <div className="min-w-0">
        <h2 className="text-base font-semibold text-slate-900">{title}</h2>
        {description ? <p className="mt-1 text-sm text-slate-500">{description}</p> : null}
      </div>
      {actions ? <div className="mt-3 flex items-center gap-3 sm:mt-0 sm:ml-4">{actions}</div> : null}
    </div>
  );
}

/* ------------------------------------------------------------------ Cards */

export function Card({ children, className = "" }: { children: ReactNode; className?: string }) {
  return <div className={cx("overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm", className)}>{children}</div>;
}

export function CardHeader({ title, description, actions }: { title: ReactNode; description?: ReactNode; actions?: ReactNode }) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 px-4 py-3 sm:flex-nowrap sm:px-5">
      <div className="min-w-0">
        <h3 className="text-sm font-semibold text-slate-900">{title}</h3>
        {description ? <p className="mt-1 text-sm text-slate-500">{description}</p> : null}
      </div>
      {actions ? <div className="flex shrink-0 items-center gap-3">{actions}</div> : null}
    </div>
  );
}

export function CardBody({ children, className = "" }: { children: ReactNode; className?: string }) {
  return <div className={cx("p-4 sm:p-5", className)}>{children}</div>;
}

export function CardFooter({ children, className = "" }: { children: ReactNode; className?: string }) {
  return <div className={cx("border-t border-slate-200 bg-white px-4 py-3 sm:px-5", className)}>{children}</div>;
}

/* ------------------------------------------------------------------ Stats */

export type StatTone = "default" | "good" | "warn" | "bad";
const STAT_TONE: Record<StatTone, string> = {
  default: "text-slate-900", good: "text-green-700", warn: "text-yellow-700", bad: "text-red-700",
};
const STAT_RULE: Record<StatTone, string> = {
  default: "bg-blue-500", good: "bg-green-500", warn: "bg-yellow-500", bad: "bg-red-500",
};

export function StatGrid({ children, cols = 4 }: { children: ReactNode; cols?: 2 | 3 | 4 }) {
  const c = { 2: "sm:grid-cols-2", 3: "sm:grid-cols-3", 4: "sm:grid-cols-2 lg:grid-cols-4" }[cols];
  return <dl className={cx("grid grid-cols-1 gap-4", c)}>{children}</dl>;
}

export function Stat({ label, value, detail, tone = "default", icon: Icon, href }: {
  label: string; value: ReactNode; detail?: ReactNode; tone?: StatTone; icon?: LucideIcon; href?: Route;
}) {
  const body = (
    <>
      <span aria-hidden className={cx("absolute inset-y-0 left-0 w-1", STAT_RULE[tone])} />
      <dt className="flex items-center gap-x-2 truncate text-xs font-medium text-slate-600">
        {Icon ? <Icon aria-hidden="true" className="size-4 shrink-0 text-slate-400" /> : null}
        {label}
      </dt>
      <dd className={cx("mt-1 text-2xl font-semibold tabular-nums", STAT_TONE[tone])}>{value}</dd>
      {detail ? <dd className="mt-1 truncate text-sm text-slate-500">{detail}</dd> : null}
    </>
  );
  const cls = "relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-4 pl-5 shadow-sm";
  return href
    ? <Link href={href} className={cx(cls, "block transition-shadow hover:shadow-md")}>{body}</Link>
    : <div className={cls}>{body}</div>;
}

/* ------------------------------------------------------- Description list */

export function DescriptionList({ items, columns = 1 }: { items: [string, ReactNode][]; columns?: 1 | 2 }) {
  if (columns === 2) {
    return (
      <dl className="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
        {items.map(([k, v]) => (
          <div key={k} className="min-w-0">
            <dt className="text-sm font-medium text-slate-500">{k}</dt>
            <dd className="mt-1 text-sm break-words text-slate-900">{v}</dd>
          </div>
        ))}
      </dl>
    );
  }
  return (
    <dl className="divide-y divide-slate-100">
      {items.map(([k, v]) => (
        <div key={k} className="py-3 sm:grid sm:grid-cols-3 sm:gap-4">
          <dt className="text-sm font-medium text-slate-900">{k}</dt>
          <dd className="mt-1 text-sm break-words text-slate-700 sm:col-span-2 sm:mt-0">{v}</dd>
        </div>
      ))}
    </dl>
  );
}

/* ----------------------------------------------------------------- Tables */

export function Table({ children, className = "" }: { children: ReactNode; className?: string }) {
  return (
    <div className={cx("overflow-x-auto", className)}>
      <table className="min-w-full divide-y divide-slate-200">{children}</table>
    </div>
  );
}

export function THead({ children }: { children: ReactNode }) {
  return <thead className="bg-slate-50 text-left text-[11px] uppercase tracking-wide text-slate-500"><tr>{children}</tr></thead>;
}

export function TH({ children, align = "left", className = "" }: { children?: ReactNode; align?: "left" | "right" | "center"; className?: string }) {
  return (
    <th scope="col" className={cx("px-3 py-2 font-semibold whitespace-nowrap first:pl-4 last:pr-4", ALIGN[align], className)}>
      {children}
    </th>
  );
}

export function TBody({ children }: { children: ReactNode }) {
  return <tbody className="divide-y divide-slate-100 bg-white">{children}</tbody>;
}

export function TR({ children, className = "" }: { children: ReactNode; className?: string }) {
  return <tr className={cx("transition hover:bg-slate-50", className)}>{children}</tr>;
}

export function TD({ children, align = "left", className = "", strong = false }: {
  children?: ReactNode; align?: "left" | "right" | "center"; className?: string; strong?: boolean;
}) {
  return (
    <td className={cx(
      "px-3 py-2.5 text-sm first:pl-4 last:pr-4",
      strong ? "font-medium text-slate-900" : "text-slate-600",
      ALIGN[align], className,
    )}>
      {children}
    </td>
  );
}

/** Empty row spanning the table. Use when the real query returned nothing. */
export function TEmpty({ colSpan, title, help }: { colSpan: number; title: string; help?: string }) {
  return (
    <tr><td colSpan={colSpan}><EmptyState title={title} description={help} /></td></tr>
  );
}

/* ------------------------------------------------------------- Pagination */

export function Pagination({ page, pageSize, total, hrefFor }: {
  page: number; pageSize: number; total: number; hrefFor: (page: number) => string;
}) {
  const pages = Math.max(1, Math.ceil(total / pageSize));
  const from = total === 0 ? 0 : (page - 1) * pageSize + 1;
  const to = Math.min(total, page * pageSize);
  const link = "relative inline-flex items-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-slate-900 ring-1 ring-inset ring-slate-300 hover:bg-slate-50";
  const off = "pointer-events-none opacity-50";
  return (
    <nav aria-label="Pagination" className="flex items-center justify-between border-t border-slate-200 bg-white px-4 py-3 sm:px-6">
      <p className="hidden text-sm text-slate-700 sm:block">
        Showing <span className="font-medium">{from}</span> to <span className="font-medium">{to}</span> of{" "}
        <span className="font-medium">{total}</span> results
      </p>
      <div className="flex flex-1 justify-between gap-3 sm:justify-end">
        <Link href={hrefFor(page - 1) as Route} aria-disabled={page <= 1} className={cx(link, page <= 1 && off)}>
          <ChevronLeft aria-hidden="true" className="-ml-1 size-4" />Previous
        </Link>
        <Link href={hrefFor(page + 1) as Route} aria-disabled={page >= pages} className={cx(link, page >= pages && off)}>
          Next<ChevronRight aria-hidden="true" className="-mr-1 size-4" />
        </Link>
      </div>
    </nav>
  );
}

/* --------------------------------------------------------------- Feedback */

export type AlertTone = "info" | "success" | "warning" | "error";
const ALERT: Record<AlertTone, [string, string, string, LucideIcon]> = {
  info: ["bg-blue-50", "text-blue-800", "text-blue-400", Info],
  success: ["bg-green-50", "text-green-800", "text-green-400", CheckCircle2],
  warning: ["bg-yellow-50", "text-yellow-800", "text-yellow-400", AlertTriangle],
  error: ["bg-red-50", "text-red-800", "text-red-400", XCircle],
};

export function Alert({ tone = "info", title, children, actions }: { tone?: AlertTone; title: ReactNode; children?: ReactNode; actions?: ReactNode }) {
  const [bg, text, icon, Icon] = ALERT[tone];
  return (
    <div className={cx("rounded-md p-4", bg)} role={tone === "error" ? "alert" : "status"}>
      <div className="flex">
        <Icon aria-hidden="true" className={cx("size-5 shrink-0", icon)} />
        <div className="ml-3 min-w-0 flex-1">
          <h3 className={cx("text-sm font-medium", text)}>{title}</h3>
          {children ? <div className={cx("mt-2 text-sm opacity-90", text)}>{children}</div> : null}
          {actions ? <div className="mt-4 flex gap-3">{actions}</div> : null}
        </div>
      </div>
    </div>
  );
}

export function EmptyState({ title, description, action, icon: Icon = Inbox }: {
  title: string; description?: ReactNode; action?: ReactNode; icon?: LucideIcon;
}) {
  return (
    <div className="px-6 py-12 text-center">
      <Icon aria-hidden="true" className="mx-auto size-12 text-slate-400" strokeWidth={1.25} />
      <h3 className="mt-2 text-sm font-semibold text-slate-900">{title}</h3>
      {description ? <p className="mx-auto mt-1 max-w-md text-sm text-slate-500">{description}</p> : null}
      {action ? <div className="mt-6">{action}</div> : null}
    </div>
  );
}

/* ------------------------------------------------------------- Progress */

export function ProgressBar({ value, max, tone = "primary", label }: { value: number; max: number; tone?: "primary" | "green" | "yellow" | "red"; label?: string }) {
  const pct = max > 0 ? Math.min(100, Math.max(0, (value / max) * 100)) : 0;
  const bar = { primary: "bg-blue-600", green: "bg-green-600", yellow: "bg-yellow-500", red: "bg-red-600" }[tone];
  return (
    <div role="progressbar" aria-label={label} aria-valuenow={Math.round(pct)} aria-valuemin={0} aria-valuemax={100} className="h-2 w-full overflow-hidden rounded-full bg-slate-200">
      <div className={cx("h-2 rounded-full", bar)} style={{ width: `${pct}%` }} />
    </div>
  );
}
