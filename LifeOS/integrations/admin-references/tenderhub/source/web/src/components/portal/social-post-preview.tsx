"use client";

import { useCallback, useEffect, useState } from "react";
import { Globe, Heart, MessageCircle, Music2, Send, Share2, ThumbsUp } from "lucide-react";
import { Alert } from "@/components/console/ui";
import { Button, Modal } from "@/components/console/interactive";

type PreviewAccount = { connected: boolean; name: string | null; username: string | null };
type Preview =
  | { available: false; reason: string }
  | {
    available: true;
    post_id: number;
    version_number: number;
    poster_url: string | null;
    headline: string;
    platforms: { facebook: { text: string }; instagram: { text: string }; tiktok: { title: string; text: string } };
    accounts: Record<"facebook" | "instagram" | "tiktok", PreviewAccount>;
  };

/** Engine poster paths are served to staff through the admin storage proxy. */
const posterSrc = (url: string | null) => (url?.startsWith("/storage/") ? `/api/admin/marketing-storage/${url.replace("/storage/", "")}` : null);

function Avatar({ label }: { label: string }) {
  return <span className="grid size-8 shrink-0 place-items-center rounded-full bg-primary-600 text-xs font-bold text-white">{label.slice(0, 1).toUpperCase()}</span>;
}

function Artwork({ src, className = "" }: { src: string | null; className?: string }) {
  // eslint-disable-next-line @next/next/no-img-element
  return src ? <img src={src} alt="Post artwork" className={`w-full object-cover ${className}`} /> : <div className={`grid place-items-center bg-gray-100 text-xs text-gray-500 ${className}`}>Artwork unavailable</div>;
}

function NotConnected({ account, label }: { account: PreviewAccount; label: string }) {
  return account.connected ? null : <p className="mt-1 text-xs text-yellow-700">{label} is not connected. Connect the real account before enabling publishing.</p>;
}

/**
 * How the post will look on each platform: the real artwork and the exact text
 * each platform receives (same formatter as the publishing worker).
 */
