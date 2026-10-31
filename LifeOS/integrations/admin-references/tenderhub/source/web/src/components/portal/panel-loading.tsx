import { LoaderCircle } from "lucide-react";

/** A real pending state; no substitute records or metrics while data loads. */
export default function PanelLoading() {
  return (
    <section
      role="status"
      aria-live="polite"
      aria-busy="true"
      className="flex min-h-88 flex-col items-center justify-center gap-3 rounded-lg bg-white px-6 py-12 text-center shadow-xs ring-1 ring-gray-900/5"
    >
      <LoaderCircle aria-hidden="true" className="size-7 text-primary-600 motion-safe:animate-spin" />
      <p className="text-sm font-semibold text-gray-900">Loading panel</p>
      <p className="max-w-sm text-sm text-gray-500">Fetching your latest information. You can continue using the menu.</p>
    </section>
  );
}
