"use client";
/** Client, because Server Components cannot pass handlers.
 *  Tailwind Plus buttons and stats. Export names/props unchanged. */
import type { ButtonHTMLAttributes, ReactNode } from "react";
import Link from "next/link";
import type { Route } from "next";

type Variant = "primary" | "secondary" | "ghost" | "danger";

const V: Record<Variant, string> = {
  primary: "bg-blue-600 text-white hover:bg-blue-500 focus-visible:outline-blue-600",
  secondary: "border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 focus-visible:outline-blue-600",
  ghost: "text-slate-600 hover:bg-slate-100",
  danger: "border border-red-200 bg-white text-red-600 hover:bg-red-50 focus-visible:outline-red-600",
};

const BASE =
  "inline-flex shrink-0 items-center justify-center gap-2 whitespace-nowrap font-medium transition duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-50 cursor-pointer";

function sizeClass(size: "sm" | "md") {
  return size === "sm" ? "h-8 rounded-sm px-3 text-sm" : "h-10 rounded-md px-4 text-sm";
}

export function Button({
  children,
  variant = "primary",
  size = "md",
  className = "",
  busy = false,
  disabled,
  ...rest
}: ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: Variant;
  size?: "sm" | "md";
  busy?: boolean;
}) {
  const isDisabled = disabled || busy;
  return (
    <button
      {...rest}
      disabled={isDisabled}
      aria-busy={busy ? "true" : undefined}
      className={`${BASE} ${V[variant]} ${sizeClass(size)} ${className}`}
    >
      {busy && (
        <svg className="-ml-0.5 size-3.5 shrink-0 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
          <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
          <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
      )}
      {children}
    </button>
  );
}

export function LinkButton({
  href,
  children,
  variant = "primary",
  size = "md",
  className = "",
}: {
  href: Route;
  children: ReactNode;
  variant?: Variant;
  size?: "sm" | "md";
  className?: string;
}) {
  return (
    <Link href={href} className={`${BASE} ${V[variant]} ${sizeClass(size)} ${className}`}>
      {children}
    </Link>
  );
}

/** Tailwind Plus "Stats" card. Values are whatever the caller passes — never invented here. */
export function Kpi({
  label,
  value,
  sub,
  tone = "neutral",
}: {
  label: string;
  value: ReactNode;
  sub?: ReactNode;
  tone?: "neutral" | "ok" | "warn" | "bad";
}) {
  const c = {
    neutral: "text-slate-900",
    ok: "text-emerald-600",
    warn: "text-amber-600",
    bad: "text-rose-600",
  }[tone];

  const dot = {
    neutral: "bg-slate-400",
    ok: "bg-emerald-500",
    warn: "bg-amber-500",
    bad: "bg-rose-500",
  }[tone];

  return (
    <div className="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs hover:shadow-xs hover:border-slate-300 transition-all flex flex-col justify-between h-full group">
      <div>
        <div className="flex items-center justify-between gap-2">
          <p className="text-xs font-bold uppercase tracking-wider text-slate-500 truncate">{label}</p>
          {tone !== "neutral" && <span className={`size-2 rounded-full ${dot}`} />}
        </div>
        <p className={`mt-2 font-mono text-3xl font-black leading-none tracking-tight tabular ${c}`}>
          {value}
        </p>
      </div>
      {sub ? (
        <div className="mt-3 pt-2.5 border-t border-slate-100 flex items-center gap-1.5">
          <p className="text-[11px] font-medium text-slate-400 truncate">{sub}</p>
        </div>
      ) : null}
    </div>
  );
}
