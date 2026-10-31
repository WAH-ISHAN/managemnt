"use client";
import React, { createContext, useContext, useState, useCallback } from "react";

export type ToastType = "success" | "error" | "info" | "warning";

export interface Toast {
  id: string;
  type: ToastType;
  title: string;
  message?: string;
  duration?: number;
}

interface ToastContextType {
  toasts: Toast[];
  addToast: (toast: Omit<Toast, "id">) => void;
  removeToast: (id: string) => void;
  success: (title: string, message?: string, duration?: number) => void;
  error: (title: string, message?: string, duration?: number) => void;
  info: (title: string, message?: string, duration?: number) => void;
  warning: (title: string, message?: string, duration?: number) => void;
}

const ToastContext = createContext<ToastContextType | undefined>(undefined);

export function ToastProvider({ children }: { children: React.ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([]);

  const removeToast = useCallback((id: string) => {
    setToasts((prev) => prev.filter((t) => t.id !== id));
  }, []);

  const addToast = useCallback(
    ({ type, title, message, duration = 4000 }: Omit<Toast, "id">) => {
      const id = Math.random().toString(36).substring(2, 9);
      const newToast: Toast = { id, type, title, message, duration };
      setToasts((prev) => [...prev, newToast]);

      if (duration > 0) {
        setTimeout(() => {
          removeToast(id);
        }, duration);
      }
    },
    [removeToast]
  );

  const success = useCallback(
    (title: string, message?: string, duration?: number) =>
      addToast({ type: "success", title, message, duration }),
    [addToast]
  );

  const error = useCallback(
    (title: string, message?: string, duration?: number) =>
      addToast({ type: "error", title, message, duration }),
    [addToast]
  );

  const info = useCallback(
    (title: string, message?: string, duration?: number) =>
      addToast({ type: "info", title, message, duration }),
    [addToast]
  );

  const warning = useCallback(
    (title: string, message?: string, duration?: number) =>
      addToast({ type: "warning", title, message, duration }),
    [addToast]
  );

  return (
    <ToastContext.Provider
      value={{ toasts, addToast, removeToast, success, error, info, warning }}
    >
      {children}
      <ToasterContainer toasts={toasts} onRemove={removeToast} />
    </ToastContext.Provider>
  );
}

export function useToast() {
  const context = useContext(ToastContext);
  if (!context) {
    throw new Error("useToast must be used within a ToastProvider");
  }
  return context;
}

function ToasterContainer({
  toasts,
  onRemove,
}: {
  toasts: Toast[];
  onRemove: (id: string) => void;
}) {
  if (toasts.length === 0) return null;

  return (
    <aside
      aria-live="polite"
      className="fixed bottom-6 right-6 z-99999 flex flex-col gap-3 max-w-sm sm:max-w-md w-full pointer-events-none px-4 sm:px-0"
    >
      {toasts.map((toast) => (
        <div
          key={toast.id}
          className={`pointer-events-auto bg-white border rounded-2xl shadow-2xl p-4 sm:p-5 transition-all transform animate-fadeIn duration-300 flex items-start gap-3.5 ${
            toast.type === "success"
              ? "border-[#BFDBFE] bg-linear-to-r from-white via-white to-blue-50/40"
              : toast.type === "error"
              ? "border-slate-300 bg-linear-to-r from-white via-white to-slate-100"
              : toast.type === "warning"
              ? "border-slate-300 bg-white"
              : "border-blue-200 bg-white"
          }`}
        >
          {/* Status Indicator Icon (Zero emojis) */}
          <div className="shrink-0 pt-0.5">
            {toast.type === "success" && (
              <div className="w-7 h-7 rounded-full bg-[#EFF6FF] border border-[#BFDBFE] text-[#0055B8] flex items-center justify-center font-black text-xs">
                <svg className="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                  <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                </svg>
              </div>
            )}
            {toast.type === "error" && (
              <div className="w-7 h-7 rounded-full bg-slate-100 border border-slate-300 text-slate-900 flex items-center justify-center font-black text-xs">
                <svg className="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                  <path fillRule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clipRule="evenodd" />
                </svg>
              </div>
            )}
            {toast.type === "info" && (
              <div className="w-7 h-7 rounded-full bg-[#EFF6FF] border border-blue-200 text-[#0055B8] flex items-center justify-center font-black text-xs">
                <svg className="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                  <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clipRule="evenodd" />
                </svg>
              </div>
            )}
            {toast.type === "warning" && (
              <div className="w-7 h-7 rounded-full bg-slate-100 border border-slate-300 text-slate-900 flex items-center justify-center font-black text-xs">
                <svg className="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                  <path fillRule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clipRule="evenodd" />
                </svg>
              </div>
            )}
          </div>

          {/* Content */}
          <div className="flex-1 min-w-0">
            <h4 className="text-xs sm:text-sm font-black text-[#0F172A] tracking-tight">
              {toast.title}
            </h4>
            {toast.message && (
              <p className="text-xs text-slate-600 font-normal mt-0.5 leading-relaxed">
                {toast.message}
              </p>
            )}
          </div>

          {/* Dismiss button */}
          <button
            type="button"
            onClick={() => onRemove(toast.id)}
            className="shrink-0 text-slate-400 hover:text-slate-700 w-5 h-5 flex items-center justify-center rounded-lg hover:bg-slate-100 transition-colors text-xs font-bold cursor-pointer"
          >
            &times;
          </button>
        </div>
      ))}
    </aside>
  );
}
