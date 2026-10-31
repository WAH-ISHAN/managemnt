"use client";

/**
 * Admin console interactive components — Tailwind Plus v4 patterns on Headless UI.
 * Scoped to /console. Headless UI owns focus trapping, Escape, scroll lock and ARIA.
 */
import { useEffect, useMemo, useState, type ButtonHTMLAttributes, type ReactNode } from "react";
import { useRouter } from "next/navigation";
import type { Route } from "next";
import {
  Combobox, ComboboxInput, ComboboxOption, ComboboxOptions,
  Dialog, DialogBackdrop, Label, DialogPanel, DialogTitle,
  Listbox, ListboxButton, ListboxOption, ListboxOptions,
  Switch,
} from "@headlessui/react";
import { AlertTriangle, Check, ChevronsUpDown, CornerDownLeft, Search, X, type LucideIcon } from "lucide-react";
import { buttonClass, inputClass, type ButtonSize, type ButtonVariant } from "./ui";

const cx = (...c: (string | false | null | undefined)[]) => c.filter(Boolean).join(" ");

/* ----------------------------------------------------------------- Button */

export function Button({ variant = "primary", size = "md", busy = false, className = "", disabled, children, ...rest }:
  ButtonHTMLAttributes<HTMLButtonElement> & { variant?: ButtonVariant; size?: ButtonSize; busy?: boolean }) {
  return (
    <button type="button" {...rest} disabled={disabled || busy} aria-busy={busy || undefined} className={buttonClass(variant, size, className)}>
      {busy ? <span aria-hidden="true" className="-ml-0.5 size-4 animate-spin rounded-full border-2 border-current border-r-transparent" /> : null}
      {children}
    </button>
  );
}

/* ------------------------------------------------------------ Form fields */

export { inputClass };

export function Field({ label, htmlFor, hint, error, children }: { label: string; htmlFor?: string; hint?: ReactNode; error?: string; children: ReactNode }) {
  return (
    <div>
      <label htmlFor={htmlFor} className="block text-sm/6 font-medium text-slate-900">{label}</label>
      <div className="mt-2">{children}</div>
      {error ? <p className="mt-2 text-sm text-red-600">{error}</p> : hint ? <p className="mt-2 text-sm text-slate-500">{hint}</p> : null}
    </div>
  );
}

export type Option<V extends string> = { value: V; label: string; description?: string };

/** Tailwind Plus "Select menu (simple custom)". Drop-in for a controlled native <select>. */
export function Select<V extends string>({ value, onChange, options, label, placeholder = "Select…", disabled, className = "", "aria-label": ariaLabel }: {
  value: V; onChange: (v: V) => void; options: Option<V>[]; label?: string; placeholder?: string; disabled?: boolean; className?: string; "aria-label"?: string;
}) {
  const current = options.find((o) => o.value === value);
  return (
    <Listbox value={value} onChange={onChange} disabled={disabled}>
      <div className={className}>
        {label ? <Label className="block text-sm/6 font-medium text-slate-900">{label}</Label> : null}
        <div className={cx("relative", label && "mt-2")}>
          <ListboxButton aria-label={ariaLabel} className="grid w-full cursor-default grid-cols-1 rounded-md bg-white py-1.5 pr-2 pl-3 text-left text-slate-900 outline-1 -outline-offset-1 outline-slate-300 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-blue-600 disabled:bg-slate-50 sm:text-sm/6">
            <span className={cx("col-start-1 row-start-1 truncate pr-6", !current && "text-slate-400")}>{current?.label ?? placeholder}</span>
            <ChevronsUpDown aria-hidden="true" className="col-start-1 row-start-1 size-4 self-center justify-self-end text-slate-500" />
          </ListboxButton>
          <ListboxOptions transition anchor="bottom start"
            className="z-50 mt-1 max-h-60 w-(--button-width) overflow-auto rounded-md bg-white py-1 text-base shadow-lg ring-1 ring-black/5 focus:outline-hidden data-leave:transition data-leave:duration-100 data-leave:ease-in data-closed:data-leave:opacity-0 sm:text-sm">
            {options.map((o) => (
              <ListboxOption key={o.value} value={o.value}
                className="group relative cursor-default py-2 pr-9 pl-3 text-slate-900 select-none data-focus:bg-blue-600 data-focus:text-white data-focus:outline-hidden">
                <span className="block truncate font-normal group-data-selected:font-semibold">{o.label}</span>
                {o.description ? <span className="block truncate text-xs text-slate-500 group-data-focus:text-blue-100">{o.description}</span> : null}
                <span className="absolute inset-y-0 right-0 hidden items-center pr-3 text-blue-600 group-data-focus:text-white group-data-selected:flex">
                  <Check aria-hidden="true" className="size-4" />
                </span>
              </ListboxOption>
            ))}
          </ListboxOptions>
        </div>
      </div>
    </Listbox>
  );
}

