import React from 'react';
import { BarChart3, TrendingUp, Eye, Users, FolderKanban, Image as ImageIcon, Video, Camera, ExternalLink } from 'lucide-react';
import { TextInput, ToggleSwitch, PageHeader } from './AdminFields';
import type { SiteContent } from '../../context/ContentContext';

interface AnalyticsSectionProps {
  content: SiteContent;
  update: (path: string[], value: any) => void;
}

export const AnalyticsSection: React.FC<AnalyticsSectionProps> = ({ content, update }) => {
  const projects = content.projects || [];
  const totalGalleryItems = projects.reduce((acc, p) => acc + (p.gallery?.length || 0), 0);
  const videoProjects = projects.filter(p => p.type === 'video').length;
  const photoProjects = projects.filter(p => p.type !== 'video').length;

  const libraryStats = [
    { label: 'Published projects', value: String(projects.length), icon: FolderKanban },
    { label: 'Photography projects', value: String(photoProjects), icon: Camera },
    { label: 'Video projects', value: String(videoProjects), icon: Video },
    { label: 'Gallery images', value: String(totalGalleryItems), icon: ImageIcon },
  ];

  return (
    <div className="space-y-6">
      <PageHeader
        icon={BarChart3}
        title="Analytics"
        description="Connect a traffic analytics service and review your content library performance."
      />

      {/* Library Statistics — real data */}
      <div>
        <h4 className="text-sm font-semibold text-gray-900 mb-3">Content library</h4>
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          {libraryStats.map((stat, i) => {
            const Icon = stat.icon;
            return (
              <div key={i} className="p-5 bg-white border border-gray-200 rounded-lg">
                <div className="flex items-center justify-between">
                  <span className="text-xs font-medium text-gray-500">{stat.label}</span>
                  <Icon className="w-4 h-4 text-gray-400" />
                </div>
                <div className="text-3xl font-semibold text-gray-900 mt-2">{stat.value}</div>
              </div>
            );
          })}
        </div>
      </div>

      {/* Google Analytics connection */}
      <div className="border border-gray-200 rounded-lg bg-white p-6 space-y-4">
        <div className="flex items-center gap-2">
          <TrendingUp className="w-4 h-4 text-gray-400" />
          <h4 className="text-sm font-semibold text-gray-900">Google Analytics</h4>
        </div>
        <p className="text-sm text-gray-500">
          Visitor metrics such as page views, traffic sources and geography are provided by Google
          Analytics. Add your Measurement ID below and include the gtag snippet in{' '}
          <code className="text-xs bg-gray-100 px-1.5 py-0.5 rounded">index.html</code> to start
          collecting data. Once connected, your reports appear in the{' '}
          <a href="https://analytics.google.com" target="_blank" rel="noreferrer" className="text-gray-900 underline underline-offset-2 inline-flex items-center gap-0.5">
            Google Analytics dashboard <ExternalLink className="w-3 h-3" />
          </a>.
        </p>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-2xl">
          <TextInput
            label="Google Analytics Measurement ID"
            value={content.seo?.analyticsId || ''}
            onChange={v => update(['seo', 'analyticsId'], v)}
            placeholder="G-XXXXXXXXXX"
          />
          <TextInput
            label="Search Console verification ID"
            value={content.seo?.searchConsoleId || ''}
            onChange={v => update(['seo', 'searchConsoleId'], v)}
            placeholder="e.g. google-site-verification value"
          />
        </div>

        {(content.seo?.analyticsId || '') !== '' ? (
          <p className="text-xs text-green-700 bg-green-50 border border-green-200 rounded-md px-3 py-2 flex items-center gap-1.5 w-fit">
            <Eye className="w-3.5 h-3.5" /> Measurement ID saved — visitor tracking is configured.
          </p>
        ) : (
          <p className="text-xs text-gray-500 bg-gray-50 border border-gray-200 rounded-md px-3 py-2 flex items-center gap-1.5 w-fit">
            <Users className="w-3.5 h-3.5" /> No Measurement ID yet — visitor analytics is not connected.
          </p>
        )}
      </div>

      {/* Indexing preference */}
      <ToggleSwitch
        label="Allow search engine indexing"
        checked={content.seo?.allowIndexing ?? true}
        onChange={val => update(['seo', 'allowIndexing'], val)}
        description="When enabled, search engines can crawl and rank your pages"
      />
    </div>
  );
};
