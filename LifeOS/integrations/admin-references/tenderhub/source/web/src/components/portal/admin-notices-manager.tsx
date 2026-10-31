"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { DataTable, type Column } from "@/components/ds/data-table";
import { Alert, Badge } from "@/components/console/ui";
import { Button } from "@/components/console/interactive";
import { AdminNoticeForm } from "@/components/portal/admin-notice-form";
import { date } from "@/lib/format";

type QueueRow = { id: number; title: string; reference?: string | null; source?: string | null; missing?: string[]; closing_at?: string | null };
type CatalogueRow = {
  id: number;
  slug?: string | null;
  title: string;
  reference?: string | null;
  authority_name?: string | null;
  organization?: string | null;
  /** Public API field names (NoticeTransformer). */
  buyer?: string | null;
  raw_organization?: string | null;
  status: string;
  documents_count?: number | null;
  closing_at?: string | null;
};
interface AdminNoticesManagerProps {
  initialQueue: QueueRow[];
  initialNotices: CatalogueRow[];
}

export function AdminNoticesManager({ initialQueue, initialNotices }: AdminNoticesManagerProps) {
  const router = useRouter();
  const [activeTab, setActiveTab] = useState<"queue" | "catalogue" | "create">("queue");
  const queue = initialQueue;
  const notices = initialNotices;
  const [busyId, setBusyId] = useState<number | null>(null);
  const [toast, setToast] = useState<{ m: string; t: "ok" | "bad" } | null>(null);

  async function handlePublish(id: number) {
    setBusyId(id);
    try {
      const res = await fetch(`/api/admin/notices/${id}/publish`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
      });
      const data = await res.json().catch(() => ({}));
      if (res.ok) {
        setToast({ m: `Notice #${id} published successfully!`, t: "ok" });
        router.refresh();
      } else {
        setToast({ m: data.detail || "Could not publish notice. Ensure a closing date is set.", t: "bad" });
      }
    } catch {
      setToast({ m: "Network error while publishing notice.", t: "bad" });
    } finally {
      setBusyId(null);
    }
  }

  const queueColumns: Column<QueueRow>[] = [
    {
      key: "title",
      header: "Notice Title & Reference",
      sortable: true,
      sortValue: (r) => r.title,
      cell: (r) => (
        <div>
          <span className="font-semibold text-gray-900 line-clamp-1">{r.title}</span>
          <div className="font-mono text-xs text-gray-500 mt-0.5">
            {r.reference || "No Ref"} · Source: {r.source || "—"}
          </div>
        </div>
      ),
    },
    {
      key: "missing",
      header: "Missing Attributes",
      width: "240px",
      cell: (r) => (
        <div className="flex flex-wrap gap-1">
          {Array.isArray(r.missing) && r.missing.length > 0 ? (
            r.missing.map((m: string) => (
              <Badge key={m} tone="yellow">
                missing {m}
              </Badge>
            ))
          ) : (
            <Badge tone="green">ready</Badge>
          )}
        </div>
      ),
    },
    {
      key: "closing",
      header: "Closing Date",
      width: "140px",
      sortable: true,
      sortValue: (r) => r.closing_at || "",
      cell: (r) => (
        <span className="font-mono text-xs text-gray-600">
          {r.closing_at ? date(r.closing_at) : <span className="text-red-600 font-semibold">Missing</span>}
        </span>
      ),
    },
    {
      key: "actions",
      header: "Action",
      width: "120px",
      align: "right",
      cell: (r) => (
        <Button
          size="sm"
          variant="primary"
          disabled={!r.closing_at}
          busy={busyId === r.id}
          onClick={() => handlePublish(r.id)}
        >
          {busyId === r.id ? "Publishing…" : "Publish"}
        </Button>
      ),
    },
  ];

  const catalogueColumns: Column<CatalogueRow>[] = [
    {
      key: "title",
      header: "Notice",
      sortable: true,
      sortValue: (r) => r.title,
      cell: (r) => (
        <div>
          <a
            href={`/tenders/${r.slug || r.id}`}
            target="_blank"
            rel="noopener noreferrer"
            className="font-semibold text-gray-900 hover:text-primary-600 line-clamp-1"
          >
            {r.title}
          </a>
          <div className="font-mono text-xs text-gray-500 mt-0.5">
            {r.reference || "No Ref"} · {r.authority_name || r.organization || r.buyer || r.raw_organization || "—"}
          </div>
        </div>
      ),
    },
    {
      key: "status",
      header: "Status",
      width: "120px",
      cell: (r) => (
        <Badge tone={r.status === "published" ? "green" : r.status === "unverified" ? "yellow" : "gray"}>
          {r.status}
        </Badge>
      ),
    },
    {
      key: "docs",
      header: "Documents",
      width: "110px",
      sortable: true,
      sortValue: (r) => r.documents_count ?? 0,
      cell: (r) => (
        <span className="font-mono text-xs text-gray-600">
          {(r.documents_count ?? 0) > 0 ? `📄 ${r.documents_count}` : "—"}
        </span>
      ),
    },
    {
      key: "closing",
      header: "Closing",
      width: "140px",
      sortable: true,
      sortValue: (r) => r.closing_at || "",
      cell: (r) => (
        <span className="font-mono text-xs text-gray-600">
          {r.closing_at ? date(r.closing_at) : "—"}
        </span>
      ),
    },
    {
      key: "link",
      header: "",
      width: "90px",
      align: "right",
      cell: (r) => (
        <a
          href={`/tenders/${r.slug || r.id}`}
          target="_blank"
          rel="noopener noreferrer"
          className="text-xs font-semibold text-primary-600 hover:underline"
        >
          View ↗
        </a>
      ),
    },
  ];

  return (
    <div className="space-y-6">
      {toast && (
        <Alert
          tone={toast.t === "ok" ? "success" : "error"}
          title={toast.m}
          actions={<Button variant="ghost" size="xs" onClick={() => setToast(null)}>Dismiss</Button>}
        />
      )}

      {/* Tailwind Plus underline tabs with count badges. */}
      <div className="border-b border-gray-200">
        <nav role="tablist" aria-label="Notice views" className="-mb-px flex gap-x-6 overflow-x-auto">
          {([
            { key: "queue", label: "Moderation queue", n: queue.length },
            { key: "catalogue", label: "All catalogue notices", n: notices.length },
            { key: "create", label: "Create manual notice" },
          ] as { key: "queue" | "catalogue" | "create"; label: string; n?: number }[]).map((t) => {
            const active = activeTab === t.key;
            return (
              <button
                key={t.key}
                type="button"
                role="tab"
                aria-selected={active}
                onClick={() => setActiveTab(t.key)}
                className={`flex shrink-0 items-center gap-2 whitespace-nowrap border-b-2 px-1 py-3 text-sm font-medium ${
                  active ? "border-primary-600 text-primary-600" : "border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700"
                }`}
              >
                {t.label}
                {t.n !== undefined && (
                  <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${active ? "bg-primary-100 text-primary-600" : "bg-gray-100 text-gray-900"}`}>
                    {t.n}
                  </span>
                )}
              </button>
            );
          })}
        </nav>
      </div>

      {/* Tab 1: Moderation Queue */}
      {activeTab === "queue" && (
        <div className="space-y-4">
          <DataTable
            rows={queue}
            columns={queueColumns}
            searchKeys={(r) => `${r.title} ${r.reference || ""}`}
            empty={{
              title: "No unverified notices awaiting moderation",
              help: "All ingested and submitted tenders have satisfied required attributes and are published.",
            }}
          />
        </div>
      )}

      {/* Tab 2: Catalogue Notices */}
      {activeTab === "catalogue" && (
        <div className="space-y-4">
          <DataTable
            rows={notices}
            columns={catalogueColumns}
            searchKeys={(r) => `${r.title} ${r.reference || ""}`}
            empty={{
              title: "No catalogue notices found",
              help: "Live procurement notices will appear here.",
            }}
          />
        </div>
      )}

      {/* Tab 3: Create Manual Notice */}
      {activeTab === "create" && (
        <div className="max-w-3xl">
          <AdminNoticeForm />
        </div>
      )}

    </div>
  );
}
