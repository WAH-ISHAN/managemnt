export type BlogPostStatus = "draft" | "published";
export type BlogLanguage = "en" | "si" | "ta";

export const BLOG_LANGUAGES: { value: BlogLanguage; label: string }[] = [
  { value: "en", label: "English" },
  { value: "si", label: "සිංහල" },
  { value: "ta", label: "தமிழ்" },
];

export interface BlogPostSummary {
  id: number;
  slug: string;
  title: string;
  excerpt: string;
  category: string;
  tags: string[];
  language: BlogLanguage;
  cover_image_url?: string | null;
  cover_image_alt?: string | null;
  status?: BlogPostStatus;
  published_at: string | null;
  updated_at: string;
  author_name?: string | null;
}

export interface BlogPost extends BlogPostSummary {
  body: string;
  seo_title?: string | null;
  seo_description?: string | null;
  show_author?: number | boolean;
  related?: BlogPostSummary[];
}

export const SITE_URL = "https://tenderhub.lk";

export const formatBlogDate = (value: string | null) => value
  ? new Intl.DateTimeFormat("en-LK", { day: "numeric", month: "short", year: "numeric", timeZone: "Asia/Colombo" }).format(new Date(value.replace(" ", "T") + (value.includes("T") ? "" : "+05:30")))
  : "Not published";

/** Minutes to read, at 220 words a minute. */
export const readingMinutes = (body: string) => Math.max(1, Math.round(body.trim().split(/\s+/).length / 220));

/** A cover address the page may load: https, or a path on this site. */
export const coverSrc = (url?: string | null) =>
  url && (/^https:\/\//i.test(url) || (url.startsWith("/") && !url.startsWith("//"))) ? url : null;

/** The query string for a blog listing filter, without empty values. */
export function blogHref(params: Record<string, string | number | undefined>) {
  const q = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) if (v !== undefined && v !== "" && !(k === "page" && Number(v) <= 1)) q.set(k, String(v));
  const s = q.toString();
  return s ? `/blog?${s}` : "/blog";
}
