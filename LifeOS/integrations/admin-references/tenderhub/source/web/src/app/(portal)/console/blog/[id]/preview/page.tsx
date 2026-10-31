import Link from "next/link";
import { notFound } from "next/navigation";
import { PanelError } from "@/components/portal/panel-error";
import { BlogArticle } from "@/components/blog/article";
import { authed } from "@/lib/api";
import type { BlogPost } from "@/lib/blog";

export const metadata = { title: "Preview | TenderHub Admin", robots: { index: false, follow: false } };

/** Staff preview of any post, drafts and scheduled posts included, as the public will see it. */
export default async function BlogPreviewPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  if (!/^\d+$/.test(id)) notFound();
  const response = await authed<BlogPost>(`/api/v1/admin/blog/${id}`);
  if (response.status === 404) notFound();
  if (!response.ok || !response.body?.data) return <PanelError status={response.status} />;
  const post = response.body.data as BlogPost;
  const shown = { ...post, author_name: Number(post.show_author ?? 1) ? post.author_name : null, related: [] };
  // eslint-disable-next-line react-hooks/purity -- a server render, once per request
  const now = Date.now();
  const scheduled = !!post.published_at && new Date(post.published_at.replace(" ", "T") + "+05:30").getTime() > now;
  return <div className="rounded-2xl border border-slate-200 bg-white">
    <div className="border-b border-slate-200 px-4 py-2 text-sm"><Link href="/console/blog" className="font-semibold text-blue-700 hover:underline">← Back to posts</Link></div>
    <BlogArticle post={shown} preview scheduled={scheduled} />
  </div>;
}
