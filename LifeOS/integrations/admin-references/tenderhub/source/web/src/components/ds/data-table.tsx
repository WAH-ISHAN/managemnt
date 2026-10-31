"use client";

import { useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import {
  Search,
  ChevronLeft,
  ChevronRight,
  ArrowUpDown,
  ArrowUp,
  ArrowDown,
  X,
  SlidersHorizontal,
  ChevronDown,
  Inbox,
} from "lucide-react";

export interface Column<T> {
  key: string;
  header: string;
  width?: string;
  align?: "left" | "right";
  sortable?: boolean;
  sortValue?: (row: T) => string | number;
  cell: (row: T) => ReactNode;
  meta?: (row: T) => ReactNode;
}

export interface FilterDef<T> {
  key: string;
  label: string;
  options: { value: string; label: string; n?: number }[];
  match: (row: T, selected: string[]) => boolean;
}

/**
 * Generic DataTable<T>. Sorting with aria-sort, multi-select filters with
 * counts, removable applied chips, search, row selection with a bulk bar,
 * windowed pagination, and a FILTER-AWARE empty state.
 */
export function DataTable<T extends { id: number | string }>({
  rows,
  columns,
  filters = [],
  searchKeys,
  perPage = 15,
  empty,
  bulkActions,
  rowHref: _rowHref,
}: {
  rows: T[];
  columns: Column<T>[];
  filters?: FilterDef<T>[];
  searchKeys?: (row: T) => string;
  perPage?: number;
  empty?: { title: string; help?: string };
  bulkActions?: (selected: T[], clear: () => void) => ReactNode;
  rowHref?: (row: T) => string;
}) {
  const [q, setQ] = useState("");
  const [sel, setSel] = useState<Record<string, string[]>>({});
  const [sort, setSort] = useState<{ key: string; dir: "asc" | "desc" } | null>(null);
  const [page, setPage] = useState(1);
  const [picked, setPicked] = useState<Set<string | number>>(new Set());
  const [openFilter, setOpenFilter] = useState<string | null>(null);
  const toolbarRef = useRef<HTMLDivElement>(null);

  // Filter menus close on Escape and on outside click.
  useEffect(() => {
    if (!openFilter) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") setOpenFilter(null);
    };
    const onClick = (e: MouseEvent) => {
      if (toolbarRef.current && !toolbarRef.current.contains(e.target as Node)) {
        setOpenFilter(null);
      }
    };
    document.addEventListener("keydown", onKey);
    document.addEventListener("mousedown", onClick);
    return () => {
      document.removeEventListener("keydown", onKey);
      document.removeEventListener("mousedown", onClick);
    };
  }, [openFilter]);

  const filtered = useMemo(() => {
    let out = rows;
    if (q.trim() && searchKeys) {
      const needle = q.toLowerCase();
      out = out.filter((r) => searchKeys(r).toLowerCase().includes(needle));
    }
    for (const f of filters) {
      const s = sel[f.key] ?? [];
      if (s.length) out = out.filter((r) => f.match(r, s));
    }
    if (sort) {
      const col = columns.find((c) => c.key === sort.key);
      if (col?.sortValue) {
        out = [...out].sort((a, b) => {
          const av = col.sortValue!(a),
            bv = col.sortValue!(b);
          const c = av < bv ? -1 : av > bv ? 1 : 0;
          return sort.dir === "asc" ? c : -c;
        });
      }
    }
    return out;
  }, [rows, q, sel, sort, filters, columns, searchKeys]);

  const pages = Math.max(1, Math.ceil(filtered.length / perPage));
  const safePage = Math.min(page, pages);
  const view = filtered.slice((safePage - 1) * perPage, safePage * perPage);
  const anyFilter = q.trim() !== "" || Object.values(sel).some((v) => v.length);

  const chips = filters.flatMap((f) =>
    (sel[f.key] ?? []).map((v) => ({
      key: f.key,
      value: v,
      label: f.options.find((o) => o.value === v)?.label ?? v,
    })),
  );

  const selectedRows = view.filter((r) => picked.has(r.id));

  const window_: (number | "…")[] = [];
  for (let i = 1; i <= pages; i++) {
    if (i === 1 || i === pages || Math.abs(i - safePage) <= 1) window_.push(i);
    else if (window_[window_.length - 1] !== "…") window_.push("…");
  }

  return (
    <div>
      {/* Toolbar */}
      <div
        ref={toolbarRef}
        className="flex flex-wrap items-center gap-2 border-b border-slate-200 bg-white px-4 py-2.5"
      >
        {searchKeys ? (
          <div className="relative w-full sm:w-64">
            <Search className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 size-4 text-slate-400" />
            <input
              value={q}
              onChange={(e) => {
                setQ(e.target.value);
                setPage(1);
              }}
              placeholder="Filter records…"
              className="h-8 w-full rounded-sm bg-white pl-9 pr-8 text-sm text-slate-900 placeholder:text-slate-400 outline-1 -outline-offset-1 outline-slate-300 focus:outline-2 focus:-outline-offset-2 focus:outline-blue-600 transition-all"
            />
            {q && (
              <button
                type="button"
                onClick={() => {
                  setQ("");
                  setPage(1);
                }}
                className="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-0.5 cursor-pointer"
              >
                <X className="size-3.5" />
              </button>
            )}
          </div>
        ) : null}

        {filters.map((f) => {
          const s = sel[f.key] ?? [];
          const active = s.length > 0;
          const isOpen = openFilter === f.key;
          return (
            <div key={f.key} className="relative">
              <button
                type="button"
                aria-haspopup="true"
                aria-expanded={isOpen}
                onClick={() => setOpenFilter(isOpen ? null : f.key)}
                className={`inline-flex items-center gap-1.5 h-8 rounded-sm border px-3 text-sm font-medium transition-all cursor-pointer ${
                  active
                    ? "border-blue-600 bg-blue-50/70 text-blue-600"
                    : "border-slate-200 bg-white text-slate-700 hover:bg-slate-50 hover:border-slate-300"
                }`}
              >
                <SlidersHorizontal className="size-3.5 opacity-60" />
                <span>{f.label}</span>
                {active && (
                  <span className="ml-0.5 rounded-full bg-blue-600 px-1.5 py-0.5 text-[10px] font-bold text-white leading-none">
                    {s.length}
                  </span>
                )}
                <ChevronDown className="size-3 text-slate-400 ml-0.5" />
              </button>

              {isOpen ? (
                <div
                  role="group"
                  aria-label={`${f.label} filters`}
                  className="absolute left-0 top-full z-40 mt-1.5 max-h-72 w-64 overflow-auto rounded-lg border border-slate-200 bg-white p-2 shadow-lg ring-1 ring-black/5"
                >
                  <div className="px-2 py-1 text-[11px] font-bold uppercase tracking-wider text-slate-400 border-b border-slate-100 mb-1">
                    Filter by {f.label}
                  </div>
                  {f.options.map((o) => (
                    <label
                      key={o.value}
                      className="flex cursor-pointer items-center gap-2.5 rounded-md px-2 py-1.5 text-xs text-slate-700 hover:bg-slate-50 transition-colors"
                    >
                      <input
                        type="checkbox"
                        checked={s.includes(o.value)}
                        onChange={() => {
                          const next = s.includes(o.value)
                            ? s.filter((x) => x !== o.value)
                            : [...s, o.value];
                          setSel({ ...sel, [f.key]: next });
                          setPage(1);
                        }}
                        className="rounded border-slate-300 text-blue-600 focus:ring-blue-600 size-3.5 accent-blue-600"
                      />
                      <span className="flex-1 truncate font-medium">{o.label}</span>
                      {o.n !== undefined ? (
                        <span className="font-mono text-[11px] text-slate-400 bg-slate-100 px-1.5 py-0.5 rounded">
                          {o.n}
                        </span>
                      ) : null}
                    </label>
                  ))}
                </div>
              ) : null}
            </div>
          );
        })}

        <span className="ml-auto font-mono text-xs font-medium text-slate-400">
          {filtered.length.toLocaleString()} {filtered.length === 1 ? "record" : "records"}
        </span>
      </div>

      {/* Applied Chips Bar */}
      {chips.length ? (
        <div className="flex flex-wrap items-center gap-2 border-b border-slate-200/80 bg-slate-50/70 px-4 py-2 sm:px-6">
          <span className="text-[11px] font-semibold uppercase tracking-wider text-slate-400 mr-1">
            Active Filters:
          </span>
          {chips.map((c) => (
            <button
              key={c.key + c.value}
              type="button"
              onClick={() =>
                setSel({
                  ...sel,
                  [c.key]: (sel[c.key] ?? []).filter((x) => x !== c.value),
                })
              }
              className="inline-flex items-center gap-1 rounded-full bg-white px-2.5 py-1 text-xs font-medium text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-100 transition-all cursor-pointer"
            >
              <span>{c.label}</span>
              <X className="size-3 text-slate-400 hover:text-slate-600" />
            </button>
          ))}
          <button
            type="button"
            onClick={() => {
              setSel({});
              setQ("");
            }}
            className="ml-1 text-xs font-semibold text-blue-600 hover:underline cursor-pointer"
          >
            Clear all
          </button>
        </div>
      ) : null}

      {/* Bulk Actions Bar */}
      {bulkActions && picked.size ? (
        <div className="flex items-center gap-3 border-b border-blue-200 bg-blue-50/80 px-4 py-2.5 sm:px-6 text-xs">
          <span className="font-semibold text-blue-600">{picked.size} selected</span>
          {bulkActions(selectedRows, () => setPicked(new Set()))}
        </div>
      ) : null}

      {/* Table Element */}
      <div className="overflow-x-auto">
        <table className="min-w-full divide-y divide-slate-200 text-left">
          <thead className="sticky top-0 z-10 bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500">
            <tr>
              {bulkActions ? <th scope="col" className="w-9 px-4 sm:px-6" /> : null}
              {columns.map((c) => {
                const active = sort?.key === c.key;
                return (
                  <th
                    key={c.key}
                    scope="col"
                    aria-sort={active ? (sort!.dir === "asc" ? "ascending" : "descending") : undefined}
                    className={`px-3 py-2 font-semibold first:pl-4 last:pr-4 ${c.align === "right" ? "text-right" : ""}`}
                    style={c.width ? { width: c.width } : undefined}
                  >
                    {c.sortable ? (
                      <button
                        type="button"
                        onClick={() =>
                          setSort(
                            active && sort!.dir === "asc"
                              ? { key: c.key, dir: "desc" }
                              : { key: c.key, dir: "asc" },
                          )
                        }
                        className="group inline-flex items-center gap-1 hover:text-slate-900 cursor-pointer"
                      >
                        <span>{c.header}</span>
                        <span className="text-slate-400 group-hover:text-slate-700">
                          {active ? (
                            sort!.dir === "asc" ? (
                              <ArrowUp className="size-3.5 text-blue-600" />
                            ) : (
                              <ArrowDown className="size-3.5 text-blue-600" />
                            )
                          ) : (
                            <ArrowUpDown className="size-3.5 opacity-40 group-hover:opacity-100" />
                          )}
                        </span>
                      </button>
                    ) : (
                      c.header
                    )}
                  </th>
                );
              })}
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100 bg-white">
            {view.map((r) => (
              <tr
                key={r.id}
                className="transition hover:bg-slate-50"
                style={{ height: "var(--row-h)" }}
              >
                {bulkActions ? (
                  <td className="px-4 sm:px-6">
                    <input
                      type="checkbox"
                      aria-label="Select row"
                      checked={picked.has(r.id)}
                      onChange={() => {
                        const n = new Set(picked);
                        if (n.has(r.id)) n.delete(r.id);
                        else n.add(r.id);
                        setPicked(n);
                      }}
                      className="rounded border-slate-300 text-blue-600 focus:ring-blue-600 size-3.5 accent-blue-600"
                    />
                  </td>
                ) : null}
                {columns.map((c) => (
                  <td
                    key={c.key}
                    className={`px-3 py-2 text-sm text-slate-700 align-middle first:pl-4 last:pr-4 ${c.align === "right" ? "text-right" : ""}`}
                  >
                    {c.cell(r)}
                    {c.meta ? (
                      <div className="mt-0.5 text-[11px] text-slate-400">{c.meta(r)}</div>
                    ) : null}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Empty States */}
      {!view.length ? (
        <div className="px-6 py-14 text-center">
          <Inbox aria-hidden="true" className="mx-auto size-10 text-slate-300 mb-2" />
          <p className="text-sm font-semibold text-slate-900">
            {anyFilter ? "No records match these criteria" : empty?.title ?? "No data recorded yet"}
          </p>
          <p className="mx-auto mt-1 max-w-md text-xs leading-relaxed text-slate-500">
            {anyFilter ? "Try resetting or widening the search filters." : empty?.help}
          </p>
          {anyFilter ? (
            <button
              type="button"
              onClick={() => {
                setSel({});
                setQ("");
              }}
              className="mt-4 inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-500 transition-colors cursor-pointer"
            >
              Clear all filters
            </button>
          ) : null}
        </div>
      ) : null}

      {/* Pagination */}
      {pages > 1 ? (
        <div className="flex items-center justify-between border-t border-slate-200 bg-white px-4 py-2">
          <div className="text-xs text-slate-500">
            Showing <span className="font-semibold text-slate-900">{(safePage - 1) * perPage + 1}</span>{" "}
            to{" "}
            <span className="font-semibold text-slate-900">
              {Math.min(safePage * perPage, filtered.length)}
            </span>{" "}
            of <span className="font-semibold text-slate-900">{filtered.length}</span> results
          </div>
          <div className="flex items-center gap-1">
            <button
              type="button"
              disabled={safePage === 1}
              onClick={() => setPage(safePage - 1)}
              className="inline-flex size-8 items-center justify-center rounded-md border border-slate-200 text-slate-600 hover:bg-slate-50 hover:border-slate-300 disabled:opacity-40 disabled:pointer-events-none transition-all cursor-pointer"
              aria-label="Previous page"
            >
              <ChevronLeft className="size-4" />
            </button>
            {window_.map((p, i) =>
              p === "…" ? (
                <span key={`e${i}`} className="px-1 text-xs text-slate-400">
                  …
                </span>
              ) : (
                <button
                  key={p}
                  type="button"
                  onClick={() => setPage(p)}
                  className={`inline-flex size-8 items-center justify-center rounded-md font-mono text-xs font-semibold transition-all cursor-pointer ${
                    p === safePage
                      ? "bg-blue-600 text-white"
                      : "border border-slate-200 text-slate-700 hover:bg-slate-50 hover:border-slate-300"
                  }`}
                >
                  {p}
                </button>
              ),
            )}
            <button
              type="button"
              disabled={safePage === pages}
              onClick={() => setPage(safePage + 1)}
              className="inline-flex size-8 items-center justify-center rounded-md border border-slate-200 text-slate-600 hover:bg-slate-50 hover:border-slate-300 disabled:opacity-40 disabled:pointer-events-none transition-all cursor-pointer"
              aria-label="Next page"
            >
              <ChevronRight className="size-4" />
            </button>
          </div>
        </div>
      ) : null}
    </div>
  );
}
