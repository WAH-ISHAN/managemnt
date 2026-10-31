import React from 'react';
import { Plus, Trash2, ArrowUp, ArrowDown, Eye, EyeOff } from 'lucide-react';
import {
  TextInput, TextArea, CollapseSection, PageHeader, ImageUpload,
} from './AdminFields';
import type { SiteContent } from '../../context/ContentContext';

interface ServicesAdminProps {
  content: SiteContent;
  update: (path: string[], value: any) => void;
}

/**
 * Admin → Services.
 * One master services list drives BOTH the homepage service rows and the
 * full-screen service detail modal (capabilities / deliverables / preview).
 */
export const ServicesAdmin: React.FC<ServicesAdminProps> = ({ content, update }) => {
  const services = content.services || [];

  const patch = (idx: number, key: string, value: unknown) => {
    update(['services'], services.map((s, i) => (i === idx ? { ...s, [key]: value } : s)));
  };
  const move = (idx: number, dir: -1 | 1) => {
    const t = idx + dir;
    if (t < 0 || t >= services.length) return;
    const next = [...services];
    [next[idx], next[t]] = [next[t], next[idx]];
    update(['services'], next);
  };
  const toggleStatus = (idx: number) => {
    const cur = (services[idx] as any).status ?? 'published';
    patch(idx, 'status', cur === 'published' ? 'draft' : 'published');
  };

  return (
    <div className="space-y-6">
      <PageHeader
        title="Agency services"
        description={`${services.length} disciplines — shown on the homepage list and in the detail modal. Draft services are hidden from the site.`}
      />

      {services.map((svc: any, idx: number) => (
        <CollapseSection
          key={svc.id || idx}
          title={`${svc.number || svc.id} — ${svc.title}`}
          badge={(svc.status ?? 'published') === 'published' ? 'Published' : 'Draft'}
          defaultOpen={idx === 0}
        >
          <div className="flex items-center justify-between pb-3 border-b border-gray-100">
            <span className="text-xs text-gray-500">Display order: <b>#{idx + 1}</b></span>
            <div className="flex items-center gap-1.5">
              <button type="button" onClick={() => toggleStatus(idx)}
                title={(svc.status ?? 'published') === 'published' ? 'Hide from website' : 'Publish'}
                className="p-2 text-gray-500 hover:bg-gray-100 rounded-md border border-gray-200 cursor-pointer">
                {(svc.status ?? 'published') === 'published' ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
              </button>
              <button type="button" onClick={() => move(idx, -1)} disabled={idx === 0}
                className="px-2.5 py-1.5 text-xs bg-white border border-gray-300 rounded hover:bg-gray-100 disabled:opacity-30 cursor-pointer"><ArrowUp className="w-3 h-3" /></button>
              <button type="button" onClick={() => move(idx, 1)} disabled={idx === services.length - 1}
                className="px-2.5 py-1.5 text-xs bg-white border border-gray-300 rounded hover:bg-gray-100 disabled:opacity-30 cursor-pointer"><ArrowDown className="w-3 h-3" /></button>
              <button type="button"
                onClick={() => { if (confirm(`Delete "${svc.title}"?`)) update(['services'], services.filter((_: any, i: number) => i !== idx)); }}
                className="p-2 text-red-500 hover:bg-red-50 rounded-md border border-red-200 cursor-pointer"><Trash2 className="w-3.5 h-3.5" /></button>
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <TextInput label="Number" value={svc.number || ''} onChange={v => patch(idx, 'number', v)} />
            <TextInput label="Code" value={svc.code || ''} onChange={v => patch(idx, 'code', v)} />
            <TextInput label="Title" value={svc.title || ''} onChange={v => patch(idx, 'title', v)} />
          </div>

          <TextArea label="Short description (homepage row)" value={svc.shortDesc || ''} onChange={v => patch(idx, 'shortDesc', v)} rows={2} />
          <TextArea label="Full description (detail modal)" value={svc.fullDesc || ''} onChange={v => patch(idx, 'fullDesc', v)} rows={3} />
          <TextInput label="Highlight line" value={svc.highlight || ''} onChange={v => patch(idx, 'highlight', v)} />
          <ImageUpload label="Preview image (hover + modal hero)" currentSrc={svc.previewImage || ''} onUpload={url => patch(idx, 'previewImage', url)} />

          <TextArea
            label="Capabilities (one per line)"
            value={(svc.capabilities || []).join('\n')}
            onChange={v => patch(idx, 'capabilities', v.split('\n').map((s: string) => s.trim()).filter(Boolean))}
            rows={4}
          />
          <TextArea
            label="Deliverables (one per line)"
            value={(svc.deliverables || []).join('\n')}
            onChange={v => patch(idx, 'deliverables', v.split('\n').map((s: string) => s.trim()).filter(Boolean))}
            rows={4}
          />
        </CollapseSection>
      ))}

      <button
        type="button"
        onClick={() =>
          update(['services'], [
            ...services,
            {
              id: `service-${Date.now()}`,
              code: `SRV-${String(services.length + 1).padStart(2, '0')}`,
              number: String(services.length + 1).padStart(2, '0'),
              title: 'NEW SERVICE',
              shortDesc: '',
              fullDesc: '',
              capabilities: [],
              deliverables: [],
              highlight: '',
              previewImage: '',
              status: 'draft',
            },
          ])
        }
        className="w-full flex items-center justify-center gap-2 border border-dashed border-gray-300 rounded-lg py-3.5 text-sm font-medium text-gray-600 hover:border-gray-900 hover:text-gray-900 transition-colors cursor-pointer"
      >
        <Plus className="w-4 h-4" /> Add a new service
      </button>
    </div>
  );
};
