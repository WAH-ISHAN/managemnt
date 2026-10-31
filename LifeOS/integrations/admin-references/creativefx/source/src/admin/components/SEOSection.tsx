import React from 'react';
import { Search, Globe, ExternalLink, CheckCircle2, Link2, Plus, Trash2 } from 'lucide-react';
import { TextInput, TextArea, CollapseSection, ImageUpload, ToggleSwitch, PageHeader } from './AdminFields';
import type { SiteContent } from '../../context/ContentContext';

interface SEOSectionProps {
  content: SiteContent;
  update: (path: string[], value: any) => void;
}

export const SEOSection: React.FC<SEOSectionProps> = ({ content, update }) => {
  const seo = { ...content.seo };
  const backlinks = seo.backlinks || [];
  const sitemapUrl = `${(seo.canonicalUrl || 'https://creativefx.lk').replace(/\/$/, '')}/sitemap.xml`;

  const verificationToken = (value: string) => {
    const trimmed = value.trim();
    const contentMatch = trimmed.match(/content=["']([^"']+)["']/i);
    return contentMatch?.[1]?.trim() || trimmed;
  };

  const addBacklink = () => update(['seo', 'backlinks'], [
    ...backlinks,
    {
      id: `backlink-${Date.now()}`,
      sourceUrl: '',
      targetUrl: seo.canonicalUrl || 'https://creativefx.lk',
      anchorText: '',
      status: 'planned' as const,
      notes: '',
    },
  ]);

  const patchBacklink = (index: number, field: string, value: string) => {
    update(['seo', 'backlinks'], backlinks.map((item, itemIndex) => (
      itemIndex === index ? { ...item, [field]: value } : item
    )));
  };

  return (
    <div className="space-y-6">
      <PageHeader
        icon={Search}
        title="Search engine optimization"
        description="Metadata used by Google and by social platforms when your links are shared."
      />

      {/* Google Search Live Preview */}
      <div className="border border-gray-200 rounded-lg bg-white p-6 space-y-3">
        <span className="text-xs font-medium text-gray-500 flex items-center gap-1.5">
          <Globe className="w-3.5 h-3.5" /> Google search result preview
        </span>
        <div className="bg-[#202124] p-4 rounded-md space-y-1 font-mono-tech max-w-2xl">
          <div className="text-xs text-[#bdc1c6] flex items-center gap-1.5">
            <span className="w-4 h-4 rounded-full bg-white/10 flex items-center justify-center text-[10px] text-white">FX</span>
            <span>{seo.canonicalUrl?.replace(/^https?:\/\//, '') || 'creativefx.lk'}</span>
            <span className="text-gray-500">›</span>
          </div>
          <h4 className="text-[#8ab4f8] text-base hover:underline cursor-pointer font-medium leading-snug line-clamp-1">
            {seo.title}
          </h4>
          <p className="text-xs text-[#bdc1c6] line-clamp-2 leading-relaxed">
            {seo.description}
          </p>
        </div>
      </div>

      {/* Primary Meta Tags */}
      <CollapseSection title="Primary metadata" defaultOpen>
        <TextInput
          label="Meta title (max 65 characters)"
          value={seo.title}
          onChange={v => update(['seo', 'title'], v)}
          hint={`${(seo.title || '').length}/65 characters`}
        />
        <TextArea
          label="Meta description (max 160 characters)"
          value={seo.description}
          onChange={v => update(['seo', 'description'], v)}
          rows={3}
          hint={`${(seo.description || '').length}/160 characters`}
        />
        <TextInput
          label="Target keywords (comma-separated)"
          value={seo.keywords}
          onChange={v => update(['seo', 'keywords'], v)}
        />
        <TextInput
          label="Canonical website URL"
          value={seo.canonicalUrl}
          onChange={v => update(['seo', 'canonicalUrl'], v)}
          placeholder="https://yourdomain.lk"
        />
      </CollapseSection>

      <CollapseSection title="Search engine connections" defaultOpen badge={`${Number(!!seo.searchConsoleId) + Number(!!seo.bingWebmasterId)}/2 ready`}>
        <div className="rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-xs leading-relaxed text-blue-900">
          Add the site in each official webmaster dashboard, paste the verification token here, publish the website, then return to that dashboard and click Verify. Account passwords are never stored in CreativeFX.
        </div>

        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <div className="rounded-lg border border-gray-200 bg-white p-4">
            <div className="flex items-start justify-between gap-3">
              <div>
                <h4 className="text-sm font-semibold text-gray-900">Google Search Console</h4>
                <p className="mt-1 text-xs text-gray-500">Indexing, Google search performance, links and sitemap reports.</p>
              </div>
              <span className={`inline-flex items-center gap-1 rounded-full px-2 py-1 text-[10px] font-semibold ${seo.searchConsoleId ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-500'}`}>
                {seo.searchConsoleId && <CheckCircle2 className="h-3 w-3" />} {seo.searchConsoleId ? 'Token ready' : 'Not connected'}
              </span>
            </div>
            <div className="mt-4">
              <TextInput
                label="Google verification token"
                value={seo.searchConsoleId || ''}
                onChange={value => update(['seo', 'searchConsoleId'], verificationToken(value))}
                placeholder="Paste the token or full meta tag"
                hint="Saved as google-site-verification in the website head"
              />
            </div>
            <a
              href="https://search.google.com/search-console"
              target="_blank"
              rel="noreferrer"
              className="mt-4 inline-flex min-h-10 w-full items-center justify-center gap-2 rounded-md bg-gray-900 px-4 text-xs font-semibold text-white transition hover:bg-gray-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
            >
              Sign in / open Search Console <ExternalLink className="h-3.5 w-3.5" />
            </a>
          </div>

          <div className="rounded-lg border border-gray-200 bg-white p-4">
            <div className="flex items-start justify-between gap-3">
              <div>
                <h4 className="text-sm font-semibold text-gray-900">Bing Webmaster Tools</h4>
                <p className="mt-1 text-xs text-gray-500">Bing indexing, search reports, backlinks and sitemap tools.</p>
              </div>
              <span className={`inline-flex items-center gap-1 rounded-full px-2 py-1 text-[10px] font-semibold ${seo.bingWebmasterId ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-500'}`}>
                {seo.bingWebmasterId && <CheckCircle2 className="h-3 w-3" />} {seo.bingWebmasterId ? 'Token ready' : 'Not connected'}
              </span>
            </div>
            <div className="mt-4">
              <TextInput
                label="Bing verification token"
                value={seo.bingWebmasterId || ''}
                onChange={value => update(['seo', 'bingWebmasterId'], verificationToken(value))}
                placeholder="Paste the token or full meta tag"
                hint="Saved as msvalidate.01 in the website head"
              />
            </div>
            <a
              href="https://www.bing.com/webmasters/"
              target="_blank"
              rel="noreferrer"
              className="mt-4 inline-flex min-h-10 w-full items-center justify-center gap-2 rounded-md border border-gray-300 bg-white px-4 text-xs font-semibold text-gray-800 transition hover:border-gray-500 hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2"
            >
              Sign in / open Bing Webmaster <ExternalLink className="h-3.5 w-3.5" />
            </a>
          </div>
        </div>

        <div className="rounded-lg border border-gray-200 bg-gray-50 p-4">
          <p className="text-xs font-semibold uppercase tracking-wide text-gray-700">Sitemap to submit</p>
          <div className="mt-2 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <code className="break-all rounded border border-gray-200 bg-white px-3 py-2 text-xs text-gray-700">{sitemapUrl}</code>
            <a href={sitemapUrl} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-700 hover:text-gray-950">
              Open sitemap <ExternalLink className="h-3.5 w-3.5" />
            </a>
          </div>
        </div>
      </CollapseSection>

      <CollapseSection title="Backlink tracker" badge={`${backlinks.length} links`}>
        <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
          <div>
            <p className="text-sm font-medium text-gray-900">Track links pointing to CreativeFX</p>
            <p className="mt-0.5 text-xs text-gray-500">Use Google and Bing for discovered-link reports; use this list for outreach and manual link monitoring.</p>
          </div>
          <button type="button" onClick={addBacklink} className="inline-flex min-h-10 items-center justify-center gap-2 rounded-md bg-gray-900 px-4 text-xs font-semibold text-white transition hover:bg-gray-700">
            <Plus className="h-4 w-4" /> Add backlink
          </button>
        </div>

        {backlinks.length === 0 ? (
          <div className="rounded-lg border border-dashed border-gray-300 bg-gray-50 px-5 py-10 text-center">
            <Link2 className="mx-auto h-7 w-7 text-gray-300" />
            <p className="mt-3 text-sm font-medium text-gray-700">No backlinks tracked yet</p>
            <p className="mt-1 text-xs text-gray-500">Add partner pages, directories, press mentions or outreach targets here.</p>
          </div>
        ) : (
          <div className="space-y-3">
            {backlinks.map((item, index) => (
              <div key={item.id} className="rounded-lg border border-gray-200 bg-white p-4">
                <div className="mb-3 flex items-center justify-between gap-3">
                  <span className="text-xs font-semibold text-gray-600">Backlink {String(index + 1).padStart(2, '0')}</span>
                  <button type="button" onClick={() => update(['seo', 'backlinks'], backlinks.filter((_, i) => i !== index))} aria-label={`Remove backlink ${index + 1}`} className="rounded-md border border-red-200 p-2 text-red-600 transition hover:bg-red-50">
                    <Trash2 className="h-3.5 w-3.5" />
                  </button>
                </div>
                <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                  <TextInput label="Source page URL" value={item.sourceUrl} onChange={value => patchBacklink(index, 'sourceUrl', value)} placeholder="https://partner-site.com/article" />
                  <TextInput label="Target page on CreativeFX" value={item.targetUrl} onChange={value => patchBacklink(index, 'targetUrl', value)} placeholder="https://creativefx.lk/..." />
                  <TextInput label="Anchor text" value={item.anchorText} onChange={value => patchBacklink(index, 'anchorText', value)} placeholder="CreativeFX photography" />
                  <div>
                    <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-700">Status</label>
                    <select value={item.status} onChange={event => patchBacklink(index, 'status', event.target.value)} className="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 outline-none focus:border-gray-900 focus:ring-1 focus:ring-gray-400">
                      <option value="planned">Planned / outreach</option>
                      <option value="live">Live</option>
                      <option value="lost">Lost / removed</option>
                    </select>
                  </div>
                </div>
                <div className="mt-3">
                  <TextInput label="Notes" value={item.notes} onChange={value => patchBacklink(index, 'notes', value)} placeholder="Contact, follow-up date or campaign note" />
                </div>
              </div>
            ))}
          </div>
        )}
      </CollapseSection>

      {/* Social Media OpenGraph Card */}
      <CollapseSection title="Social share card (Open Graph)" defaultOpen>
        <div className="space-y-4">
          <p className="text-sm text-gray-500">
            Image shown when your website link is shared on WhatsApp, Facebook, LinkedIn and other platforms.
          </p>
          <ImageUpload
            label="Share banner image (1200 × 630px recommended)"
            currentSrc={seo.ogImage}
            onUpload={url => update(['seo', 'ogImage'], url)}
          />
        </div>
      </CollapseSection>

      {/* Indexing */}
      <ToggleSwitch
        label="Allow search engine indexing"
        checked={seo.allowIndexing ?? true}
        onChange={v => update(['seo', 'allowIndexing'], v)}
        description="When disabled, search engines are asked not to index the site"
      />
    </div>
  );
};
