"use client";

import Link from "next/link";
import type { Route } from "next";
import { usePathname } from "next/navigation";
import { useState, type ReactNode } from "react";
import { Check } from "lucide-react";

/** Tailwind Plus "Tabs with underline and badges". */
export function Tabs({ tabs, value, onChange }: { tabs: { key: string; label: string; n?: number }[]; value: string; onChange: (k: string) => void }) {
  return (
    <div className="border-b border-gray-200 px-[var(--card-p)]">
      <nav role="tablist" aria-label="Sections" className="-mb-px flex gap-x-6 overflow-x-auto no-scrollbar">
        {tabs.map((t) => {
          const active = value === t.key;
          return (
            <button
              key={t.key}
              role="tab"
              aria-selected={active}
              onClick={() => onChange(t.key)}
              className={`flex shrink-0 items-center gap-x-2 whitespace-nowrap border-b-2 px-1 py-3.5 text-sm font-medium transition-colors ${
                active ? "border-brand-600 text-brand-700" : "border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700"
              }`}
            >
              {t.label}
              {t.n !== undefined ? (
                <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${active ? "bg-brand-100 text-brand-700" : "bg-gray-100 text-gray-700"}`}>
                  {t.n}
                </span>
              ) : null}
            </button>
          );
        })}
      </nav>
    </div>
  );
}

/** Kept for compatibility; the sidebar shell renders its own nav links. */
export function NavLink({ href, children }: { href: Route; children: ReactNode }) {
  const path = usePathname();
  const isPortalRoot = href === "/" || href === "/console" || href === "/workspace" || href === "/app";
  const active = isPortalRoot ? path === href : path === href || path.startsWith(`${href}/`);
  return (
    <Link
      href={href}
      aria-current={active ? "page" : undefined}
      className={`inline-flex shrink-0 items-center whitespace-nowrap rounded-md px-3 py-1.5 text-sm font-semibold transition-colors ${
        active ? "bg-gray-100 text-brand-700" : "text-gray-600 hover:bg-gray-100 hover:text-gray-900"
      }`}
    >
      {children}
    </Link>
  );
}

/** Tailwind Plus "Progress steps" (simple, with completed check marks). */
export function Stepper({ steps, current }: { steps: string[]; current: number }) {
  return (
    <nav aria-label="Progress">
      <ol role="list" className="flex flex-wrap items-center gap-x-1 gap-y-2">
        {steps.map((s, i) => {
          const done = i < current, now = i === current;
          return (
            <li key={s} className="flex items-center gap-x-1.5">
              <span
                aria-hidden="true"
                className={`inline-flex size-6 items-center justify-center rounded-full text-[11px] font-semibold ${
                  done ? "bg-brand-600 text-white" : now ? "border-2 border-brand-600 bg-white text-brand-700" : "border-2 border-gray-300 bg-white text-gray-500"
                }`}
              >
                {done ? <Check className="size-3.5" /> : i}
              </span>
              <span className={`text-xs ${now ? "font-semibold text-brand-700" : done ? "text-gray-700" : "text-gray-400"}`} aria-current={now ? "step" : undefined}>
                {s}
              </span>
              {i < steps.length - 1 ? <span aria-hidden="true" className="mx-1 h-px w-4 bg-gray-300" /> : null}
            </li>
          );
        })}
      </ol>
    </nav>
  );
}

export function DensityToggle() {
  const [compact, setCompact] = useState(false);
  return (
    <button
      type="button"
      onClick={() => {
        const next = !compact;
        setCompact(next);
        document.documentElement.setAttribute("data-density", next ? "compact" : "comfortable");
      }}
      aria-pressed={compact}
      className="hidden items-center gap-1.5 rounded-md bg-white px-2.5 py-1.5 text-xs font-semibold text-gray-700 shadow-xs ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:inline-flex"
      title="Toggle row density"
    >
      <span aria-hidden="true" className={`size-1.5 rounded-full ${compact ? "bg-brand-600" : "bg-gray-400"}`} />
      <span>{compact ? "Compact" : "Comfortable"}</span>
    </button>
  );
}
