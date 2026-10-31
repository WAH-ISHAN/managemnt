"use client";

export function InfrastructureRefresh() {
  return <button type="button" onClick={() => window.location.reload()} className="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Refresh telemetry</button>;
}