/** Tailwind Plus "Toggle with label". */
export function Toggle({ checked, onChange, label, description, disabled }: {
  checked: boolean; onChange: (v: boolean) => void; label: string; description?: string; disabled?: boolean;
}) {
  return (
    <div className="flex items-center justify-between gap-x-6">
      <span className="flex grow flex-col">
        <span className="text-sm/6 font-medium text-slate-900">{label}</span>
        {description ? <span className="text-sm text-slate-500">{description}</span> : null}
      </span>
      <Switch checked={checked} onChange={onChange} disabled={disabled} aria-label={label}
        className="group relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent bg-slate-200 transition-colors duration-200 ease-in-out focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 disabled:cursor-not-allowed disabled:opacity-50 data-checked:bg-blue-600">
        <span aria-hidden="true" className="pointer-events-none inline-block size-5 transform rounded-full bg-white shadow-sm ring-0 transition duration-200 ease-in-out group-data-checked:translate-x-5" />
      </Switch>
    </div>
  );
}

/* ---------------------------------------------------------------- Overlays */

const SIZES = { sm: "sm:max-w-sm", md: "sm:max-w-lg", lg: "sm:max-w-2xl", xl: "sm:max-w-4xl" } as const;

/** Tailwind Plus modal dialog. */
export function Modal({ open, onClose, title, description, children, footer, size = "md" }: {
  open: boolean; onClose: () => void; title: string; description?: ReactNode; children?: ReactNode; footer?: ReactNode; size?: keyof typeof SIZES;
}) {
  return (
    <Dialog open={open} onClose={onClose} className="relative z-50">
      <DialogBackdrop transition className="fixed inset-0 bg-slate-500/75 transition-opacity data-closed:opacity-0 data-enter:duration-300 data-enter:ease-out data-leave:duration-200 data-leave:ease-in" />
      <div className="fixed inset-0 z-50 w-screen overflow-y-auto">
        <div className="flex min-h-full items-end justify-center p-4 sm:items-center sm:p-0">
          <DialogPanel transition className={cx(
            "relative w-full transform overflow-hidden rounded-lg bg-white text-left shadow-xl transition-all sm:my-8",
            "data-closed:translate-y-4 data-closed:opacity-0 data-enter:duration-300 data-enter:ease-out data-leave:duration-200 data-leave:ease-in sm:data-closed:translate-y-0 sm:data-closed:scale-95",
            SIZES[size],
          )}>
            <div className="flex items-start justify-between gap-4 border-b border-slate-200 px-4 py-4 sm:px-6">
              <div className="min-w-0">
                <DialogTitle className="text-base font-semibold text-slate-900">{title}</DialogTitle>
                {description ? <p className="mt-1 text-sm text-slate-500">{description}</p> : null}
              </div>
              <button type="button" onClick={onClose} className="-m-1.5 rounded-md p-1.5 text-slate-400 hover:text-slate-500 focus-visible:outline-2 focus-visible:outline-blue-600">
                <span className="sr-only">Close</span><X aria-hidden="true" className="size-5" />
              </button>
            </div>
            {children ? <div className="max-h-[70dvh] overflow-y-auto px-4 py-5 sm:px-6">{children}</div> : null}
            {footer ? <div className="flex flex-col-reverse gap-3 bg-slate-50 px-4 py-3 sm:flex-row sm:justify-end sm:px-6">{footer}</div> : null}
          </DialogPanel>
        </div>
      </div>
    </Dialog>
  );
}

