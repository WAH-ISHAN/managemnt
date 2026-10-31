"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import type { Route } from "next";
import { usePathname, useRouter } from "next/navigation";
import { Alert, Badge, Card, CardBody, CardHeader, EmptyState } from "@/components/console/ui";
import { Button, inputClass } from "@/components/console/interactive";
import { Markdown } from "@/lib/markdown";
import { BLOG_LANGUAGES, SITE_URL, coverSrc, formatBlogDate, type BlogLanguage, type BlogPost, type BlogPostStatus } from "@/lib/blog";

type Form = {
  slug: string; title: string; excerpt: string; body: string; category: string; status: BlogPostStatus;
  seo_title: string; seo_description: string; cover_image_url: string; cover_image_alt: string;
  tags: string; language: BlogLanguage; show_author: boolean; published_at: string;
};

const EMPTY: Form = {
  slug: "", title: "", excerpt: "", body: "", category: "Procurement insights", status: "draft",
  seo_title: "", seo_description: "", cover_image_url: "", cover_image_alt: "", tags: "", language: "en",
  show_author: true, published_at: "",
};

/** "2026-10-23 09:30:00" (Colombo) → "2026-10-23T09:30" for a datetime-local input. */
const toLocalInput = (v: string | null | undefined) => (v ? v.replace(" ", "T").slice(0, 16) : "");

const isScheduled = (p: BlogPost) => p.status === "published" && !!p.published_at
  && new Date(p.published_at.replace(" ", "T") + "+05:30").getTime() > Date.now();

function toForm(p: BlogPost): Form {
  return {
    slug: p.slug, title: p.title, excerpt: p.excerpt ?? "", body: p.body ?? "", category: p.category, status: p.status ?? "draft",
    seo_title: p.seo_title ?? "", seo_description: p.seo_description ?? "", cover_image_url: p.cover_image_url ?? "",
    cover_image_alt: p.cover_image_alt ?? "", tags: (p.tags ?? []).join(", "), language: p.language ?? "en",
    show_author: Number(p.show_author ?? 1) === 1, published_at: toLocalInput(p.published_at),
  };
}

const MD_HELP = "## Heading · **bold** · *italic* · [link](https://…) · ![image](https://…) · - list · 1. list · > quote";

