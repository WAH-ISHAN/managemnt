import Link from "next/link";
import { Markdown } from "@/lib/markdown";
import { BLOG_LANGUAGES, SITE_URL, blogHref, coverSrc, formatBlogDate, readingMinutes, type BlogPost, type BlogPostSummary } from "@/lib/blog";

/** A blog card: cover, category, title, excerpt, date. Used by the list, related posts and the home page. */
export function BlogCard({ post, compact = false }: { post: BlogPostSummary; compact?: boolean }) {
  const cover = coverSrc(post.cover_image_url);
  return (
    <article className="group flex h-full flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:shadow-md">
      {cover ? (
        <Link href={`/blog/${post.slug}`} tabIndex={-1} aria-hidden="true" className="block aspect-[16/9] overflow-hidden bg-slate-100">
          {/* eslint-disable-next-line @next/next/no-img-element -- covers are hosted anywhere over https */}
          <img src={cover} alt="" loading="lazy" className="size-full object-cover transition duration-300 group-hover:scale-[1.02]" />
        </Link>
      ) : null}
      <div className={`flex flex-1 flex-col ${compact ? "p-4" : "p-5"}`}>
        <Link href={blogHref({ category: post.category })} className="text-xs font-bold uppercase tracking-wider text-blue-700 hover:underline">{post.category}</Link>
        <h3 className={`mt-2 font-bold text-slate-950 ${compact ? "text-base" : "text-xl"}`}>
          <Link href={`/blog/${post.slug}`} className="hover:text-blue-700" lang={post.language}>{post.title}</Link>
        </h3>
        {!compact ? <p className="mt-3 flex-1 text-sm leading-6 text-slate-600" lang={post.language}>{post.excerpt}</p> : <div className="flex-1" />}
        <div className="mt-4 flex items-center justify-between gap-3 text-xs text-slate-500">
          <time dateTime={post.published_at ?? undefined}>{formatBlogDate(post.published_at)}</time>
          <Link href={`/blog/${post.slug}`} className="font-bold text-blue-700 hover:underline">Read →</Link>
        </div>
      </div>
    </article>
  );
}

function ShareLinks({ post }: { post: BlogPost }) {
  const url = encodeURIComponent(`${SITE_URL}/blog/${post.slug}`);
  const text = encodeURIComponent(post.title);
  const links = [
    ["Facebook", `https://www.facebook.com/sharer/sharer.php?u=${url}`],
    ["LinkedIn", `https://www.linkedin.com/sharing/share-offsite/?url=${url}`],
    ["X", `https://twitter.com/intent/tweet?url=${url}&text=${text}`],
    ["WhatsApp", `https://wa.me/?text=${text}%20${url}`],
  ];
  return (
    <div className="flex flex-wrap items-center gap-2 text-sm">
      <span className="font-semibold text-slate-700">Share:</span>
      {links.map(([label, href]) => (
        <a key={label} href={href} target="_blank" rel="noopener noreferrer"
          className="inline-flex h-8 items-center rounded-md border border-slate-300 bg-white px-3 font-medium text-slate-700 hover:bg-slate-50">
          {label}
        </a>
      ))}
    </div>
  );
}

/** The full article. `preview` marks a staff view of a draft or scheduled (`scheduled`) post. */
export function BlogArticle({ post, preview = false, scheduled = false }: { post: BlogPost; preview?: boolean; scheduled?: boolean }) {
  const cover = coverSrc(post.cover_image_url);
  const language = BLOG_LANGUAGES.find((l) => l.value === post.language);
  return (
    <article className="mx-auto max-w-[820px] px-5 py-10 sm:py-14" lang={post.language}>
      {preview ? (
        <div role="status" className="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
          Preview — {post.status === "published" ? (scheduled ? `scheduled for ${formatBlogDate(post.published_at)}` : "published") : "draft, not visible to the public"}.
        </div>
      ) : null}
      <nav aria-label="Breadcrumb" className="text-xs text-slate-500" lang="en">
        <Link href="/" className="hover:text-blue-700">Home</Link><span className="px-2">/</span>
        <Link href="/blog" className="hover:text-blue-700">Blog</Link><span className="px-2">/</span>
        <Link href={blogHref({ category: post.category })} className="hover:text-blue-700">{post.category}</Link>
      </nav>
      <header className="mt-8 border-b border-slate-200 pb-8">
        <p className="text-xs font-black uppercase tracking-[0.18em] text-blue-700">{post.category}{language && language.value !== "en" ? ` · ${language.label}` : ""}</p>
        <h1 className="mt-3 text-4xl font-black leading-tight tracking-tight text-slate-950 sm:text-5xl">{post.title}</h1>
        <p className="mt-5 text-lg leading-8 text-slate-600">{post.excerpt}</p>
        <p className="mt-4 text-sm text-slate-500" lang="en">
          {post.author_name ? <>By <span className="font-semibold text-slate-700">{post.author_name}</span></> : "TenderHub Editorial"}
          {" · "}<time dateTime={post.published_at ?? undefined}>{formatBlogDate(post.published_at)}</time>
          {" · "}{readingMinutes(post.body)} min read
        </p>
      </header>
      {cover ? (
        // eslint-disable-next-line @next/next/no-img-element -- covers are hosted anywhere over https
        <img src={cover} alt={post.cover_image_alt ?? ""} className="mt-8 aspect-[16/9] w-full rounded-2xl border border-slate-200 bg-slate-100 object-cover" />
      ) : null}
      <div className="mt-8 space-y-6 text-base leading-8 text-slate-700">
        <Markdown source={post.body} />
      </div>
      {post.tags.length ? (
        <ul aria-label="Tags" className="mt-10 flex flex-wrap gap-2">
          {post.tags.map((t) => (
            <li key={t}><Link href={blogHref({ tag: t })} className="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-700 hover:bg-blue-50 hover:text-blue-700">#{t}</Link></li>
          ))}
        </ul>
      ) : null}
      <footer className="mt-10 space-y-6 border-t border-slate-200 pt-6" lang="en">
        {!preview ? <ShareLinks post={post} /> : null}
        {post.related?.length ? (
          <section aria-labelledby="related-heading">
            <h2 id="related-heading" className="text-lg font-bold text-slate-950">More in {post.category}</h2>
            <div className="mt-4 grid gap-4 sm:grid-cols-3">
              {post.related.map((r) => <BlogCard key={r.id} post={r} compact />)}
            </div>
          </section>
        ) : null}
        <Link href="/blog" className="inline-block text-sm font-bold text-blue-700 hover:underline">← Back to all articles</Link>
      </footer>
    </article>
  );
}
