export function lkr(v: number | null | undefined, short = false): string {
  if (v === null || v === undefined) return "—";
  if (short) {
    if (v >= 1_000_000_000) return `Rs. ${(v / 1_000_000_000).toFixed(2)} B`;
    if (v >= 1_000_000) return `Rs. ${(v / 1_000_000).toFixed(1)} M`;
    if (v >= 1_000) return `Rs. ${(v / 1_000).toFixed(0)} K`;
  }
  return `Rs. ${v.toLocaleString("en-LK", { maximumFractionDigits: 0 })}`;
}

export function bytes(n: number | null | undefined): string {
  if (!n) return "—";
  if (n >= 1_048_576) return `${(n / 1_048_576).toFixed(1)} MB`;
  if (n >= 1024) return `${(n / 1024).toFixed(0)} KB`;
  return `${n} B`;
}

const MONTHS = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];

function parse(v: string | null | undefined): Date | null {
  if (!v) return null;
  const iso = v.includes("T") ? v : v.replace(" ", "T") + "Z";
  const d = new Date(iso);
  return isNaN(d.getTime()) ? null : d;
}

export function date(v: string | null | undefined): string {
  const d = parse(v);
  if (!d) return "—";
  return `${d.getUTCDate()} ${MONTHS[d.getUTCMonth()]} ${d.getUTCFullYear()}`;
}

export function dateTime(v: string | null | undefined): string {
  const d = parse(v);
  if (!d) return "—";
  const hh = String(d.getUTCHours()).padStart(2, "0");
  const mm = String(d.getUTCMinutes()).padStart(2, "0");
  return `${date(v)}, ${hh}:${mm}`;
}

export function dateTimeColombo(v: string | null | undefined): string {
  const d = parse(v);
  if (!d) return "—";
  return new Intl.DateTimeFormat("en-GB", {
    timeZone: "Asia/Colombo",
    day: "numeric",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).format(d);
}

/**
 * The countdown is computed against SERVER time (meta.now), never the browser
 * clock. A deadline is the one thing this product cannot get wrong.
 */
export function countdown(closing: string | null | undefined, serverNow: string): string {
  const c = parse(closing);
  const n = parse(serverNow);
  if (!c || !n) return "—";
  const ms = c.getTime() - n.getTime();
  if (ms <= 0) return "Closed";
  const days = Math.floor(ms / 86_400_000);
  const hours = Math.floor((ms % 86_400_000) / 3_600_000);
  if (days > 30) return `${days} days left`;
  if (days >= 1) return `${days}d ${hours}h left`;
  const mins = Math.floor((ms % 3_600_000) / 60_000);
  return `${hours}h ${mins}m left`;
}

export function titleCase(s: string): string {
  return s.replace(/[-_]/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
}
