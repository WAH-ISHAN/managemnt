import React from 'react';
import { ArrowDown, ArrowLeft, ArrowRight, ArrowUp, Check, MapPin, Plus, Trash2 } from 'lucide-react';
import { CollapseSection, ImageUpload, TextInput } from './AdminFields';
import type { SiteContent } from '../../context/ContentContext';

interface Props {
  content: SiteContent;
  update: (path: string[], value: unknown) => void;
}

const positions = [
  { label: 'Before Hero', context: 'At the very top of the homepage' },
  { label: 'After Hero', context: 'Between Hero and Introduction' },
  { label: 'After Introduction', context: 'Between Introduction and Featured Work' },
  { label: 'After Featured Work', context: 'Between Featured Work and Selected Work' },
  { label: 'After Selected Work', context: 'Between Selected Work and Services' },
  { label: 'After Services', context: 'Between Services and Final CTA' },
  { label: 'Between Final CTA and Contact', context: 'Directly above the contact section' },
  { label: 'After Contact', context: 'Between Contact and the footer' },
] as const;

export const PartnerLogosAdmin: React.FC<Props> = ({ content, update }) => {
  const section = content.home?.partnerLogos || { enabled: false, label: 'Trusted by', position: 6, logos: [] };
  const logos = Array.isArray(section.logos) ? section.logos : [];
  const storedPosition = Number(section.position);
  const position = Number.isFinite(storedPosition)
    ? Math.min(positions.length - 1, Math.max(0, Math.round(storedPosition)))
    : 6;
  const updateSection = (key: string, value: unknown) => update(['home', 'partnerLogos', key], value);
  const updateLogo = (index: number, key: string, value: string) =>
    updateSection('logos', logos.map((logo, i) => i === index ? { ...logo, [key]: value } : logo));
  const moveLogo = (index: number, direction: -1 | 1) => {
    const target = index + direction;
    if (target < 0 || target >= logos.length) return;
    const reordered = [...logos];
    [reordered[index], reordered[target]] = [reordered[target], reordered[index]];
    updateSection('logos', reordered);
  };

  return (
    <CollapseSection title="Moving company logos" badge={section.enabled ? 'Visible' : 'Hidden'}>
      <p className="text-xs text-gray-500">A continuous logo row. Changes go live when you click Publish to Website.</p>
      <label className="flex items-center gap-3 rounded-md border border-gray-200 bg-white px-3 py-3 text-sm font-medium text-gray-800">
        <input type="checkbox" checked={section.enabled} onChange={event => updateSection('enabled', event.target.checked)} className="h-4 w-4 accent-gray-900" />
        Show moving logos on the homepage
      </label>
      <TextInput label="Section label" value={section.label} onChange={value => updateSection('label', value)} />
      <div className="rounded-lg border border-gray-200 bg-gray-50 p-4">
        <div className="flex items-start gap-3">
          <div className="mt-0.5 rounded-md border border-gray-200 bg-white p-2 text-gray-600">
            <MapPin className="h-4 w-4" aria-hidden="true" />
          </div>
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div>
                <label htmlFor="partner-logo-position" className="text-sm font-semibold text-gray-900">Section position</label>
                <p className="mt-0.5 text-xs text-gray-500">Choose exactly where the moving logo row appears.</p>
              </div>
              <span className="rounded-full border border-gray-200 bg-white px-2.5 py-1 text-[11px] font-medium text-gray-500">
                Position {position + 1} of {positions.length}
              </span>
            </div>

            <select
              id="partner-logo-position"
              value={position}
              onChange={event => updateSection('position', Number(event.target.value))}
              className="mt-3 w-full rounded-md border border-gray-300 bg-white px-3 py-2.5 text-sm font-medium text-gray-900 outline-none transition focus:border-gray-900 focus:ring-2 focus:ring-gray-900/10"
            >
              {positions.map((item, index) => (
                <option key={item.label} value={index}>{item.label}</option>
              ))}
            </select>

            <div className="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2.5" role="status" aria-live="polite">
              <div className="flex items-start gap-2">
                <Check className="mt-0.5 h-3.5 w-3.5 flex-shrink-0 text-emerald-700" aria-hidden="true" />
                <div>
                  <p className="text-xs font-semibold text-emerald-900">{positions[position].label}</p>
                  <p className="mt-0.5 text-[11px] text-emerald-800">{positions[position].context}</p>
                </div>
              </div>
            </div>

            <div className="mt-3 grid grid-cols-2 gap-2">
              <button
                type="button"
                disabled={position === 0}
                onClick={() => updateSection('position', position - 1)}
                className="inline-flex min-h-10 items-center justify-center gap-2 rounded-md border border-gray-300 bg-white px-3 text-xs font-semibold text-gray-700 transition hover:border-gray-500 hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 disabled:cursor-not-allowed disabled:opacity-40"
              >
                <ArrowUp className="h-4 w-4" aria-hidden="true" /> Move earlier
              </button>
              <button
                type="button"
                disabled={position === positions.length - 1}
                onClick={() => updateSection('position', position + 1)}
                className="inline-flex min-h-10 items-center justify-center gap-2 rounded-md border border-gray-300 bg-white px-3 text-xs font-semibold text-gray-700 transition hover:border-gray-500 hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 disabled:cursor-not-allowed disabled:opacity-40"
              >
                <ArrowDown className="h-4 w-4" aria-hidden="true" /> Move later
              </button>
            </div>
          </div>
        </div>
      </div>
      <div className="flex items-center justify-between gap-3">
        <div>
          <h4 className="text-sm font-semibold text-gray-900">Logos</h4>
          <p className="text-xs text-gray-500">Upload logos, name each company, and set an optional website link.</p>
        </div>
        <button type="button" onClick={() => updateSection('logos', [...logos, { id: `logo-${Date.now()}`, name: '', image: '', url: '' }])} className="inline-flex items-center gap-2 rounded-md bg-gray-900 px-3 py-2 text-xs font-semibold text-white hover:bg-gray-700"><Plus className="h-4 w-4" /> Add logo</button>
      </div>
      {logos.map((logo, index) => (
        <div key={logo.id} className="space-y-3 rounded-md border border-gray-200 bg-white p-4">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-gray-600">Logo {index + 1}</span>
            <div className="flex gap-1">
              <button type="button" title="Move logo left" aria-label={`Move ${logo.name || 'logo'} left`} disabled={index === 0} onClick={() => moveLogo(index, -1)} className="rounded border border-gray-200 p-1.5 hover:bg-gray-100 disabled:opacity-30"><ArrowLeft className="h-3.5 w-3.5" /></button>
              <button type="button" title="Move logo right" aria-label={`Move ${logo.name || 'logo'} right`} disabled={index === logos.length - 1} onClick={() => moveLogo(index, 1)} className="rounded border border-gray-200 p-1.5 hover:bg-gray-100 disabled:opacity-30"><ArrowRight className="h-3.5 w-3.5" /></button>
              <button type="button" title="Remove logo" aria-label={`Remove ${logo.name || 'logo'}`} onClick={() => updateSection('logos', logos.filter((_, i) => i !== index))} className="rounded border border-red-200 p-1.5 text-red-600 hover:bg-red-50"><Trash2 className="h-3.5 w-3.5" /></button>
            </div>
          </div>
          <TextInput label="Company name" value={logo.name} onChange={value => updateLogo(index, 'name', value)} />
          <ImageUpload label="Logo image" currentSrc={logo.image} onUpload={value => updateLogo(index, 'image', value)} />
          {logo.image && <button type="button" onClick={() => updateLogo(index, 'image', '')} className="text-xs text-red-600 hover:underline">Remove image</button>}
          <TextInput label="Website URL (optional)" value={logo.url} onChange={value => updateLogo(index, 'url', value)} />
        </div>
      ))}
      {logos.length === 0 && (
        <div className="rounded-md border border-dashed border-gray-300 bg-gray-50 px-4 py-8 text-center">
          <p className="text-sm font-medium text-gray-700">No company logos yet</p>
          <p className="mt-1 text-xs text-gray-500">Use “Add logo” to create the first item in the moving row.</p>
        </div>
      )}
    </CollapseSection>
  );
};
