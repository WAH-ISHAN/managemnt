import React, { useMemo, useState } from 'react';
import {
  Plus, Trash2, Search, Check, Video, Camera, FolderKanban, ArrowUp, ArrowDown,
  Archive, ArchiveRestore, Eye, EyeOff, ChevronDown, AlertTriangle,
} from 'lucide-react';
import {
  TextInput, TextArea, ImageUpload, VideoInput, CollapseSection, FieldLabel,
  GalleryMediaManager, SelectField,
} from './AdminFields';
import { parseDriveUrl } from '../../utils/driveUtils';
import { ALL_PROJECTS } from '../../data/projectsData';
import type { SiteContent } from '../../context/ContentContext';

interface WorksSectionProps {
  item: string;
  content: SiteContent;
  update: (path: string[], value: any) => void;
}

const PAGE_SIZE = 8;
const STATUS_OPTIONS = [
  { value: 'published', label: 'Published' },
  { value: 'draft', label: 'Draft (hidden from site)' },
  { value: 'archived', label: 'Archived' },
];

export const WorksSection: React.FC<WorksSectionProps> = ({ item, content, update }) => {
  const projects: any[] = content.projects || [];
  const [searchQuery, setSearchQuery] = useState('');
  const [statusTab, setStatusTab] = useState<string>('ALL');
  const [typeTab, setTypeTab] = useState<string>('ALL');
  const [sortKey, setSortKey] = useState<string>('manual');
  const [page, setPage] = useState(0);
  const [expandedId, setExpandedId] = useState<string | null>(null);

  // ── Project helpers (id-based so filtered views stay consistent) ──
  const patchProject = (id: string, patch: Record<string, unknown>) => {
    const idx = projects.findIndex(p => p.id === id);
    if (idx === -1) return;
    const next = projects.map((p, i) => (i === idx ? { ...p, ...patch } : p));
    update(['projects'], next);
  };

  const moveProject = (id: string, dir: -1 | 1) => {
    const idx = projects.findIndex(p => p.id === id);
    const target = idx + dir;
    if (idx === -1 || target < 0 || target >= projects.length) return;
    const next = [...projects];
    [next[idx], next[target]] = [next[target], next[idx]];
    update(['projects'], next);
  };

  const deleteProject = (p: any) => {
    if (!confirm(`Delete "${p.title}" permanently? This cannot be undone.\n\nTip: Archive it instead to keep the record hidden but recoverable.`)) return;
    update(['projects'], projects.filter(x => x.id !== p.id));
  };

  // Standard categories specified for CreativeFX
  const knownCategories = useMemo(() => {
    return [
      'Graduation',
      'Events',
      'Casual Shoots',
      'Birthday',
      'Conceptual Reels',
      'Product',
      'Marketing Reels',
      'Drone',
      'Other'
    ];
  }, []);

  // ── Add Project form state ──
  const blankNew = () => ({
    title: '', slug: '', client: '', category: 'COMMERCIAL', categoryLabel: '',
    year: String(new Date().getFullYear()), type: 'photography' as 'photography' | 'video',
    coverImage: '', videoUrl: '', videoPoster: '', summary: '', challenge: '', solution: '',
    deliverables: 'Highlight Reel, Social Clips', tags: '', status: 'published',
  });
  const [newProject, setNewProject] = useState(blankNew());
  const [addSuccess, setAddSuccess] = useState(false);

  const handleCreateProject = () => {
    if (!newProject.title.trim()) return alert('Please enter a project title');
    let slug = newProject.slug.trim().toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    if (!slug) slug = newProject.title.toLowerCase().replace(/[^a-z0-9]+/g, '-');
    if (projects.some(p => p.slug === slug)) {
      return alert(`The slug "/works/${slug}" is already used by another project. Pick a unique slug.`);
    }
    const projectToAdd = {
      ...newProject,
      code: `PROJECT / ${String(projects.length + 1).padStart(3, '0')}`,
      id: `proj-${Date.now()}`,
      slug,
      categoryLabel: newProject.categoryLabel || newProject.category,
      deliverables: newProject.deliverables.split(',').map(s => s.trim()).filter(Boolean),
      tags: newProject.tags.split(',').map(s => s.trim()).filter(Boolean),
      heroPosition: newProject.type === 'video' ? 'center center' : 'center 20%',
    };
    const updatedProjects = [projectToAdd, ...projects];
    update(['projects'], updatedProjects);
    setAddSuccess(true);
    setTimeout(() => setAddSuccess(false), 4000);
    setNewProject(blankNew());
  };

  // ── ADD PROJECT view ──
  if (item === 'Add Project') {
    return (
      <div className="space-y-6 max-w-4xl">
        <div className="border-b border-gray-200 pb-4">
          <h3 className="text-sm font-mono text-gray-900 font-bold uppercase tracking-widest flex items-center gap-2">
            <Plus className="w-4 h-4 text-yellow-600" /> Add New Portfolio Project
          </h3>
          <p className="text-xs font-mono text-gray-500 mt-1">
            Creates one master record — it appears automatically on Works, in filters, and can be picked for Featured / Selected Work.
          </p>
        </div>

        {addSuccess && (
          <div className="p-4 bg-green-50 border border-green-200 text-green-700 font-mono text-xs rounded-md flex items-center gap-2">
            <Check className="w-4 h-4" /> Project created and published to the live website.
          </div>
        )}

        <div className="space-y-5 bg-white border border-gray-200 p-6 sm:p-8 rounded-lg shadow-sm">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <TextInput label="Project Title *" value={newProject.title} onChange={v => setNewProject(p => ({ ...p, title: v }))} placeholder="e.g. Aura Fragrance Commercial" />
            <TextInput label="Slug (URL path)" value={newProject.slug} onChange={v => setNewProject(p => ({ ...p, slug: v }))} placeholder="e.g. aura-fragrance" hint="Leave blank to auto-generate from title" />
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <TextInput label="Client / Brand" value={newProject.client} onChange={v => setNewProject(p => ({ ...p, client: v }))} placeholder="e.g. AURA PERFUMES" />
            <TextInput label="Production Year" value={newProject.year} onChange={v => setNewProject(p => ({ ...p, year: v }))} />
            <SelectField label="Publishing status" value={newProject.status} onChange={v => setNewProject(p => ({ ...p, status: v }))} options={STATUS_OPTIONS} />
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <FieldLabel label="Category" />
              <input
                list="cfx-category-options"
                value={newProject.category}
                onChange={e => setNewProject(p => ({ ...p, category: e.target.value.toUpperCase() }))}
                className="w-full bg-white border border-gray-300 rounded-md px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-gray-900"
              />
              <datalist id="cfx-category-options">
                {knownCategories.map(c => <option key={c} value={c} />)}
              </datalist>
              <p className="text-[11px] text-gray-400 mt-0.5">Works-page filters are generated from this field.</p>
            </div>
            <div>
              <FieldLabel label="Media Format" />
              <div className="flex gap-2">
                <button
                  type="button"
                  onClick={() => setNewProject(p => ({ ...p, type: 'photography' }))}
                  className={`flex-1 flex items-center justify-center gap-2 py-2.5 text-xs font-mono rounded-md border transition-colors cursor-pointer ${
                    newProject.type === 'photography' ? 'bg-gray-900 text-white border-gray-900 font-bold' : 'border-gray-300 text-gray-500 hover:text-gray-900'
                  }`}
                >
                  <Camera className="w-3.5 h-3.5" /> Photography
                </button>
                <button
                  type="button"
                  onClick={() => setNewProject(p => ({ ...p, type: 'video' }))}
                  className={`flex-1 flex items-center justify-center gap-2 py-2.5 text-xs font-mono rounded-md border transition-colors cursor-pointer ${
                    newProject.type === 'video' ? 'bg-gray-900 text-white border-gray-900 font-bold' : 'border-gray-300 text-gray-500 hover:text-gray-900'
                  }`}
                >
                  <Video className="w-3.5 h-3.5" /> 4K Cinema
                </button>
              </div>
            </div>
          </div>

          <TextInput label="Category subtitle label" value={newProject.categoryLabel} onChange={v => setNewProject(p => ({ ...p, categoryLabel: v }))} placeholder="e.g. Macro Product Photography" />

          <TextArea label="Project summary" value={newProject.summary} onChange={v => setNewProject(p => ({ ...p, summary: v }))} rows={2} placeholder="A short description of the shoot concept and visual direction..." />
          <TextArea label="The Challenge" value={newProject.challenge} onChange={v => setNewProject(p => ({ ...p, challenge: v }))} rows={2} />
          <TextArea label="The Solution" value={newProject.solution} onChange={v => setNewProject(p => ({ ...p, solution: v }))} rows={2} />

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <TextInput label="Deliverables (comma-separated)" value={newProject.deliverables} onChange={v => setNewProject(p => ({ ...p, deliverables: v }))} />
            <TextInput label="Tags (comma-separated)" value={newProject.tags} onChange={v => setNewProject(p => ({ ...p, tags: v }))} />
          </div>

          <ImageUpload label="Cover image" currentSrc={newProject.coverImage} onUpload={url => setNewProject(p => ({ ...p, coverImage: url }))} />

          {newProject.type === 'video' && (
            <>
              <VideoInput label="Main video URL / Google Drive link" value={newProject.videoUrl} onChange={v => setNewProject(p => ({ ...p, videoUrl: v }))} />
              <ImageUpload label="Video poster frame" currentSrc={newProject.videoPoster} onUpload={url => setNewProject(p => ({ ...p, videoPoster: url }))} />
            </>
          )}

          <div className="pt-4 border-t border-gray-100">
            <button
              type="button"
              onClick={handleCreateProject}
              className="w-full flex items-center justify-center gap-2 bg-gray-900 text-white font-bold text-xs uppercase tracking-widest py-3.5 rounded-md hover:bg-gray-700 transition-colors font-mono cursor-pointer"
            >
              <Plus className="w-4 h-4" /> Save & Publish Project
            </button>
          </div>
        </div>
      </div>
    );
  }

  // ── LIST VIEW (All Projects / Photography / Video tabs) ──
  const filtered = projects.filter(p => {
    if (item === 'Photography' && p.type !== 'photography') return false;
    if (item === 'Video' && p.type !== 'video') return false;
    if (typeTab !== 'ALL' && p.type !== typeTab) return false;
    if (statusTab !== 'ALL' && (p.status ?? 'published') !== statusTab) return false;
    if (searchQuery.trim()) {
      const q = searchQuery.toLowerCase().trim();
      return (
        p.title?.toLowerCase().includes(q) ||
        (p.client && p.client.toLowerCase().includes(q)) ||
        (p.categoryLabel && p.categoryLabel.toLowerCase().includes(q)) ||
        (p.slug && p.slug.toLowerCase().includes(q))
      );
    }
    return true;
  });

  const sorted = [...filtered].sort((a, b) => {
    if (sortKey === 'title') return String(a.title || '').localeCompare(String(b.title || ''));
    if (sortKey === 'year') return String(b.year || '').localeCompare(String(a.year || ''));
    if (sortKey === 'status') {
      const rank = (s?: string) => (s === 'published' ? 0 : s === 'draft' ? 1 : 2);
      return rank(a.status ?? 'published') - rank(b.status ?? 'published');
    }
    return 0; // manual = stored array order
  });

  const pageCount = Math.max(1, Math.ceil(sorted.length / PAGE_SIZE));
  const safePage = Math.min(page, pageCount - 1);
  const pageItems = sorted.slice(safePage * PAGE_SIZE, safePage * PAGE_SIZE + PAGE_SIZE);

  const heading =
    item === 'Photography'
      ? { title: 'Photography Projects', desc: `${filtered.length} photography case studies` }
      : item === 'Video'
        ? { title: 'Video & Cinema Projects', desc: `${filtered.length} video productions` }
        : { title: 'All Portfolio Projects', desc: `${filtered.length} of ${projects.length} total records shown` };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="border-b border-gray-200 pb-4">
        <h3 className="text-sm font-mono text-gray-900 font-bold uppercase tracking-widest flex items-center gap-2">
          <FolderKanban className="w-4 h-4 text-yellow-600" /> {heading.title}
        </h3>
        <p className="text-xs font-mono text-gray-500 mt-1">{heading.desc}</p>
      </div>

      {/* Toolbar */}
      <div className="p-4 bg-white border border-gray-200 rounded-lg space-y-3 shadow-sm">
        <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
          <div className="relative w-full sm:w-80">
            <Search className="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" />
            <input
              type="text"
              value={searchQuery}
              onChange={e => { setSearchQuery(e.target.value); setPage(0); }}
              placeholder="Search by title, client, slug…"
              className="w-full bg-white border border-gray-300 rounded-md pl-9 pr-3 py-2 text-xs font-mono text-gray-900 placeholder:text-gray-400 focus:outline-none focus:border-gray-900"
            />
          </div>

          <select
            value={sortKey}
            onChange={e => setSortKey(e.target.value)}
            className="bg-white border border-gray-300 rounded-md px-3 py-2 text-xs text-gray-700 focus:outline-none focus:border-gray-900 cursor-pointer"
          >
            <option value="manual">Sort: Manual order</option>
            <option value="title">Sort: Title A–Z</option>
            <option value="year">Sort: Year (new first)</option>
            <option value="status">Sort: Status</option>
          </select>
        </div>

        <div className="flex flex-wrap items-center gap-2 pt-2 border-t border-gray-100">
          {['ALL', 'published', 'draft', 'archived'].map(tab => (
            <button
              key={tab}
              type="button"
              onClick={() => { setStatusTab(tab); setPage(0); }}
              className={`px-3 py-1 rounded-full text-[10px] font-mono uppercase tracking-wider transition-colors border cursor-pointer ${
                statusTab === tab ? 'border-gray-900 bg-gray-900 text-white font-bold' : 'border-gray-200 bg-white text-gray-500 hover:text-gray-900'
              }`}
            >
              {tab === 'ALL' ? 'All statuses' : tab}
            </button>
          ))}
          <span className="mx-1 w-px h-4 bg-gray-200" />
          {[
            { id: 'ALL', label: 'All media' },
            { id: 'photography', label: 'Photography' },
            { id: 'video', label: 'Cinema' },
          ].map(tab => (
            <button
              key={tab.id}
              type="button"
              onClick={() => { setTypeTab(tab.id); setPage(0); }}
              className={`px-3 py-1 rounded-full text-[10px] font-mono uppercase tracking-wider transition-colors border cursor-pointer ${
                typeTab === tab.id ? 'border-yellow-500 bg-yellow-500 text-black font-bold' : 'border-gray-200 bg-white text-gray-500 hover:text-gray-900'
              }`}
            >
              {tab.label}
            </button>
          ))}
        </div>
      </div>

      {/* List */}
      <div className="space-y-3">
        {pageItems.map((proj) => {
          const status = proj.status ?? 'published';
          const isExpanded = expandedId === proj.id;
          const globalIdx = projects.findIndex(p => p.id === proj.id);
          return (
            <div key={proj.id} className="border border-gray-200 rounded-lg bg-white overflow-hidden shadow-sm">
              {/* Row header */}
              <div className="flex items-center justify-between gap-3 px-4 py-3 hover:bg-gray-50 transition-colors">
                <button
                  type="button"
                  onClick={() => setExpandedId(isExpanded ? null : proj.id)}
                  className="flex items-center gap-3 min-w-0 flex-1 text-left cursor-pointer"
                >
                  <ChevronDown className={`w-4 h-4 text-gray-400 transition-transform flex-shrink-0 ${isExpanded ? '' : '-rotate-90'}`} />
                  {proj.coverImage ? (
                    <img loading="lazy" decoding="async" src={proj.coverImage} alt="" className="w-10 h-10 object-cover rounded-sm border border-gray-200 flex-shrink-0" />
                  ) : (
                    <div className="w-10 h-10 rounded-sm bg-gray-100 flex-shrink-0" />
                  )}
                  <div className="min-w-0">
                    <div className="flex items-center gap-2 flex-wrap">
                      <span className="text-sm font-semibold text-gray-900 truncate">{proj.title}</span>
                      <span className={`text-[10px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded-full ${
                        proj.type === 'video' ? 'bg-amber-100 text-amber-800' : 'bg-blue-100 text-blue-800'
                      }`}>
                        {proj.type === 'video' ? '4K VIDEO' : 'PHOTO'}
                      </span>
                      <span className={`text-[10px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded-full border ${
                        status === 'published'
                          ? 'bg-green-50 text-green-700 border-green-200'
                          : status === 'draft'
                            ? 'bg-amber-50 text-amber-700 border-amber-200'
                            : 'bg-gray-100 text-gray-500 border-gray-200'
                      }`}>
                        {status}
                      </span>
                      {proj.featured && (
                        <span className="text-[10px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded-full bg-yellow-100 text-yellow-800">
                          Featured flag
                        </span>
                      )}
                    </div>
                    <span className="text-[11px] text-gray-400 truncate block">#!project={proj.slug}</span>
                  </div>
                </button>

                {/* Quick actions */}
                <div className="flex items-center gap-1 flex-shrink-0">
                  {(status === 'draft' || status === 'archived') && (
                    <button type="button" onClick={() => patchProject(proj.id, { status: 'published' })}
                      title="Publish" className="p-2 text-green-600 hover:bg-green-50 rounded-md cursor-pointer"><Eye className="w-4 h-4" /></button>
                  )}
                  {status === 'published' && (
                    <button type="button" onClick={() => patchProject(proj.id, { status: 'draft' })}
                      title="Unpublish (hide from site)" className="p-2 text-amber-600 hover:bg-amber-50 rounded-md cursor-pointer"><EyeOff className="w-4 h-4" /></button>
                  )}
                  {status !== 'archived' ? (
                    <button type="button" onClick={() => patchProject(proj.id, { status: 'archived' })}
                      title="Archive" className="p-2 text-gray-500 hover:bg-gray-100 rounded-md cursor-pointer"><Archive className="w-4 h-4" /></button>
                  ) : (
                    <button type="button" onClick={() => patchProject(proj.id, { status: 'draft' })}
                      title="Restore to draft" className="p-2 text-gray-500 hover:bg-gray-100 rounded-md cursor-pointer"><ArchiveRestore className="w-4 h-4" /></button>
                  )}
                  <button type="button" onClick={() => moveProject(proj.id, -1)} disabled={globalIdx <= 0}
                    title="Move earlier" className="p-2 text-gray-500 hover:bg-gray-100 rounded-md disabled:opacity-25 cursor-pointer"><ArrowUp className="w-4 h-4" /></button>
                  <button type="button" onClick={() => moveProject(proj.id, 1)} disabled={globalIdx >= projects.length - 1}
                    title="Move later" className="p-2 text-gray-500 hover:bg-gray-100 rounded-md disabled:opacity-25 cursor-pointer"><ArrowDown className="w-4 h-4" /></button>
                  <button type="button" onClick={() => deleteProject(proj)}
                    title="Delete permanently" className="p-2 text-red-500 hover:bg-red-50 rounded-md cursor-pointer"><Trash2 className="w-4 h-4" /></button>
                </div>
              </div>

              {/* Expanded editor */}
              {isExpanded && (
                <div className="border-t border-gray-100 px-4 sm:px-6 py-5 space-y-4 bg-gray-50/60">
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <TextInput label="Title" value={proj.title || ''} onChange={v => patchProject(proj.id, { title: v })} />
                    <div>
                      <TextInput
                        label="Slug (URL)"
                        value={proj.slug || ''}
                        onChange={v => patchProject(proj.id, { slug: v.toLowerCase().replace(/[^a-z0-9]+/g, '-') })}
                        hint={`#!project=${proj.slug}`}
                      />
                      {projects.filter(p => p.id !== proj.id && p.slug && p.slug === proj.slug).length > 0 && (
                        <p className="mt-1 text-[11px] text-red-600 flex items-center gap-1"><AlertTriangle className="w-3 h-3" /> Duplicate slug — links will break.</p>
                      )}
                    </div>
                  </div>

                  <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <TextInput label="Client" value={proj.client || ''} onChange={v => patchProject(proj.id, { client: v })} />
                    <div>
                      <FieldLabel label="Category" />
                      <input
                        list={`cat-options-${proj.id}`}
                        value={proj.category || ''}
                        onChange={e => patchProject(proj.id, { category: e.target.value.toUpperCase() })}
                        className="w-full bg-white border border-gray-300 rounded-md px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-gray-900"
                      />
                      <datalist id={`cat-options-${proj.id}`}>
                        {knownCategories.map(c => <option key={c} value={c} />)}
                      </datalist>
                    </div>
                    <TextInput label="Category label" value={proj.categoryLabel || ''} onChange={v => patchProject(proj.id, { categoryLabel: v })} />
                    <TextInput label="Year" value={proj.year || ''} onChange={v => patchProject(proj.id, { year: v })} />
                  </div>

                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <SelectField label="Status" value={status} onChange={v => patchProject(proj.id, { status: v })} options={STATUS_OPTIONS} hint="Only Published is visible on the public site" />
                    <div className="flex items-end pb-1">
                      <button
                        type="button"
                        onClick={() => patchProject(proj.id, { featured: !proj.featured })}
                        className={`relative inline-flex items-center gap-2 px-3 py-2 rounded-md border text-xs font-medium transition-colors cursor-pointer ${
                          proj.featured ? 'bg-yellow-50 border-yellow-300 text-yellow-800' : 'bg-white border-gray-300 text-gray-600'
                        }`}
                      >
                        <span className={`inline-block w-7 h-4 rounded-full relative transition-colors ${proj.featured ? 'bg-yellow-500' : 'bg-gray-300'}`}>
                          <span className={`absolute top-0.5 w-3 h-3 rounded-full bg-white shadow transition-all ${proj.featured ? 'left-3.5' : 'left-0.5'}`} />
                        </span>
                        Featured fallback flag
                      </button>
                    </div>
                  </div>

                  <TextArea label="Summary" value={proj.summary || ''} onChange={v => patchProject(proj.id, { summary: v })} rows={2} />

                  <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
                    <TextArea label="The Challenge" value={proj.challenge || ''} onChange={v => patchProject(proj.id, { challenge: v })} rows={3} />
                    <TextArea label="The Solution" value={proj.solution || ''} onChange={v => patchProject(proj.id, { solution: v })} rows={3} />
                  </div>

                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <TextInput label="Deliverables (comma-separated)" value={(proj.deliverables || []).join(', ')} onChange={v => patchProject(proj.id, { deliverables: v.split(',').map((s: string) => s.trim()).filter(Boolean) })} />
                    <TextInput label="Tags (comma-separated)" value={(proj.tags || []).join(', ')} onChange={v => patchProject(proj.id, { tags: v.split(',').map((s: string) => s.trim()).filter(Boolean) })} />
                  </div>

                  <ImageUpload label="Cover image" currentSrc={proj.coverImage} onUpload={url => patchProject(proj.id, { coverImage: url })} />

                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <VideoInput label="Hero video URL (optional)" value={proj.videoUrl || ''} onChange={v => patchProject(proj.id, { videoUrl: v })} />
                    <ImageUpload label="Hero video poster frame" currentSrc={proj.videoPoster || ''} onUpload={url => patchProject(proj.id, { videoPoster: url })} />
                  </div>

                  <TextInput
                    label="Hero media framing (object-position)"
                    value={proj.heroPosition || ''}
                    onChange={v => patchProject(proj.id, { heroPosition: v })}
                    placeholder="center center / center 20% …"
                    hint="Fine-tunes how the detail-page hero crops the subject"
                  />

                  <CollapseSection title="SEO for this project">
                    <TextInput label="Custom SEO title" value={proj.seoTitle || ''} onChange={v => patchProject(proj.id, { seoTitle: v })} placeholder={`${proj.title} — CreativeFX`} />
                    <TextArea label="Custom meta description" value={proj.seoDescription || ''} onChange={v => patchProject(proj.id, { seoDescription: v })} rows={2} placeholder={proj.summary || ''} />
                  </CollapseSection>

                  <div className="border-t border-gray-200 pt-4">
                    <GalleryMediaManager
                      label="Project media gallery"
                      items={proj.gallery || []}
                      onChange={(newGal: any[]) => patchProject(proj.id, { gallery: newGal })}
                      hint="Photos & videos shown on the detail page. Use ▲ ▼ to reorder, ✎ for alt/caption/poster."
                    />
                  </div>
                </div>
              )}
            </div>
          );
        })}

        {pageItems.length === 0 && (
          <div className="p-10 border border-dashed border-gray-300 rounded-lg bg-white text-center">
            <p className="text-sm font-medium text-gray-600">No projects match this filter</p>
            <p className="text-xs text-gray-400 mt-1">Adjust search or status filters above.</p>
          </div>
        )}
      </div>

      {/* Pagination */}
      {pageCount > 1 && (
        <div className="flex items-center justify-between pt-2">
          <button
            type="button"
            disabled={safePage === 0}
            onClick={() => setPage(p => Math.max(0, p - 1))}
            className="px-4 py-2 text-xs font-medium bg-white border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-40 cursor-pointer disabled:cursor-not-allowed"
          >
            ← Previous
          </button>
          <span className="text-xs text-gray-500 font-mono">Page {safePage + 1} of {pageCount}</span>
          <button
            type="button"
            disabled={safePage >= pageCount - 1}
            onClick={() => setPage(p => Math.min(pageCount - 1, p + 1))}
            className="px-4 py-2 text-xs font-medium bg-white border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-40 cursor-pointer disabled:cursor-not-allowed"
          >
            Next →
          </button>
        </div>
      )}
    </div>
  );
};
