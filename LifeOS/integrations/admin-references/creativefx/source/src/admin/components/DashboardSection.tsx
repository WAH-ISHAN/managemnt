import React, { useEffect, useState } from 'react';
import {
  FolderKanban,
  Briefcase,
  Image as ImageIcon,
  Mail,
  ArrowUpRight,
  CheckCircle2,
  XCircle,
  HardDrive,
  Plus,
  Upload,
  Search,
  MessageSquare,
  RefreshCw
} from 'lucide-react';
import type { SiteContent } from '../../context/ContentContext';
import { API_BASE } from '../../context/ContentContext';

interface DashboardSectionProps {
  content: SiteContent;
  onNavigate: (category: string, item: string) => void;
}

interface InquiryRecord {
  id: string;
  name: string;
  email: string;
  phone?: string;
  service?: string;
  message: string;
  status: 'new' | 'replied';
  createdAt: string;
}

interface ServerStats {
  uploadCount: number;
  uploadsBytes: number;
  inquiryCount: number;
  newInquiries: number;
}

function timeAgo(iso: string): string {
  const diff = Date.now() - new Date(iso).getTime();
  const mins = Math.floor(diff / 60000);
  if (mins < 1) return 'just now';
  if (mins < 60) return `${mins} min ago`;
  const hours = Math.floor(mins / 60);
  if (hours < 24) return `${hours} hr ago`;
  const days = Math.floor(hours / 24);
  return `${days} day${days > 1 ? 's' : ''} ago`;
}