function Editor({ id, onClose }: { id: number | null; onClose: () => void }) {
  const router = useRouter();
  const [form, setForm] = useState<Form | null>(id === null ? EMPTY : null);
  const [tab, setTab] = useState<"write" | "preview">("write");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const set = <K extends keyof Form>(key: K, value: Form[K]) => setForm((now) => (now ? { ...now, [key]: value } : now));

  useEffect(() => {
    if (id === null) return;
    let live = true;
    fetch(`/api/admin/blog/${id}`).then(async (r) => {
      const json = await r.json().catch(() => ({}));
      if (!live) return;
      if (!r.ok) setError(json.detail ?? "The post could not be loaded.");
      else setForm(toForm(json.data as BlogPost));
    }).catch(() => live && setError("Network error. Please try again."));
    return () => { live = false; };
  }, [id]);

  async function save(e: React.FormEvent) {
    e.preventDefault();
    if (!form) return;
    setBusy(true); setError(null);
    try {
      const response = await fetch(id !== null ? `/api/admin/blog/${id}` : "/api/admin/blog", {
        method: id !== null ? "PUT" : "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ ...form, published_at: form.published_at ? form.published_at.replace("T", " ") + ":00" : "" }),
      });
      const json = await response.json().catch(() => ({}));
      if (!response.ok) { setError(json.detail ?? "The post could not be saved."); return; }
      onClose(); router.refresh();
    } catch { setError("Network error. Please try again."); } finally { setBusy(false); }
  }

  if (!form) {
    return <Card><CardBody>{error ? <Alert tone="error" title={error} actions={<Button variant="secondary" onClick={onClose}>Back</Button>} /> : <p className="text-sm text-slate-500" role="status">Loading post…</p>}</CardBody></Card>;
  }
  const cover = coverSrc(form.cover_image_url);
  const label = "text-sm font-medium text-slate-700";

  return (
    <Card>
      <CardHeader title={id !== null ? "Edit blog post" : "New blog post"}
        description="Write in Markdown. A post is public only when its status is Published and its publish time has passed." />
      <CardBody>
        {error ? <div className="mb-4"><Alert tone="error" title={error} /></div> : null}
        <form onSubmit={save} className="grid gap-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <label className={label}>Title<input required maxLength={240} value={form.title} onChange={(e) => set("title", e.target.value)} className={`mt-1 ${inputClass}`} /></label>
            <label className={label}>Slug<input maxLength={190} pattern="[a-z0-9-]*" placeholder="Generated from the title when empty" value={form.slug} onChange={(e) => set("slug", e.target.value.toLowerCase())} className={`mt-1 ${inputClass}`} /></label>
            <label className={label}>Category<input required maxLength={100} value={form.category} onChange={(e) => set("category", e.target.value)} className={`mt-1 ${inputClass}`} /></label>
            <label className={label}>Tags<input maxLength={480} placeholder="e-gp, bid security, registration" value={form.tags} onChange={(e) => set("tags", e.target.value)} className={`mt-1 ${inputClass}`} /><span className="mt-1 block text-xs font-normal text-slate-500">Comma separated, up to ten.</span></label>
            <label className={label}>Language<select value={form.language} onChange={(e) => set("language", e.target.value as BlogLanguage)} className={`mt-1 ${inputClass}`}>{BLOG_LANGUAGES.map((l) => <option key={l.value} value={l.value}>{l.label}</option>)}</select></label>
            <label className={label}>Status<select value={form.status} onChange={(e) => set("status", e.target.value as BlogPostStatus)} className={`mt-1 ${inputClass}`}><option value="draft">Draft</option><option value="published">Published</option></select></label>
            <label className={label}>Publish date and time (Sri Lanka)<input type="datetime-local" value={form.published_at} onChange={(e) => set("published_at", e.target.value)} className={`mt-1 ${inputClass}`} /><span className="mt-1 block text-xs font-normal text-slate-500">Leave empty to publish now. A future time schedules the post.</span></label>
            <label className="flex items-center gap-2 self-center text-sm font-medium text-slate-700"><input type="checkbox" checked={form.show_author} onChange={(e) => set("show_author", e.target.checked)} className="size-4" />Show the author&apos;s name on the article</label>
          </div>

          <div className="grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-start">
            <label className={label}>Cover image URL<input maxLength={500} placeholder="https://… or /images/…" value={form.cover_image_url} onChange={(e) => set("cover_image_url", e.target.value.trim())} className={`mt-1 ${inputClass}`} /></label>
            <label className={label}>Cover image description<input maxLength={240} placeholder="What the image shows, for screen readers" value={form.cover_image_alt} onChange={(e) => set("cover_image_alt", e.target.value)} className={`mt-1 ${inputClass}`} /></label>
            {/* eslint-disable-next-line @next/next/no-img-element -- a preview of whatever address the editor typed */}
            {cover ? <img src={cover} alt="" className="h-20 w-32 rounded-md border border-slate-200 object-cover" /> : null}
          </div>

          <label className={label}>Excerpt<textarea maxLength={500} rows={3} placeholder="Generated from the article when empty" value={form.excerpt} onChange={(e) => set("excerpt", e.target.value)} className={`mt-1 ${inputClass}`} /></label>

          <div>
            <div className="flex items-center justify-between">
              <span className={label} id="body-label">Article body</span>
              <div role="tablist" aria-label="Article body" className="flex gap-1 rounded-md bg-slate-100 p-0.5 text-sm">
                {(["write", "preview"] as const).map((t) => (
                  <button key={t} type="button" role="tab" aria-selected={tab === t} onClick={() => setTab(t)}
                    className={`rounded px-3 py-1 font-medium capitalize ${tab === t ? "bg-white text-slate-900 shadow-sm" : "text-slate-600"}`}>{t}</button>
                ))}
              </div>
            </div>
            {tab === "write" ? (
              <>
                <textarea aria-labelledby="body-label" required maxLength={100000} rows={18} value={form.body} onChange={(e) => set("body", e.target.value)} className={`mt-2 font-mono ${inputClass}`} aria-describedby="body-help" lang={form.language} />
                <span id="body-help" className="mt-1 block text-xs text-slate-500">Markdown: {MD_HELP}. HTML is shown as text.</span>
              </>
            ) : (
              <div className="mt-2 min-h-40 space-y-5 rounded-md border border-slate-200 p-4 text-base leading-8 text-slate-700" lang={form.language}>
                {form.body.trim() ? <Markdown source={form.body} /> : <p className="text-sm text-slate-400">Nothing to preview yet.</p>}
              </div>
            )}
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <label className={label}>SEO title<input maxLength={240} value={form.seo_title} onChange={(e) => set("seo_title", e.target.value)} className={`mt-1 ${inputClass}`} /></label>
            <label className={label}>SEO description<textarea maxLength={320} rows={2} value={form.seo_description} onChange={(e) => set("seo_description", e.target.value)} className={`mt-1 ${inputClass}`} /></label>
          </div>
          <div className="flex justify-end gap-3"><Button type="button" variant="secondary" disabled={busy} onClick={onClose}>Cancel</Button><Button type="submit" busy={busy}>Save post</Button></div>
        </form>
      </CardBody>
    </Card>
  );
}

