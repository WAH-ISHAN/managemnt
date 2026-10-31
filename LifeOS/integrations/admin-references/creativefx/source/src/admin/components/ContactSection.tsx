import React, { useEffect, useMemo, useState } from 'react';
import { Mail, MessageSquare, Trash2, MessageCircle, Loader, Inbox, Search, Plus, Star, ExternalLink, RefreshCw } from 'lucide-react';
import { TextInput, TextArea, CollapseSection, PageHeader, EmptyState, ImageUpload } from './AdminFields';
import type { SiteContent } from '../../context/ContentContext';
import { API_BASE } from '../../context/ContentContext';

interface ContactSectionProps {
  content: SiteContent;
  update: (path: string[], value: any) => void;
}

type InquiryStatus = 'new' | 'contacted' | 'in-progress' | 'completed' | 'archived';

interface InquiryRecord {
  id: string;
  name: string;
  email?: string;
  phone?: string;
  service?: string;
  message: string;
  status: InquiryStatus | 'replied';
  source?: string;
  createdAt: string;
}

const STATUS_LABELS: Record<string, string> = {
  new: 'New',
  contacted: 'Contacted',
  'in-progress': 'In Progress',
  completed: 'Completed',
  archived: 'Archived',
};

const STATUS_BADGES: Record<string, string> = {
  new: 'bg-green-50 text-green-700 border-green-200',
  contacted: 'bg-blue-50 text-blue-700 border-blue-200',
  'in-progress': 'bg-amber-50 text-amber-700 border-amber-200',
  completed: 'bg-emerald-50 text-emerald-700 border-emerald-200',
  archived: 'bg-gray-100 text-gray-500 border-gray-200',
  replied: 'bg-blue-50 text-blue-700 border-blue-200',
};

const FILTERS = ['all', 'new', 'contacted', 'in-progress', 'completed', 'archived'] as const;
type Filter = typeof FILTERS[number];

function formatDate(iso: string): string {
  const d = new Date(iso);
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) + ' · ' +
    d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
}