/** Tailwind Plus slide-over panel. */
export function Drawer({ open, onClose, title, description, children, footer }: {
  open: boolean; onClose: () => void; title: string; description?: ReactNode; children: ReactNode; footer?: ReactNode;
}) {
  return (
    <Dialog open={open} onClose={onClose} className="relative z-50">
      <DialogBackdrop transition className="fixed inset-0 bg-slate-500/75 transition-opacity duration-500 ease-in-out data-closed:opacity-0" />
      <div className="fixed inset-0 overflow-hidden">
        <div className="pointer-events-none fixed inset-y-0 right-0 flex max-w-full pl-10 sm:pl-16">
          <DialogPanel transition className="pointer-events-auto w-screen max-w-md transform transition duration-500 ease-in-out data-closed:translate-x-full sm:duration-700">
            <div className="flex h-full flex-col bg-white shadow-xl">
              <div className="bg-blue-700 px-4 py-6 sm:px-6">
                <div className="flex items-start justify-between gap-4">
                  <DialogTitle className="text-base font-semibold text-white">{title}</DialogTitle>
                  <button type="button" onClick={onClose} className="rounded-md text-blue-200 hover:text-white focus-visible:outline-2 focus-visible:outline-white">
                    <span className="sr-only">Close panel</span><X aria-hidden="true" className="size-6" />
                  </button>
                </div>
                {description ? <p className="mt-1 text-sm text-blue-200">{description}</p> : null}
              </div>
              <div className="flex-1 overflow-y-auto px-4 py-6 sm:px-6">{children}</div>
              {footer ? <div className="flex shrink-0 justify-end gap-3 border-t border-slate-200 px-4 py-4">{footer}</div> : null}
            </div>
          </DialogPanel>
        </div>
      </div>
    </Dialog>
  );
}

/** Replacement for window.confirm(): Tailwind Plus "simple alert" dialog. */
export function ConfirmDialog({ open, onClose, onConfirm, title, message, confirmLabel = "Confirm", destructive = true, busy = false }: {
  open: boolean; onClose: () => void; onConfirm: () => void; title: string; message: ReactNode;
  confirmLabel?: string; destructive?: boolean; busy?: boolean;
}) {
  return (
    <Dialog open={open} onClose={busy ? () => {} : onClose} className="relative z-50">
      <DialogBackdrop transition className="fixed inset-0 bg-slate-500/75 transition-opacity data-closed:opacity-0 data-enter:duration-300 data-leave:duration-200" />
      <div className="fixed inset-0 z-50 w-screen overflow-y-auto">
        <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
          <DialogPanel transition className="relative transform overflow-hidden rounded-lg bg-white px-4 pt-5 pb-4 text-left shadow-xl transition-all data-closed:translate-y-4 data-closed:opacity-0 data-enter:duration-300 data-leave:duration-200 sm:my-8 sm:w-full sm:max-w-lg sm:p-6 sm:data-closed:translate-y-0 sm:data-closed:scale-95">
            <div className="sm:flex sm:items-start">
              <div className={cx("mx-auto flex size-12 shrink-0 items-center justify-center rounded-full sm:mx-0 sm:size-10", destructive ? "bg-red-100" : "bg-blue-100")}>
                <AlertTriangle aria-hidden="true" className={cx("size-6", destructive ? "text-red-600" : "text-blue-600")} />
              </div>
              <div className="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                <DialogTitle className="text-base font-semibold text-slate-900">{title}</DialogTitle>
                <div className="mt-2 text-sm text-slate-500">{message}</div>
              </div>
            </div>
            <div className="mt-5 flex flex-col-reverse gap-3 sm:mt-4 sm:flex-row sm:justify-end">
              <Button variant="secondary" onClick={onClose} disabled={busy}>Cancel</Button>
              <Button variant={destructive ? "danger" : "primary"} onClick={onConfirm} busy={busy}>{confirmLabel}</Button>
            </div>
          </DialogPanel>
        </div>
      </div>
    </Dialog>
  );
}

