import React from 'react';
import { Plus, Trash2, Users } from 'lucide-react';
import { CollapseSection, ImageUpload, PageHeader, TextArea, TextInput } from './AdminFields';
import type { SiteContent } from '../../context/ContentContext';

interface Props {
  content: SiteContent;
  update: (path: string[], value: unknown) => void;
}

export const CreativePoolAdmin: React.FC<Props> = ({ content, update }) => {
  const pool = content.creativePool;
  const creatives = pool.creatives || [];

  const patchCreative = (index: number, field: string, value: unknown) => {
    const next = creatives.map((creative, i) => i === index ? { ...creative, [field]: value } : creative);
    update(['creativePool', 'creatives'], next);
  };

  const addCreative = () => {
    const id = 'creative-' + Date.now();
    update(['creativePool', 'creatives'], [
      ...creatives,
      { id, name: 'New Creative', handle: '@username', instagramUrl: '', images: ['', '', '', '', ''] },
    ]);
  };

  const removeCreative = (index: number) => {
    if (!window.confirm('Remove this creative from the public Creative Pool?')) return;
    update(['creativePool', 'creatives'], creatives.filter((_, i) => i !== index));
  };

  return (
    <div className="space-y-6">
      <PageHeader title="Creative Pool" description="Edit the header, profiles, Instagram details, and images. Click Publish to Website to save changes permanently to the server." icon={Users} />

      <CollapseSection title="Page copy & header image" defaultOpen>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div className="sm:col-span-2">
            <ImageUpload
              label="Header / hero image"
              currentSrc={pool.heroImage}
              onUpload={value => update(['creativePool', 'heroImage'], value)}
            />
          </div>
          <TextInput label="Eyebrow" value={pool.eyebrow} onChange={value => update(['creativePool', 'eyebrow'], value)} />
          <TextInput label="Page title" value={pool.title} onChange={value => update(['creativePool', 'title'], value)} />
          <TextInput label="Location" value={pool.location} onChange={value => update(['creativePool', 'location'], value)} />
          <TextInput label="CTA eyebrow" value={pool.ctaEyebrow} onChange={value => update(['creativePool', 'ctaEyebrow'], value)} />
          <div className="sm:col-span-2">
            <TextArea label="Introduction" value={pool.description} onChange={value => update(['creativePool', 'description'], value)} rows={3} />
          </div>
          <div className="sm:col-span-2">
            <TextInput label="CTA title" value={pool.ctaTitle} onChange={value => update(['creativePool', 'ctaTitle'], value)} />
          </div>
        </div>
      </CollapseSection>

      <div className="flex items-center justify-between gap-4">
        <div>
          <h4 className="text-base font-semibold text-gray-900">Creative profiles</h4>
          <p className="text-xs text-gray-500 mt-1">{creatives.length} profiles currently shown on the website.</p>
        </div>
        <button type="button" onClick={addCreative} className="inline-flex items-center gap-2 rounded-md bg-gray-900 px-4 py-2 text-xs font-semibold text-white hover:bg-gray-700">
          <Plus className="w-4 h-4" /> Add creative
        </button>
      </div>

      {creatives.map((creative, index) => (
        <CollapseSection key={creative.id} title={(index + 1).toString().padStart(2, '0') + ' · ' + creative.name} defaultOpen={index === 0}>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <TextInput label="Name" value={creative.name} onChange={value => patchCreative(index, 'name', value)} />
            <TextInput label="Instagram handle" value={creative.handle} onChange={value => patchCreative(index, 'handle', value)} />
            <div className="sm:col-span-2">
              <TextInput label="Instagram profile URL" value={creative.instagramUrl} onChange={value => patchCreative(index, 'instagramUrl', value)} />
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2">
            {Array.from({ length: 5 }).map((_, imageIndex) => (
              <ImageUpload
                key={imageIndex}
                label={'Profile image ' + (imageIndex + 1)}
                currentSrc={creative.images[imageIndex] || ''}
                onUpload={value => {
                  const images = Array.from({ length: 5 }, (_, i) => creative.images[i] || '');
                  images[imageIndex] = value;
                  patchCreative(index, 'images', images);
                }}
              />
            ))}
          </div>

          <div className="flex justify-end border-t border-gray-100 pt-4">
            <button type="button" onClick={() => removeCreative(index)} className="inline-flex items-center gap-2 rounded-md border border-red-200 px-3 py-2 text-xs font-medium text-red-600 hover:bg-red-50">
              <Trash2 className="w-4 h-4" /> Remove creative
            </button>
          </div>
        </CollapseSection>
      ))}
    </div>
  );
};
