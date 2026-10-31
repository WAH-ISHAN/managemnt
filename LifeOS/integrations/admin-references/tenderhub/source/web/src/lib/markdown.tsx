import type { ReactNode } from "react";

/**
 * A small Markdown renderer for blog articles.
 *
 * It builds React elements, never an HTML string, so whatever an editor types
 * is text unless it is one of the forms below — there is no path for raw HTML
 * or a script into the page. Links and images are kept only when their address
 * is https:, http: (links only), mailto: or a path on this site.
 *
 * Supported: # to #### headings, paragraphs, - / * / 1. lists, > quotes,
 * --- rules, **bold**, *italic*, `code`, [text](url) and ![alt](url).
 */

function safeHref(url: string): string | null {
  const u = url.trim();
  if (/^(https?:\/\/|mailto:)/i.test(u)) return u;
  if (u.startsWith("/") && !u.startsWith("//")) return u;
  if (u.startsWith("#")) return u;
  return null;
}

function safeImage(url: string): string | null {
  const u = url.trim();
  if (/^https:\/\//i.test(u)) return u;
  if (u.startsWith("/") && !u.startsWith("//")) return u;
  return null;
}

const INLINE = /(!\[([^\]]*)\]\(([^)\s]+)\)|\[([^\]]+)\]\(([^)\s]+)\)|\*\*([^*]+)\*\*|\*([^*\s][^*]*)\*|_([^_\s][^_]*)_|`([^`]+)`)/;

export function inline(text: string, key = "i"): ReactNode[] {
  const out: ReactNode[] = [];
  let rest = text;
  let n = 0;
  while (rest) {
    const m = INLINE.exec(rest);
    if (!m) { out.push(rest); break; }
    if (m.index > 0) out.push(rest.slice(0, m.index));
    const k = `${key}-${n++}`;
    if (m[2] !== undefined && m[3] !== undefined) {
      const src = safeImage(m[3]);
      // eslint-disable-next-line @next/next/no-img-element -- editors use images hosted anywhere over https
      out.push(src ? <img key={k} src={src} alt={m[2]} loading="lazy" className="my-6 w-full rounded-xl border border-slate-200" /> : m[0]);
    } else if (m[4] !== undefined && m[5] !== undefined) {
      const href = safeHref(m[5]);
      const external = href ? /^https?:/i.test(href) : false;
      out.push(href
        ? <a key={k} href={href} className="font-semibold text-blue-700 underline underline-offset-2 hover:text-blue-800" {...(external ? { target: "_blank", rel: "noopener noreferrer nofollow" } : {})}>{inline(m[4], k)}</a>
        : m[4]);
    } else if (m[6] !== undefined) {
      out.push(<strong key={k} className="font-semibold text-slate-900">{inline(m[6], k)}</strong>);
    } else if (m[7] !== undefined || m[8] !== undefined) {
      out.push(<em key={k}>{inline((m[7] ?? m[8])!, k)}</em>);
    } else if (m[9] !== undefined) {
      out.push(<code key={k} className="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[0.9em] text-slate-800">{m[9]}</code>);
    }
    rest = rest.slice(m.index + m[0].length);
  }
  return out;
}

const HEADING = ["", "mt-10 text-3xl", "mt-10 text-2xl", "mt-8 text-xl", "mt-6 text-lg"];

export function Markdown({ source }: { source: string }) {
  const lines = source.replace(/\r\n?/g, "\n").split("\n");
  const blocks: ReactNode[] = [];
  let i = 0;
  let b = 0;

  while (i < lines.length) {
    const line = lines[i];
    if (!line.trim()) { i++; continue; }
    const key = `b${b++}`;

    const h = /^(#{1,4})\s+(.*)$/.exec(line);
    if (h) {
      const level = h[1].length;
      const Tag = (`h${Math.min(level + 1, 5)}`) as "h2" | "h3" | "h4" | "h5";
      blocks.push(<Tag key={key} className={`${HEADING[level]} font-bold tracking-tight text-slate-950`}>{inline(h[2], key)}</Tag>);
      i++; continue;
    }
    if (/^\s*(-{3,}|\*{3,})\s*$/.test(line)) { blocks.push(<hr key={key} className="my-8 border-slate-200" />); i++; continue; }

    if (/^\s*>/.test(line)) {
      const quote: string[] = [];
      while (i < lines.length && /^\s*>/.test(lines[i])) { quote.push(lines[i].replace(/^\s*>\s?/, "")); i++; }
      blocks.push(<blockquote key={key} className="border-l-4 border-blue-200 pl-4 italic text-slate-600">{inline(quote.join(" "), key)}</blockquote>);
      continue;
    }

    const bullet = /^\s*[-*]\s+/;
    const numbered = /^\s*\d+[.)]\s+/;
    if (bullet.test(line) || numbered.test(line)) {
      const ordered = numbered.test(line);
      const marker = ordered ? numbered : bullet;
      const items: string[] = [];
      while (i < lines.length && marker.test(lines[i])) { items.push(lines[i].replace(marker, "")); i++; }
      const List = ordered ? "ol" : "ul";
      blocks.push(
        <List key={key} className={`space-y-2 pl-6 ${ordered ? "list-decimal" : "list-disc"} marker:text-slate-400`}>
          {items.map((it, j) => <li key={j}>{inline(it, `${key}-${j}`)}</li>)}
        </List>,
      );
      continue;
    }

    const para: string[] = [];
    while (i < lines.length && lines[i].trim() && !/^(#{1,4}\s|\s*>|\s*[-*]\s+|\s*\d+[.)]\s+|\s*(-{3,}|\*{3,})\s*$)/.test(lines[i])) {
      para.push(lines[i].trim()); i++;
    }
    blocks.push(<p key={key}>{inline(para.join(" "), key)}</p>);
  }

  return <>{blocks}</>;
}