/* --------------------------------------------------------- Command palette */

export type CommandItem = { href: Route; label: string; group: string; icon?: LucideIcon };

/** Tailwind Plus command palette — jump to any page in the current portal. Opens with ⌘K / Ctrl+K. */
export function CommandPalette({ items }: { items: CommandItem[] }) {
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === "k") { e.preventDefault(); setOpen((o) => !o); }
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, []);

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    return q ? items.filter((i) => i.label.toLowerCase().includes(q) || i.group.toLowerCase().includes(q)) : items;
  }, [items, query]);

  const close = () => { setOpen(false); setQuery(""); };

  return (
    <>
      <button type="button" onClick={() => setOpen(true)}
        className="flex w-full max-w-sm items-center gap-x-2 rounded-md bg-slate-50 px-3 py-1.5 text-sm text-slate-500 ring-1 ring-inset ring-slate-200 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-blue-600">
        <Search aria-hidden="true" className="size-4 shrink-0 text-slate-400" />
        <span className="flex-1 truncate text-left">Jump to…</span>
        <kbd className="hidden rounded-sm border border-slate-200 bg-white px-1.5 font-sans text-xs text-slate-400 sm:inline">⌘K</kbd>
      </button>

      <Dialog open={open} onClose={close} className="relative z-50">
        <DialogBackdrop transition className="fixed inset-0 bg-slate-500/25 transition-opacity data-closed:opacity-0 data-enter:duration-300 data-leave:duration-200" />
        <div className="fixed inset-0 z-50 w-screen overflow-y-auto p-4 sm:p-6 md:p-20">
          <DialogPanel transition className="mx-auto max-w-xl transform divide-y divide-slate-100 overflow-hidden rounded-xl bg-white shadow-2xl ring-1 ring-black/5 transition-all data-closed:scale-95 data-closed:opacity-0 data-enter:duration-300 data-leave:duration-200">
            <DialogTitle className="sr-only">Jump to a page</DialogTitle>
            <Combobox<CommandItem | null> onChange={(item) => { if (item) { close(); router.push(item.href); } }}>
              <div className="grid grid-cols-1">
                <ComboboxInput autoFocus
                  className="col-start-1 row-start-1 h-12 w-full pr-4 pl-11 text-base text-slate-900 outline-hidden placeholder:text-slate-400 sm:text-sm"
                  placeholder="Search pages…" onChange={(e) => setQuery(e.target.value)} onBlur={() => setQuery("")} />
                <Search aria-hidden="true" className="pointer-events-none col-start-1 row-start-1 ml-4 size-5 self-center text-slate-400" />
              </div>
              {filtered.length > 0 ? (
                <ComboboxOptions static className="max-h-80 scroll-py-2 overflow-y-auto py-2 text-sm text-slate-800">
                  {filtered.map((item) => {
                    const Icon = item.icon;
                    return (
                      <ComboboxOption key={item.href} value={item}
                        className="group flex cursor-default items-center gap-x-3 px-4 py-2 select-none data-focus:bg-blue-600 data-focus:text-white data-focus:outline-hidden">
                        {Icon ? <Icon aria-hidden="true" className="size-5 shrink-0 text-slate-400 group-data-focus:text-white" /> : null}
                        <span className="flex-auto truncate">{item.label}</span>
                        <span className="text-xs text-slate-400 group-data-focus:text-blue-100">{item.group}</span>
                        <CornerDownLeft aria-hidden="true" className="hidden size-4 group-data-focus:block" />
                      </ComboboxOption>
                    );
                  })}
                </ComboboxOptions>
              ) : (
                <p className="p-4 text-sm text-slate-500">No pages match “{query}”.</p>
              )}
            </Combobox>
          </DialogPanel>
        </div>
      </Dialog>
    </>
  );
}