export function PostPreviewModal({ sourceId, title, canGenerate, onClose }: {
  sourceId: number; title: string; canGenerate: boolean; onClose: () => void;
}) {
  const [preview, setPreview] = useState<Preview | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const request = useCallback(async (generate: boolean): Promise<{ data?: Preview; error?: string }> => {
    try {
      const res = await fetch(`/api/admin/marketing/social/tenders/${sourceId}/preview`, { method: generate ? "POST" : "GET", cache: "no-store" });
      const json = await res.json().catch(() => ({}));
      return res.ok && json.success ? { data: json.data } : { error: json.error ?? "The preview could not be loaded." };
    } catch {
      return { error: "Could not reach the marketing engine." };
    }
  }, [sourceId]);

  // Read-only load on open: shows an existing draft, never creates one.
  useEffect(() => {
    let live = true;
    void request(false).then((r) => {
      if (!live) return;
      if (r.data) setPreview(r.data);
      else setError(r.error ?? null);
    });
    return () => { live = false; };
  }, [request]);

  async function generate() {
    setBusy(true);
    setError(null);
    const r = await request(true);
    if (r.data) setPreview(r.data);
    else setError(r.error ?? null);
    setBusy(false);
  }

  const ready = preview?.available ? preview : null;
  const src = ready ? posterSrc(ready.poster_url) : null;
  const name = (a: PreviewAccount, _fallback: string) => a.name || a.username || "Not connected";

  return (
    <Modal
      open
      onClose={onClose}
      title="Post preview"
      size="xl"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Close</Button>
          {canGenerate && ready && (
            <Button variant="soft" busy={busy} onClick={generate} className="sm:mr-auto">
              Regenerate artwork
            </Button>
          )}
        </>
      }
    >
      <p className="font-semibold text-gray-900">{title}</p>
      {!preview && !error && <p role="status" className="mt-3 text-sm text-gray-500">Loading preview…</p>}
      {error && <div className="mt-3"><Alert tone="error" title={error} /></div>}
      {preview && !preview.available && (
        <div className="mt-3 rounded-lg bg-gray-50 p-3 text-sm text-gray-700">
          <p>{preview.reason}</p>
          {canGenerate
            ? <Button className="mt-2" size="sm" busy={busy} onClick={generate}>Generate preview</Button>
            : <p className="mt-1 text-xs text-yellow-700">This tender cannot be promoted, so no post can be generated.</p>}
          <p className="mt-2 text-xs text-gray-500">Generating creates the artwork and caption from the tender. Nothing is posted and no automation is enabled.</p>
        </div>
      )}
      {ready && (
        <>
          <p className="mt-1 text-xs text-gray-500">Post version {ready.version_number}. This is the exact text each platform receives; edit it from the post row after enabling automation.</p>
          <div className="mt-4 grid gap-4 lg:grid-cols-3">
            {/* Facebook Page post */}
            <figure className="overflow-hidden rounded-lg border border-gray-200 bg-white text-sm shadow-sm">
              <figcaption className="border-b border-gray-100 bg-gray-50 px-3 py-1.5 text-xs font-bold uppercase tracking-wide text-gray-500">Facebook</figcaption>
              <div className="flex items-center gap-2 px-3 pt-3">
                <Avatar label={name(ready.accounts.facebook, "?")} />
                <div><p className="font-semibold text-gray-900">{name(ready.accounts.facebook, "TenderHub.lk")}</p><p className="flex items-center gap-1 text-xs text-gray-500">Just now · <Globe className="size-3" /></p></div>
              </div>
              <div className="px-3"><NotConnected account={ready.accounts.facebook} label="Facebook" /></div>
              <p className="max-h-96 overflow-y-auto whitespace-pre-wrap px-3 py-2 text-gray-800">{ready.platforms.facebook.text}</p>
              <Artwork src={src} className="aspect-square" />
              <div className="flex justify-around border-t border-gray-100 py-2 text-xs font-semibold text-gray-500">
                <span className="flex items-center gap-1"><ThumbsUp className="size-4" />Like</span><span className="flex items-center gap-1"><MessageCircle className="size-4" />Comment</span><span className="flex items-center gap-1"><Share2 className="size-4" />Share</span>
              </div>
            </figure>

            {/* Instagram post */}
            <figure className="overflow-hidden rounded-lg border border-gray-200 bg-white text-sm shadow-sm">
              <figcaption className="border-b border-gray-100 bg-gray-50 px-3 py-1.5 text-xs font-bold uppercase tracking-wide text-gray-500">Instagram</figcaption>
              <div className="flex items-center gap-2 px-3 py-2"><Avatar label={ready.accounts.instagram.username || "?"} /><p className="font-semibold text-gray-900">{ready.accounts.instagram.username || "Not connected"}</p></div>
              <div className="px-3"><NotConnected account={ready.accounts.instagram} label="Instagram" /></div>
              <Artwork src={src} className="aspect-square" />
              <div className="flex gap-3 px-3 pt-2 text-gray-800"><Heart className="size-5" /><MessageCircle className="size-5" /><Send className="size-5" /></div>
              <p className="max-h-96 overflow-y-auto whitespace-pre-wrap px-3 py-2 text-gray-800">{ready.platforms.instagram.text}</p>
            </figure>

            {/* TikTok photo post */}
            <figure className="overflow-hidden rounded-lg border border-gray-200 bg-white text-sm shadow-sm">
              <figcaption className="border-b border-gray-100 bg-gray-50 px-3 py-1.5 text-xs font-bold uppercase tracking-wide text-gray-500">TikTok</figcaption>
              <div className="relative mx-auto aspect-[9/16] max-h-[520px] bg-black">
                <div className="absolute inset-0 flex items-center"><Artwork src={src} className="aspect-square" /></div>
                <div className="absolute right-2 bottom-24 flex flex-col items-center gap-3 text-white"><Heart className="size-6" /><MessageCircle className="size-6" /><Share2 className="size-6" /></div>
                <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/85 to-transparent p-3 text-white">
                  <p className="font-semibold">{ready.accounts.tiktok.username ? `@${ready.accounts.tiktok.username}` : "Not connected"}</p>
                  <p className="mt-0.5 font-bold">{ready.platforms.tiktok.title}</p>
                  <p className="mt-0.5 max-h-64 overflow-y-auto whitespace-pre-wrap text-xs text-white/90">{ready.platforms.tiktok.text}</p>
                  <p className="mt-1 flex items-center gap-1 text-xs text-white/80"><Music2 className="size-3" />Photo post</p>
                </div>
              </div>
              <div className="px-3 pb-2"><NotConnected account={ready.accounts.tiktok} label="TikTok" /></div>
            </figure>
          </div>
        </>
      )}
    </Modal>
  );
}