function StatusBadge({ post }: { post: BlogPost }) {
  if (isScheduled(post)) return <Badge tone="yellow">scheduled</Badge>;
  return <Badge tone={post.status === "published" ? "green" : "gray"}>{post.status}</Badge>;
}

/** Copies a ready-made social post (title, excerpt, link) for Social marketing or Campaigns. */
function CopySocial({ post }: { post: BlogPost }) {
  const [done, setDone] = useState(false);
  async function copy() {
    const text = `${post.title}\n\n${post.excerpt}\n\n${SITE_URL}/blog/${post.slug}${post.tags?.length ? "\n\n" + post.tags.map((t) => "#" + t.replace(/[^\p{L}\p{N}]+/gu, "")).join(" ") : ""}`;
    try { await navigator.clipboard.writeText(text); setDone(true); setTimeout(() => setDone(false), 2000); } catch { window.prompt("Copy this social post:", text); }
  }
  return <Button size="sm" variant="ghost" onClick={copy}>{done ? "Copied" : "Copy for social"}</Button>;
}

const FILTERS = [["", "All"], ["published", "Published"], ["scheduled", "Scheduled"], ["draft", "Drafts"]] as const;

export function BlogManager({ posts, total, page, pages, q, status }: {
  posts: BlogPost[]; total: number; page: number; pages: number; q: string; status: string;
}) {
  const router = useRouter();
  const pathname = usePathname();
  const [editing, setEditing] = useState<number | "new" | null>(null);
  const [busy, setBusy] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState(q);

  const href = (next: { q?: string; status?: string; page?: number }) => {
    const p = new URLSearchParams();
    const v = { q, status, page, ...next };
    if (v.q) p.set("q", v.q);
    if (v.status) p.set("status", v.status);
    if (v.page && v.page > 1) p.set("page", String(v.page));
    const s = p.toString();
    return (s ? `${pathname}?${s}` : pathname) as Route;
  };

  async function remove(post: BlogPost) {
    if (!window.confirm(`Delete “${post.title}”? This cannot be undone.`)) return;
    setBusy(post.id); setError(null);
    try {
      const response = await fetch(`/api/admin/blog/${post.id}`, { method: "DELETE" });
      const json = await response.json().catch(() => ({}));
      if (!response.ok) { setError(json.detail ?? "The post could not be deleted."); return; }
      router.refresh();
    } catch { setError("Network error. Please try again."); } finally { setBusy(null); }
  }

  if (editing !== null) return <Editor id={editing === "new" ? null : editing} onClose={() => setEditing(null)} />;

  return <div className="space-y-5">
    {error ? <Alert tone="error" title={error} /> : null}
    <Card>
      <CardHeader title="Editorial posts" description={`${total} post${total === 1 ? "" : "s"}. Drafts and scheduled posts stay private until they are due.`}
        actions={<Button size="sm" onClick={() => setEditing("new")}>New post</Button>} />
      <div className="flex flex-wrap items-center gap-2 border-b border-slate-200 px-4 py-2.5">
        <form onSubmit={(e) => { e.preventDefault(); router.push(href({ q: search.trim(), page: 1 })); }} role="search" className="flex gap-2">
          <label htmlFor="blog-admin-q" className="sr-only">Search posts</label>
          <input id="blog-admin-q" type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search title, excerpt or tags" maxLength={100} className={`${inputClass} !w-64 !min-h-8 !py-1`} />
          <Button size="sm" variant="secondary" type="submit">Search</Button>
        </form>
        <nav aria-label="Status" className="ml-auto flex gap-1">
          {FILTERS.map(([value, text]) => (
            <Link key={value} href={href({ status: value, page: 1 })} aria-current={status === value ? "page" : undefined}
              className={`inline-flex h-8 items-center rounded-sm px-3 text-sm font-medium ${status === value ? "bg-blue-50 text-blue-700" : "text-slate-600 hover:bg-slate-100"}`}>{text}</Link>
          ))}
        </nav>
      </div>
      {posts.length === 0 ? <EmptyState title={q || status ? "No posts match" : "No blog posts yet"} description={q || status ? "Try another search or filter." : "Create the first authentic TenderHub editorial post."} action={q || status ? undefined : <Button onClick={() => setEditing("new")}>Create post</Button>} /> : (
        <div className="divide-y divide-slate-100">{posts.map((post) => <article key={post.id} className="grid gap-4 p-4 sm:p-5 lg:grid-cols-[1fr_auto] lg:items-center">
          <div className="min-w-0"><div className="flex flex-wrap items-center gap-2"><h2 className="font-semibold text-slate-900" lang={post.language}>{post.title}</h2><StatusBadge post={post} />{post.language !== "en" ? <Badge tone="purple">{BLOG_LANGUAGES.find((l) => l.value === post.language)?.label}</Badge> : null}</div>
            <p className="mt-1 line-clamp-2 text-sm text-slate-600" lang={post.language}>{post.excerpt}</p>
            <p className="mt-2 text-xs text-slate-500">/{post.slug} · {post.category}{post.tags?.length ? ` · ${post.tags.map((t) => "#" + t).join(" ")}` : ""} · {post.status === "published" ? `${isScheduled(post) ? "Scheduled" : "Published"} ${formatBlogDate(post.published_at)}` : `Updated ${formatBlogDate(post.updated_at)}`}{post.author_name ? ` · ${post.author_name}` : ""}</p></div>
          <div className="flex flex-wrap gap-2">
            <Link href={`/console/blog/${post.id}/preview` as Route} className="inline-flex h-8 items-center rounded-sm border border-slate-300 bg-white px-3 text-sm font-medium text-slate-700 hover:bg-slate-50">Preview</Link>
            <Button size="sm" variant="secondary" onClick={() => setEditing(post.id)}>Edit</Button>
            {post.status === "published" && !isScheduled(post) ? <CopySocial post={post} /> : null}
            <Button size="sm" variant="danger" disabled={busy === post.id} onClick={() => remove(post)}>Delete</Button>
          </div>
        </article>)}</div>
      )}
      {pages > 1 ? <nav aria-label="Pagination" className="flex items-center justify-between border-t border-slate-200 px-4 py-2 text-sm">
        {page > 1 ? <Link href={href({ page: page - 1 })} className="font-semibold text-blue-700 hover:underline">← Previous</Link> : <span />}
        <span className="text-slate-500">Page {page} of {pages}</span>
        {page < pages ? <Link href={href({ page: page + 1 })} className="font-semibold text-blue-700 hover:underline">Next →</Link> : <span />}
      </nav> : null}
    </Card>
  </div>;
}