export const DashboardSection: React.FC<DashboardSectionProps> = ({ content, onNavigate }) => {
  const projects = content.projects || [];
  const services = content.services || [];

  const [inquiries, setInquiries] = useState<InquiryRecord[]>([]);
  const [stats, setStats] = useState<ServerStats | null>(null);
  const [apiOnline, setApiOnline] = useState<boolean | null>(null);

  const loadServerData = () => {
    // Real inquiry list (top 5 shown below)
    fetch(`${API_BASE}/api/inquiries`, { headers: { 'x-admin-token': sessionStorage.getItem('cfx_admin_token') || '' } })
      .then(r => { setApiOnline(r.ok); return r.ok ? r.json() : []; })
      .then((list: InquiryRecord[]) => setInquiries(Array.isArray(list) ? list : []))
      .catch(() => setApiOnline(false));
    // Real storage + inquiry counters
    fetch(`${API_BASE}/api/stats`, { headers: { 'x-admin-token': sessionStorage.getItem('cfx_admin_token') || '' } })
      .then(r => (r.ok ? r.json() : null))
      .then((s: ServerStats | null) => setStats(s))
      .catch(() => {});
  };

  useEffect(() => {
    loadServerData();
  }, []);

  // Real media counts derived from project content
  const totalGalleryItems = projects.reduce((acc, p) => acc + (p.gallery?.length || 0), 0);
  const totalCoverImages = projects.filter(p => !!p.coverImage).length;
  const uploadedFiles = stats?.uploadCount ?? 0;
  const totalMedia = totalGalleryItems + totalCoverImages + uploadedFiles;

  const statsCards = [
    { label: 'Projects', value: String(projects.length), detail: `${projects.filter(p => p.type === 'video').length} video · ${projects.filter(p => p.type !== 'video').length} photo`, icon: FolderKanban },
    { label: 'Services', value: String(services.length), detail: 'Published on the site', icon: Briefcase },
    { label: 'Media assets', value: String(totalMedia), detail: `${uploadedFiles} uploaded files`, icon: ImageIcon },
    { label: 'Inquiries', value: String(stats?.inquiryCount ?? 0), detail: `${stats?.newInquiries ?? 0} awaiting reply`, icon: Mail },
  ];

  const formatBytes = (bytes: number) => {
    if (!bytes) return '0 MB';
    const mb = bytes / (1024 * 1024);
    return mb >= 1024 ? `${(mb / 1024).toFixed(1)} GB` : `${mb.toFixed(1)} MB`;
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h1 className="text-xl font-semibold text-gray-900">Overview</h1>
          <p className="text-sm text-gray-500 mt-0.5">Everything on your website, at a glance.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <button
            onClick={() => onNavigate('Works / Projects', 'Add Project')}
            className="flex items-center gap-2 bg-gray-900 text-white text-xs font-semibold px-4 py-2 rounded-md hover:bg-gray-700 transition-colors"
          >
            <Plus className="w-3.5 h-3.5" /> Add project
          </button>
          <button
            onClick={() => onNavigate('Media Library', 'Upload / Replace')}
            className="flex items-center gap-2 bg-white border border-gray-300 text-gray-700 text-xs font-medium px-4 py-2 rounded-md hover:border-gray-900 hover:text-gray-900 transition-colors"
          >
            <Upload className="w-3.5 h-3.5" /> Upload media
          </button>
          <button
            onClick={() => onNavigate('SEO', 'SEO')}
            className="flex items-center gap-2 bg-white border border-gray-300 text-gray-700 text-xs font-medium px-4 py-2 rounded-md hover:border-gray-900 hover:text-gray-900 transition-colors"
          >
            <Search className="w-3.5 h-3.5" /> Edit SEO
          </button>
        </div>
      </div>

      {/* Metric Cards */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {statsCards.map((stat, i) => {
          const Icon = stat.icon;
          return (
            <div key={i} className="p-5 bg-white border border-gray-200 rounded-lg">
              <div className="flex items-center justify-between">
                <span className="text-xs font-medium text-gray-500">{stat.label}</span>
                <Icon className="w-4 h-4 text-gray-400" />
              </div>
              <div className="text-3xl font-semibold text-gray-900 mt-2">{stat.value}</div>
              <div className="text-[11px] text-gray-400 mt-1">{stat.detail}</div>
            </div>
          );
        })}
      </div>

      {/* Two Column Section */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {/* Recent Inquiries */}
        <div className="lg:col-span-2 border border-gray-200 rounded-lg bg-white">
          <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100">
            <div className="flex items-center gap-2">
              <MessageSquare className="w-4 h-4 text-gray-400" />
              <h3 className="text-sm font-semibold text-gray-900">Recent inquiries</h3>
            </div>
            <button
              onClick={() => onNavigate('Contact / Inquiries', 'Contact / Inquiries')}
              className="text-xs text-gray-500 hover:text-gray-900 flex items-center gap-1 transition-colors"
            >
              View all <ArrowUpRight className="w-3 h-3" />
            </button>
          </div>

          <div className="divide-y divide-gray-100">
            {inquiries.length === 0 ? (
              <div className="px-5 py-10 text-center">
                <Mail className="w-8 h-8 text-gray-200 mx-auto" />
                <p className="text-sm font-medium text-gray-600 mt-3">No inquiries yet</p>
                <p className="text-xs text-gray-400 mt-1 max-w-xs mx-auto">
                  Messages sent through the website contact form will appear here.
                </p>
              </div>
            ) : (
              inquiries.slice(0, 5).map(inq => (
                <div key={inq.id} className="px-5 py-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-2 hover:bg-gray-50 transition-colors">
                  <div className="min-w-0">
                    <div className="flex items-center gap-2">
                      <span className="text-sm font-medium text-gray-900 truncate">{inq.name}</span>
                      {inq.status === 'new' && (
                        <span className="text-[10px] font-medium px-1.5 py-0.5 rounded bg-green-50 text-green-700 border border-green-200">New</span>
                      )}
                    </div>
                    <p className="text-xs text-gray-500 truncate mt-0.5">{inq.service || 'General inquiry'} · {inq.email}</p>
                  </div>
                  <div className="flex items-center gap-3 flex-shrink-0">
                    <span className="text-[11px] text-gray-400">{timeAgo(inq.createdAt)}</span>
                    {inq.phone && (
                      <a
                        href={`https://wa.me/${inq.phone.replace(/[^0-9]/g, '')}?text=Hi%20${encodeURIComponent(inq.name)}%2C%20CreativeFX%20team%20here.`}
                        target="_blank"
                        rel="noreferrer"
                        className="text-[11px] text-green-700 bg-green-50 border border-green-200 px-2 py-1 rounded hover:bg-green-100 transition-colors"
                      >
                        Reply on WhatsApp
                      </a>
                    )}
                  </div>
                </div>
              ))
            )}
          </div>
        </div>

        {/* System Health & Fast Links */}
        <div className="space-y-6">
          <div className="border border-gray-200 rounded-lg bg-white p-5 space-y-3">
            <div className="flex items-center justify-between">
              <h3 className="text-sm font-semibold text-gray-900">System status</h3>
              <button
                onClick={loadServerData}
                title="Refresh status"
                className="p-1 text-gray-400 hover:text-gray-900 transition-colors"
              >
                <RefreshCw className="w-3.5 h-3.5" />
              </button>
            </div>

            <div className="space-y-2 text-sm">
              <div className="flex items-center justify-between px-3 py-2.5 bg-gray-50 rounded-md border border-gray-100">
                <span className="text-gray-600">API server</span>
                {apiOnline === null ? (
                  <span className="text-gray-400 text-xs">Checking…</span>
                ) : apiOnline ? (
                  <span className="text-green-700 text-xs font-medium flex items-center gap-1.5"><CheckCircle2 className="w-3.5 h-3.5" /> Connected</span>
                ) : (
                  <span className="text-red-600 text-xs font-medium flex items-center gap-1.5"><XCircle className="w-3.5 h-3.5" /> Offline</span>
                )}
              </div>
              <div className="flex items-center justify-between px-3 py-2.5 bg-gray-50 rounded-md border border-gray-100">
                <span className="text-gray-600">Uploaded files</span>
                <span className="text-gray-900 text-xs font-medium">{uploadedFiles}</span>
              </div>
              <div className="flex items-center justify-between px-3 py-2.5 bg-gray-50 rounded-md border border-gray-100">
                <span className="text-gray-600 flex items-center gap-1.5"><HardDrive className="w-3.5 h-3.5 text-gray-400" /> Storage used</span>
                <span className="text-gray-900 text-xs font-medium">{formatBytes(stats?.uploadsBytes ?? 0)}</span>
              </div>
            </div>
          </div>

          <div className="border border-gray-200 rounded-lg bg-white p-5 space-y-2">
            <h3 className="text-sm font-semibold text-gray-900 mb-2">Quick links</h3>
            <div className="grid grid-cols-2 gap-2 text-xs">
              <button onClick={() => onNavigate('Homepage', 'Homepage')} className="px-3 py-2.5 bg-gray-50 hover:bg-gray-100 text-gray-700 hover:text-gray-900 rounded-md text-left border border-gray-100 transition-colors">
                Homepage content
              </button>
              <button onClick={() => onNavigate('Weddings', 'Stories')} className="px-3 py-2.5 bg-gray-50 hover:bg-gray-100 text-gray-700 hover:text-gray-900 rounded-md text-left border border-gray-100 transition-colors">
                Wedding stories
              </button>
              <button onClick={() => onNavigate('Works / Projects', 'All Projects')} className="px-3 py-2.5 bg-gray-50 hover:bg-gray-100 text-gray-700 hover:text-gray-900 rounded-md text-left border border-gray-100 transition-colors">
                All {projects.length} projects
              </button>
              <button onClick={() => onNavigate('Theme / Branding', 'Theme / Branding')} className="px-3 py-2.5 bg-gray-50 hover:bg-gray-100 text-gray-700 hover:text-gray-900 rounded-md text-left border border-gray-100 transition-colors">
                Theme & branding
              </button>
            </div>
          </div>
        </div>

      </div>
    </div>
  );
};