export const ContactInquiriesSection: React.FC<ContactSectionProps> = ({ content, update }) => {
  const [inquiries, setInquiries] = useState<InquiryRecord[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState(false);
  const [filter, setFilter] = useState<Filter>('all');
  const [inboxQuery, setInboxQuery] = useState('');
  const [sortDesc, setSortDesc] = useState(true);
  const [page, setPage] = useState(0);
  const INBOX_PAGE = 6;

  // ── Testimonials editor state ──
  const testimonials = content.testimonials || [];

  const patchTestimonial = (idx: number, key: string, value: string) => {
    const next = testimonials.map((t, i) => (i === idx ? { ...t, [key]: value } : t));
    update(['testimonials'], next);
  };

  const moveTestimonial = (idx: number, dir: -1 | 1) => {
    const t = idx + dir;
    if (t < 0 || t >= testimonials.length) return;
    const next = [...testimonials];
    [next[idx], next[t]] = [next[t], next[idx]];
    update(['testimonials'], next);
  };

  const loadInquiries = () => {
    setLoading(true);
    setLoadError(false);
    fetch(`${API_BASE}/api/inquiries`, { headers: { 'x-admin-token': sessionStorage.getItem('cfx_admin_token') || '' } })
      .then(r => {
        if (!r.ok) throw new Error('failed');
        return r.json();
      })
      .then((list: InquiryRecord[]) => setInquiries(Array.isArray(list) ? list : []))
      .catch(() => setLoadError(true))
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    loadInquiries();
  }, []);

  const handleDeleteInquiry = async (id: string) => {
    if (!confirm('Delete this inquiry? This cannot be undone.')) return;
    setInquiries(prev => prev.filter(i => i.id !== id));
    try {
      await fetch(`${API_BASE}/api/inquiries/${id}`, {
        method: 'DELETE',
        headers: { 'x-admin-token': sessionStorage.getItem('cfx_admin_token') || '' },
      });
    } catch {
      // Optimistic delete — refresh will reconcile
    }
  };

  const handleStatusChange = async (id: string, status: string) => {
    setInquiries(prev => prev.map(i => (i.id === id ? { ...i, status: status as InquiryRecord['status'] } : i)));
    try {
      await fetch(`${API_BASE}/api/inquiries/${id}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json', 'x-admin-token': sessionStorage.getItem('cfx_admin_token') || '' },
        body: JSON.stringify({ status }),
      });
    } catch {
      // Optimistic update — refresh will reconcile
    }
  };

  const searched = useMemo(() => {
    const q = inboxQuery.trim().toLowerCase();
    const list = inquiries.filter(i =>
      filter === 'all' ? true : filter === 'contacted'
        ? (i.status === 'contacted' || i.status === 'replied')
        : i.status === filter
    );
    const withMatch = q
      ? list.filter(i =>
          i.name?.toLowerCase().includes(q) ||
          i.email?.toLowerCase().includes(q) ||
          i.phone?.toLowerCase().includes(q) ||
          i.service?.toLowerCase().includes(q) ||
          i.message?.toLowerCase().includes(q))
      : list;
    return [...withMatch].sort((a, b) => sortDesc
      ? b.createdAt.localeCompare(a.createdAt)
      : a.createdAt.localeCompare(b.createdAt));
  }, [inquiries, filter, inboxQuery, sortDesc]);

  const pageCount = Math.max(1, Math.ceil(searched.length / INBOX_PAGE));
  const safePage = Math.min(page, pageCount - 1);
  const paged = searched.slice(safePage * INBOX_PAGE, safePage * INBOX_PAGE + INBOX_PAGE);
  const newCount = inquiries.filter(i => i.status === 'new').length;

  return (
    <div className="space-y-6">
      <PageHeader
        icon={MessageSquare}
        title="Contact & inquiries"
        description="Messages submitted through the website contact form and project inquiry drawer."
      />

      {/* INBOX */}
      <div className="border border-gray-200 rounded-lg bg-white">
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 px-5 py-4 border-b border-gray-100">
          <div className="flex items-center gap-2">
            <h3 className="text-sm font-semibold text-gray-900">Inbox</h3>
            {newCount > 0 && (
              <span className="text-[11px] font-medium px-2 py-0.5 rounded-full bg-green-50 text-green-700 border border-green-200">
                {newCount} new
              </span>
            )}
          </div>
          <div className="flex items-center flex-wrap gap-1 bg-gray-100 rounded-md p-0.5 w-fit text-xs">
            {FILTERS.map(f => (
              <button
                key={f}
                onClick={() => { setFilter(f as Filter); setPage(0); }}
                className={`px-2.5 py-1 rounded capitalize transition-colors cursor-pointer ${
                  filter === f ? 'bg-white text-gray-900 font-medium shadow-sm' : 'text-gray-500 hover:text-gray-900'
                }`}
              >
                {f === 'all' ? 'All' : STATUS_LABELS[f] ?? f}
              </button>
            ))}
          </div>
        </div>

        {/* Search & sort toolbar */}
        <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 px-5 py-3 border-b border-gray-100 bg-gray-50/60">
          <div className="relative w-full sm:w-72">
            <Search className="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" />
            <input
              type="text"
              value={inboxQuery}
              onChange={e => { setInboxQuery(e.target.value); setPage(0); }}
              placeholder="Search name, email, message…"
              className="w-full bg-white border border-gray-300 rounded-md pl-9 pr-3 py-1.5 text-xs text-gray-900 placeholder:text-gray-400 focus:outline-none focus:border-gray-900"
            />
          </div>
          <select
            value={sortDesc ? 'newest' : 'oldest'}
            onChange={e => setSortDesc(e.target.value === 'newest')}
            className="bg-white border border-gray-300 rounded-md px-3 py-1.5 text-xs text-gray-700 focus:outline-none focus:border-gray-900 cursor-pointer"
          >
            <option value="newest">Sort: Newest first</option>
            <option value="oldest">Sort: Oldest first</option>
          </select>
        </div>

        <div className="divide-y divide-gray-100">
          {loading && (
            <div className="px-5 py-12 flex items-center justify-center gap-2 text-sm text-gray-400">
              <Loader className="w-4 h-4 animate-spin" /> Loading inquiries…
            </div>
          )}

          {!loading && loadError && (
            <div className="px-5 py-10 text-center">
              <p className="text-sm font-medium text-gray-700">Could not reach the API server</p>
              <p className="text-xs text-gray-500 mt-1">Start the backend (node server.cjs) and try again.</p>
              <button onClick={loadInquiries} className="mt-3 text-xs font-medium text-gray-900 underline underline-offset-2">
                Retry
              </button>
            </div>
          )}

          {!loading && !loadError && searched.length === 0 && (
            <div className="p-6">
              <EmptyState
                icon={Inbox}
                title={filter === 'all' ? 'No inquiries yet' : `No ${STATUS_LABELS[filter]?.toLowerCase() ?? ''} inquiries`}
                description="New messages from the website contact form and wedding inquiry modal appear here automatically."
              />
            </div>
          )}

          {!loading && paged.map(inq => (
            <div key={inq.id} className="px-5 py-4 space-y-3 hover:bg-gray-50 transition-colors">
              <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-2">
                <div>
                  <div className="flex items-center gap-2">
                    <span className="text-sm font-semibold text-gray-900">{inq.name}</span>
                    <span className={`text-[10px] font-medium px-2 py-0.5 rounded-full border ${STATUS_BADGES[inq.status] ?? STATUS_BADGES.new}`}>
                      {STATUS_LABELS[inq.status] ?? inq.status}
                    </span>
                  </div>
                  {inq.service && <div className="text-xs text-gray-500 mt-0.5">{inq.service}</div>}
                </div>
                <select
                  value={STATUS_LABELS[inq.status] ? inq.status : 'contacted'}
                  onChange={e => handleStatusChange(inq.id, e.target.value)}
                  className="text-xs border-gray-300 rounded-md px-2 py-1.5 text-gray-700 focus:outline-none focus:border-gray-900"
                >
                  {Object.entries(STATUS_LABELS).map(([value, label]) => (
                    <option key={value} value={value}>{label}</option>
                  ))}
                </select>
              </div>

              <p className="text-sm text-gray-600 bg-gray-50 border border-gray-100 rounded-md p-3 leading-relaxed whitespace-pre-wrap">
                {inq.message}
              </p>

              <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="text-xs text-gray-500">
                  {formatDate(inq.createdAt)}
                  {inq.source && <> · via {inq.source}</>}
                  {inq.phone && <> · Phone: <a href={`tel:${inq.phone}`} className="text-gray-900 hover:underline">{inq.phone}</a></>}
                  {inq.email && <> · Email: <a href={`mailto:${inq.email}`} className="text-gray-900 hover:underline">{inq.email}</a></>}
                </div>
                <div className="flex items-center gap-2">
                  {inq.phone && (
                    <a
                      href={`https://wa.me/${inq.phone.replace(/[^0-9]/g, '')}?text=Hi%20${encodeURIComponent(inq.name)}%2C%20thank%20you%20for%20contacting%20CreativeFX.`}
                      target="_blank"
                      rel="noreferrer"
                      onClick={() => handleStatusChange(inq.id, 'contacted')}
                      className="inline-flex items-center gap-1.5 bg-green-50 text-green-700 border border-green-200 px-3 py-1.5 text-[11px] font-medium rounded-md hover:bg-green-100 transition-colors"
                    >
                      <MessageCircle className="w-3.5 h-3.5" /> WhatsApp
                    </a>
                  )}
                  {inq.email && (
                    <a
                      href={`mailto:${inq.email}?subject=Re: your CreativeFX inquiry${inq.service ? ` — ${inq.service}` : ''}`}
                      onClick={() => handleStatusChange(inq.id, 'contacted')}
                      className="inline-flex items-center gap-1.5 bg-white text-gray-700 border border-gray-300 px-3 py-1.5 text-[11px] font-medium rounded-md hover:border-gray-900 hover:text-gray-900 transition-colors"
                    >
                      <Mail className="w-3.5 h-3.5" /> Reply by email
                    </a>
                  )}
                  <button
                    onClick={() => handleDeleteInquiry(inq.id)}
                    className="p-1.5 text-gray-400 hover:text-red-600 transition-colors rounded-md"
                    title="Delete inquiry"
                  >
                    <Trash2 className="w-3.5 h-3.5" />
                  </button>
                </div>
              </div>
            </div>
          ))}
        </div>

        {/* Pagination */}
        {pageCount > 1 && (
          <div className="flex items-center justify-between px-5 py-3 border-t border-gray-100">
            <button
              type="button"
              disabled={safePage === 0}
              onClick={() => setPage(p => Math.max(0, p - 1))}
              className="px-3 py-1.5 text-xs font-medium bg-white border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-40 cursor-pointer disabled:cursor-not-allowed"
            >
              ← Previous
            </button>
            <span className="text-[11px] text-gray-500 font-mono">Page {safePage + 1} of {pageCount} · {searched.length} total</span>
            <button
              type="button"
              disabled={safePage >= pageCount - 1}
              onClick={() => setPage(p => Math.min(pageCount - 1, p + 1))}
              className="px-3 py-1.5 text-xs font-medium bg-white border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-40 cursor-pointer disabled:cursor-not-allowed"
            >
              Next →
            </button>
          </div>
        )}
      </div>

      {/* GOOGLE REVIEWS & CLIENT TESTIMONIALS */}
      <CollapseSection title="Google Reviews & Client Testimonials" badge={`${testimonials.length} reviews`} defaultOpen>
        
        {/* Google Place Connection Bar */}
        <div className="bg-gradient-to-r from-amber-50 to-orange-50 border border-amber-200/80 rounded-xl p-4 space-y-3">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div className="flex items-center gap-2">
              <div className="w-6 h-6 rounded-full bg-white border border-amber-200 shadow-2xs flex items-center justify-center">
                <svg className="w-3.5 h-3.5" viewBox="0 0 24 24">
                  <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                  <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                  <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
                  <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
                </svg>
              </div>
              <div>
                <h4 className="text-xs font-bold text-gray-900 uppercase tracking-wide flex items-center gap-1.5">
                  Connected Google Business Reviews
                  <span className="px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-800 text-[10px] font-bold">CONNECTED</span>
                </h4>
                <p className="text-[11px] text-gray-600">CreativeFX (Pvt) Ltd · Kaduwela, Sri Lanka</p>
              </div>
            </div>
            <a
              href={content.googleReviewsConfig?.placeUrl || "https://www.google.com/search?q=creativefx+pvt+ltd+kaduwela+reviews#lrd=0xbfe9d365346670d:0x60fdaf92bd3171c7,1"}
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex items-center gap-1 text-xs font-semibold text-blue-600 hover:text-blue-800 bg-white border border-blue-200 px-3 py-1.5 rounded-md shadow-2xs transition-colors"
            >
              Test Live Google Link <ExternalLink className="w-3 h-3" />
            </a>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2 border-t border-amber-200/60">
            <TextInput
              label="Google Reviews Search URL"
              value={content.googleReviewsConfig?.placeUrl || 'https://www.google.com/search?q=creativefx+pvt+ltd+kaduwela+reviews#lrd=0xbfe9d365346670d:0x60fdaf92bd3171c7,1'}
              onChange={v => update(['googleReviewsConfig', 'placeUrl'], v)}
              placeholder="https://www.google.com/search?q=...#lrd=..."
            />
            <TextInput
              label="Write Review Direct Link"
              value={content.googleReviewsConfig?.writeReviewUrl || 'https://www.google.com/search?q=creativefx+pvt+ltd+kaduwela+reviews#lrd=0xbfe9d365346670d:0x60fdaf92bd3171c7,3'}
              onChange={v => update(['googleReviewsConfig', 'writeReviewUrl'], v)}
              placeholder="https://www.google.com/search?q=...#lrd=...,3"
            />
          </div>
        </div>

        {/* Reviews List */}
        {testimonials.map((t: any, idx: number) => (
          <div key={idx} className="border border-gray-200 rounded-lg p-4 space-y-3 bg-white">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2">
                <span className="text-xs font-semibold text-gray-700 uppercase tracking-wide">Review #{idx + 1}</span>
                <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-blue-50 text-blue-700 text-[10px] font-bold border border-blue-200">
                  <Star className="w-2.5 h-2.5 fill-amber-400 text-amber-400" /> 5.0 Star Google Verified
                </span>
              </div>
              <div className="flex items-center gap-1.5">
                <button type="button" onClick={() => moveTestimonial(idx, -1)} disabled={idx === 0}
                  className="px-2 py-1 text-xs bg-gray-50 border border-gray-200 rounded hover:bg-gray-100 disabled:opacity-30 cursor-pointer">▲</button>
                <button type="button" onClick={() => moveTestimonial(idx, 1)} disabled={idx === testimonials.length - 1}
                  className="px-2 py-1 text-xs bg-gray-50 border border-gray-200 rounded hover:bg-gray-100 disabled:opacity-30 cursor-pointer">▼</button>
                <button
                  type="button"
                  onClick={() => { if (confirm('Delete this review?')) update(['testimonials'], testimonials.filter((_: any, i: number) => i !== idx)); }}
                  className="p-1.5 text-red-500 hover:bg-red-50 rounded-md border border-red-200 cursor-pointer"
                >
                  <Trash2 className="w-3.5 h-3.5" />
                </button>
              </div>
            </div>
            <TextArea label="Review text / Quote" value={t.quote || ''} onChange={v => patchTestimonial(idx, 'quote', v)} rows={3} />
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <TextInput label="Author Name" value={t.author || ''} onChange={v => patchTestimonial(idx, 'author', v)} />
              <TextInput label="Role / Project Type" value={t.role || ''} onChange={v => patchTestimonial(idx, 'role', v)} />
            </div>
            <ImageUpload label="Client Avatar / Photo" currentSrc={t.avatar || ''} onUpload={url => patchTestimonial(idx, 'avatar', url)} />
          </div>
        ))}

        <button
          type="button"
          onClick={() => update(['testimonials'], [...(testimonials || []), { quote: '', author: '', role: '', avatar: '', rating: 5, verified: true, source: 'google' }])}
          className="w-full flex items-center justify-center gap-2 border border-dashed border-gray-300 rounded-lg py-3 text-sm font-medium text-gray-600 hover:border-gray-900 hover:text-gray-900 transition-colors cursor-pointer"
        >
          <Plus className="w-4 h-4" /> Add a Google Review / Testimonial
        </button>
      </CollapseSection>
      <CollapseSection title="Studio contact details" defaultOpen>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <TextInput label="Phone number" value={content.contact.phone} onChange={v => update(['contact', 'phone'], v)} />
          <TextInput label="Email address" value={content.contact.email} onChange={v => update(['contact', 'email'], v)} />
        </div>
        <TextInput label="Studio location" value={content.contact.location} onChange={v => update(['contact', 'location'], v)} />
      </CollapseSection>

      {/* SOCIALS & MESSAGING */}
      <CollapseSection title="Social links & WhatsApp number" defaultOpen>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <TextInput label="Instagram profile URL" value={content.contact.instagram} onChange={v => update(['contact', 'instagram'], v)} />
          <TextInput label="Facebook page URL" value={content.contact.facebook} onChange={v => update(['contact', 'facebook'], v)} />
          <TextInput label="TikTok profile URL" value={content.contact.tiktok} onChange={v => update(['contact', 'tiktok'], v)} />
          <TextInput label="WhatsApp number (with country code, e.g. 94777548671)" value={content.contact.whatsapp} onChange={v => update(['contact', 'whatsapp'], v)} />

        </div>
      </CollapseSection>
    </div>
  );
};
