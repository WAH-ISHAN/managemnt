import React from 'react';
import { ArrowUp, ArrowDown, X, GripVertical, Heart } from 'lucide-react';
import { TextInput, TextArea, CollapseSection, PageHeader, ImageUpload } from './AdminFields';
import type { SiteContent } from '../../context/ContentContext';
import { useAllProjects, filterPublished } from '../../context/ContentContext';
import { PartnerLogosAdmin } from './PartnerLogosAdmin';

interface HomepageSectionProps {
  content: SiteContent;
  update: (path: string[], value: any) => void;
}

/**
 * Admin → Homepage.
 * Every control here drives a section of the public homepage:
 *   Hero / Introduction → Hero & Intro sections
 *   Featured Work       → pinned "Featured Work" scroll
 *   Selected Work       → "Selected Work" grid
 *   Final CTA           → collaboration banner above the footer
 */
export const HomepageSection: React.FC<HomepageSectionProps> = ({ content, update }) => {
  const allProjects = useAllProjects();
  const published = filterPublished(allProjects);

  // ── Featured Work ordering helpers ──
  const featuredIds: string[] = content.home?.featuredProjectIds || [];
  const featuredProjects = featuredIds
    .map(id => allProjects.find(p => p.id === id))
    .filter(Boolean);

  const moveFeatured = (idx: number, dir: -1 | 1) => {
    const next = [...featuredIds];
    const target = idx + dir;
    if (target < 0 || target >= next.length) return;
    [next[idx], next[target]] = [next[target], next[idx]];
    update(['home', 'featuredProjectIds'], next);
  };

  const toggleFeatured = (id: string) => {
    if (featuredIds.includes(id)) {
      update(['home', 'featuredProjectIds'], featuredIds.filter(x => x !== id));
    } else {
      if (featuredIds.length >= 6) {
        alert('The Featured Work showcase supports up to 6 projects. Remove one first.');
        return;
      }
      update(['home', 'featuredProjectIds'], [...featuredIds, id]);
    }
  };

  // ── Selected Work helpers ──
  const selectedIds: string[] = content.home?.selectedWorkIds || [];

  const moveSelected = (idx: number, dir: -1 | 1) => {
    const next = [...selectedIds];
    const target = idx + dir;
    if (target < 0 || target >= next.length) return;
    [next[idx], next[target]] = [next[target], next[idx]];
    update(['home', 'selectedWorkIds'], next);
  };

  const removeSelected = (id: string) => {
    update(['home', 'selectedWorkIds'], selectedIds.filter(x => x !== id));
  };

  const addSelected = (id: string) => {
    if (!id || selectedIds.includes(id)) return;
    update(['home', 'selectedWorkIds'], [...selectedIds, id]);
  };

  return (
    <div className="space-y-6">
      <PageHeader title="Homepage" description="Everything the visitor sees on the landing page, in page order." />

      {/* ── 1. HERO ── */}
      <CollapseSection title="Hero — headline & description" defaultOpen badge="Above the fold">
        <TextInput label="Main title" value={content.hero.title} onChange={v => update(['hero', 'title'], v)} />
        <TextInput label="Subtitle / tagline" value={content.hero.subtitle} onChange={v => update(['hero', 'subtitle'], v)} />
        <TextArea label="Description paragraph" value={content.hero.description} onChange={v => update(['hero', 'description'], v)} rows={3} />
      </CollapseSection>

      {/* ── 2. INTRODUCTION ── */}
      <CollapseSection title="Introduction — studio section (01)">
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <TextInput label="Section number" value={content.intro.sectionNumber} onChange={v => update(['intro', 'sectionNumber'], v)} />
          <TextInput label="Section label" value={content.intro.label || '/ Studio'} onChange={v => update(['intro', 'label'], v)} />
        </div>
        <TextArea label="Headline (line breaks allowed)" value={content.intro.headline} onChange={v => update(['intro', 'headline'], v)} rows={3} />
        <TextArea label="Body text" value={content.intro.body} onChange={v => update(['intro', 'body'], v)} rows={3} />
        <TextInput label="Second body line" value={content.intro.bodyLine2} onChange={v => update(['intro', 'bodyLine2'], v)} />
        <ImageUpload
          label="Intro image"
          currentSrc={content.intro.image || ''}
          onUpload={url => update(['intro', 'image'], url)}
        />
      </CollapseSection>

      {/* ── 3. FEATURED WORK ── */}
      <CollapseSection title="Featured Work — pinned scroll showcase" badge={`${featuredProjects.length}/6 selected`}>
        <p className="text-xs text-gray-500 -mt-1">
          Pick up to 6 projects from the master project database. They appear in the animated
          “Featured Work” experience in the order below. Leave empty to fall back to projects
          flagged as featured.
        </p>
        <TextArea
          label="Showcase caption"
          value={content.home?.featuredLabel || ''}
          onChange={v => update(['home', 'featuredLabel'], v)}
          rows={2}
        />

        {featuredProjects.length > 0 && (
          <div className="space-y-2 border border-gray-200 rounded-md p-2 bg-white">
            {featuredProjects.map((p, idx) => (
              <div key={p!.id} className="flex items-center justify-between gap-3 p-2 bg-gray-50 rounded-md border border-gray-100">
                <div className="flex items-center gap-2 min-w-0">
                  <GripVertical className="w-4 h-4 text-gray-300 flex-shrink-0" />
                  <span className="text-xs font-mono font-bold text-gray-400 w-5">{String(idx + 1).padStart(2, '0')}</span>
                  {p!.coverImage && <img loading="lazy" decoding="async" src={p!.coverImage} alt="" className="w-9 h-9 object-cover rounded-sm border border-gray-200" />}
                  <span className="text-sm text-gray-800 truncate">{p!.title}</span>
                </div>
                <div className="flex items-center gap-1 flex-shrink-0">
                  <button onClick={() => moveFeatured(idx, -1)} disabled={idx === 0} title="Move up"
                    className="p-1.5 border border-gray-200 rounded hover:bg-gray-100 disabled:opacity-25 cursor-pointer">▲</button>
                  <button onClick={() => moveFeatured(idx, 1)} disabled={idx === featuredProjects.length - 1} title="Move down"
                    className="p-1.5 border border-gray-200 rounded hover:bg-gray-100 disabled:opacity-25 cursor-pointer">▼</button>
                  <button onClick={() => toggleFeatured(p!.id)} title="Remove"
                    className="p-1.5 border border-red-200 text-red-500 rounded hover:bg-red-50 cursor-pointer"><X className="w-3.5 h-3.5" /></button>
                </div>
              </div>
            ))}
          </div>
        )}

        <div>
          <p className="text-xs font-semibold text-gray-700 uppercase tracking-wide mb-2">Available published projects</p>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2 max-h-64 overflow-y-auto p-1">
            {published.map(p => {
              const picked = featuredIds.includes(p.id);
              return (
                <button
                  key={p.id}
                  type="button"
                  onClick={() => toggleFeatured(p.id)}
                  className={`flex items-center gap-2 p-2 rounded-md border text-left transition-colors cursor-pointer ${
                    picked ? 'border-green-500 bg-green-50' : 'border-gray-200 bg-white hover:border-gray-400'
                  }`}
                >
                  {p.coverImage && <img loading="lazy" decoding="async" src={p.coverImage} alt="" className="w-8 h-8 object-cover rounded-sm flex-shrink-0" />}
                  <span className="text-xs text-gray-800 truncate">{p.title}</span>
                  {picked && <span className="ml-auto text-[10px] font-bold text-green-600 uppercase">Added</span>}
                </button>
              );
            })}
          </div>
        </div>
      </CollapseSection>

      {/* ── 4. SELECTED WORK GRID ── */}
      <CollapseSection title="Selected Work — grid below Featured Work" badge={`${selectedIds.length} tiles`}>
        <p className="text-xs text-gray-500 -mt-1">
          Choose exactly which published projects appear in the “Selected Work” grid and in what
          order. The special Weddings tile links to the weddings experience.
        </p>

        <div className="space-y-2 border border-gray-200 rounded-md p-2 bg-white">
          {selectedIds.map((id, idx) => {
            if (id === '__weddings__') {
              return (
                <div key={id} className="flex items-center justify-between gap-3 p-2 bg-pink-50 rounded-md border border-pink-100">
                  <div className="flex items-center gap-2 min-w-0">
                    <GripVertical className="w-4 h-4 text-gray-300" />
                    <span className="text-xs font-mono font-bold text-gray-400 w-5">{String(idx + 1).padStart(2, '0')}</span>
                    <Heart className="w-4 h-4 text-pink-500" />
                    <span className="text-sm text-gray-800">Weddings tile → /weddings</span>
                  </div>
                  <div className="flex items-center gap-1">
                    <button onClick={() => moveSelected(idx, -1)} disabled={idx === 0} className="p-1.5 border border-gray-200 rounded hover:bg-gray-100 disabled:opacity-25 cursor-pointer">▲</button>
                    <button onClick={() => moveSelected(idx, 1)} disabled={idx === selectedIds.length - 1} className="p-1.5 border border-gray-200 rounded hover:bg-gray-100 disabled:opacity-25 cursor-pointer">▼</button>
                    <button onClick={() => removeSelected(id)} className="p-1.5 border border-red-200 text-red-500 rounded hover:bg-red-50 cursor-pointer"><X className="w-3.5 h-3.5" /></button>
                  </div>
                </div>
              );
            }
            const p = allProjects.find(x => x.id === id);
            if (!p) return null;
            return (
              <div key={id} className="flex items-center justify-between gap-3 p-2 bg-gray-50 rounded-md border border-gray-100">
                <div className="flex items-center gap-2 min-w-0">
                  <GripVertical className="w-4 h-4 text-gray-300 flex-shrink-0" />
                  <span className="text-xs font-mono font-bold text-gray-400 w-5">{String(idx + 1).padStart(2, '0')}</span>
                  {p.coverImage && <img loading="lazy" decoding="async" src={p.coverImage} alt="" className="w-9 h-9 object-cover rounded-sm border border-gray-200" />}
                  <span className="text-sm text-gray-800 truncate">{p.title}</span>
                  {(p.status ?? 'published') !== 'published' && (
                    <span className="text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700 uppercase">{p.status}</span>
                  )}
                </div>
                <div className="flex items-center gap-1 flex-shrink-0">
                  <button onClick={() => moveSelected(idx, -1)} disabled={idx === 0} className="p-1.5 border border-gray-200 rounded hover:bg-gray-100 disabled:opacity-25 cursor-pointer">▲</button>
                  <button onClick={() => moveSelected(idx, 1)} disabled={idx === selectedIds.length - 1} className="p-1.5 border border-gray-200 rounded hover:bg-gray-100 disabled:opacity-25 cursor-pointer">▼</button>
                  <button onClick={() => removeSelected(id)} className="p-1.5 border border-red-200 text-red-500 rounded hover:bg-red-50 cursor-pointer"><X className="w-3.5 h-3.5" /></button>
                </div>
              </div>
            );
          })}
          {selectedIds.length === 0 && (
            <p className="text-xs text-gray-400 p-3 text-center">Grid is empty — add projects below.</p>
          )}
        </div>

        <select
          value=""
          onChange={e => addSelected(e.target.value)}
          className="w-full bg-white border border-gray-300 rounded-md px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-gray-900 cursor-pointer"
        >
          <option value="">+ Add a project or the Weddings tile…</option>
          <option value="__weddings__">Weddings tile (→ /weddings)</option>
          {published.filter(p => !selectedIds.includes(p.id)).map(p => (
            <option key={p.id} value={p.id}>{p.title} — {p.categoryLabel || p.category}</option>
          ))}
        </select>
      </CollapseSection>

      {/* ── 5. FINAL CTA ── */}
      <CollapseSection title="Final CTA — collaboration banner">
        <TextInput label="Tagline" value={content.cta?.tagline || ''} onChange={v => update(['cta', 'tagline'], v)} />
        <TextArea label="Headline (line breaks allowed)" value={content.cta?.headline || ''} onChange={v => update(['cta', 'headline'], v)} rows={3} />
        <TextInput label="Sub-headline" value={content.cta?.subHeadline || ''} onChange={v => update(['cta', 'subHeadline'], v)} />
        <TextArea label="Body text" value={content.cta?.body || ''} onChange={v => update(['cta', 'body'], v)} rows={2} />
        <TextInput label="Button label" value={content.cta?.buttonLabel || ''} onChange={v => update(['cta', 'buttonLabel'], v)} />
      </CollapseSection>
      <PartnerLogosAdmin content={content} update={update} />
    </div>
  );
};
