import { PageHeading } from "@/components/console/ui";
import { PanelError } from "@/components/portal/panel-error";
import { BlogManager } from "@/components/portal/blog-manager";
import { authed } from "@/lib/api";
import type { BlogPost } from "@/lib/blog";

export const metadata = { title: "Blog | TenderHub Admin" };

type Search = { q?: string; status?: string; page?: string };

export default async function BlogAdminPage({ searchParams }: { searchParams: Promise<Search> }) {
  const s = await searchParams;
  const page = Math.max(1, Number(s.page) || 1);
  const query = new URLSearchParams({ per_page: "20", page: String(page) });
  if (s.q) query.set("q", s.q.slice(0, 100));
  if (s.status) query.set("status", s.status);
  const response = await authed<BlogPost[]>(`/api/v1/admin/blog?${query}`);
  if (!response.ok) return <PanelError status={response.status} />;
  return <>
    <PageHeading title="Blog" description="Write, review, schedule and publish TenderHub procurement articles from one editorial panel." />
    <BlogManager
      posts={(response.body?.data ?? []) as BlogPost[]}
      total={Number(response.body?.meta?.total ?? 0)}
      page={page}
      pages={Math.max(1, Number(response.body?.meta?.pages ?? 1))}
      q={s.q ?? ""}
      status={s.status ?? ""}
    />
  </>;
}
