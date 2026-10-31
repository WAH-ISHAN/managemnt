"use client";

import { useEffect, useId, useRef, useSyncExternalStore, type ReactNode } from "react";
import { createPortal } from "react-dom";
import { AlertTriangle, CheckCircle2, X } from "lucide-react";
import { Button } from "./controls";

const emptySubscribe = () => () => {};

/**
 * Tailwind Plus modal dialog. Portalled so a table's overflow never clips it.
 * Escape, scrim click, focus trap, initial focus and focus restore are kept;
 * the title is wired through aria-labelledby.
 */
export function Modal({
  open, onClose, title, children, footer, width = 560,
}: { open: boolean; onClose: () => void; title: string; children: ReactNode; footer?: ReactNode; width?: number }) {
  const ref = useRef<HTMLDivElement>(null);
  const restore = useRef<HTMLElement | null>(null);
  const titleId = useId();
  const mounted = useSyncExternalStore(emptySubscribe, () => true, () => false);

  useEffect(() => {
    if (!open) return;
    restore.current = document.activeElement as HTMLElement;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") onClose();
      if (e.key === "Tab" && ref.current) {
        const f = ref.current.querySelectorAll<HTMLElement>('button,[href],input,select,textarea,[tabindex]:not([tabindex="-1"])');
        if (!f.length) return;
        const first = f[0], last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    };
    document.addEventListener("keydown", onKey);
    document.body.style.overflow = "hidden";
    setTimeout(() => ref.current?.querySelector<HTMLElement>("input,select,textarea,button:not([aria-label='Close'])")?.focus(), 20);
    return () => {
      document.removeEventListener("keydown", onKey);
      document.body.style.overflow = "";
      restore.current?.focus();
    };
  }, [open, onClose]);

  if (!mounted || !open) return null;

  return createPortal(
    <div className="relative z-50" role="dialog" aria-modal="true" aria-labelledby={titleId}>
      <div className="fixed inset-0 bg-gray-500/75 transition-opacity" onClick={onClose} aria-hidden="true" />
      <div className="fixed inset-0 z-50 w-screen overflow-y-auto">
        <div className="flex min-h-full items-end justify-center p-0 text-center sm:items-center sm:p-4">
          <div
            ref={ref}
            style={{ maxWidth: width }}
            className="relative flex w-full transform flex-col overflow-hidden rounded-t-lg bg-white text-left shadow-xl transition-all animate-slideUp sm:my-8 sm:rounded-lg sm:animate-none max-h-[92dvh] sm:max-h-[85vh]"
          >
            <div className="flex items-center justify-between border-b border-gray-200 px-5 py-4">
              <h2 id={titleId} className="text-base font-semibold text-gray-900">{title}</h2>
              <button type="button" onClick={onClose} aria-label="Close" className="-m-2 rounded-md p-2 text-gray-400 hover:text-gray-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
                <X aria-hidden="true" className="size-5" />
              </button>
            </div>
            <div className="custom-scrollbar overflow-y-auto px-5 py-4 text-sm text-gray-700">{children}</div>
            {footer ? (
              <div className="flex flex-col-reverse gap-2 border-t border-gray-200 bg-gray-50 px-5 py-3 pb-[calc(12px+env(safe-area-inset-bottom,0px))] sm:flex-row sm:justify-end sm:pb-3">
                {footer}
              </div>
            ) : null}
          </div>
        </div>
      </div>
    </div>,
    document.body,
  );
}

export function ConfirmDialog({
  open, onClose, onConfirm, title, body, confirmLabel = "Confirm", danger,
}: { open: boolean; onClose: () => void; onConfirm: () => void; title: string; body: ReactNode; confirmLabel?: string; danger?: boolean }) {
  return (
    <Modal open={open} onClose={onClose} title={title} width={440}
      footer={<>
        <Button variant="secondary" onClick={onClose}>Cancel</Button>
        <Button variant={danger ? "danger" : "primary"} onClick={() => { onConfirm(); onClose(); }}>{confirmLabel}</Button>
      </>}>
      {body}
    </Modal>
  );
}

/**
 * Tailwind Plus notification (top-right panel with icon and dismiss).
 * Announced politely to assistive tech; auto-dismisses. API unchanged.
 */
export function Toast({ message, tone = "ok", onDone }: { message: string; tone?: "ok" | "bad"; onDone?: () => void }) {
  const mounted = useSyncExternalStore(emptySubscribe, () => true, () => false);
  useEffect(() => { const t = setTimeout(() => onDone?.(), 5000); return () => clearTimeout(t); }, [onDone]);
  if (!mounted) return null;
  const Icon = tone === "ok" ? CheckCircle2 : AlertTriangle;
  return createPortal(
    <div aria-live="polite" role="status" className="pointer-events-none fixed inset-0 z-[60] flex items-end px-4 py-6 sm:items-start sm:p-6">
      <div className="flex w-full flex-col items-center space-y-4 sm:items-end">
        <div className="pointer-events-auto w-full max-w-sm overflow-hidden rounded-lg bg-white shadow-lg ring-1 ring-black/5 animate-fadeIn">
          <div className="p-4">
            <div className="flex items-start">
              <div className="shrink-0">
                <Icon aria-hidden="true" className={`size-6 ${tone === "ok" ? "text-green-500" : "text-red-500"}`} />
              </div>
              <div className="ml-3 w-0 flex-1 pt-0.5">
                <p className="text-sm font-medium text-gray-900">{message}</p>
              </div>
              <div className="ml-4 flex shrink-0">
                <button type="button" onClick={() => onDone?.()} className="inline-flex rounded-md bg-white text-gray-400 hover:text-gray-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600" aria-label="Dismiss">
                  <X aria-hidden="true" className="size-5" />
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>,
    document.body,
  );
}
