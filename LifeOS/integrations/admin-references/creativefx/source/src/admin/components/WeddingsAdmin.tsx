import React, { useState } from 'react';
import { Plus, Trash2, ArrowUp, ArrowDown } from 'lucide-react';
import {
  TextInput, TextArea, CollapseSection, PageHeader, ImageUpload, GalleryMediaManager,
} from './AdminFields';
import type { SiteContent } from '../../context/ContentContext';

interface WeddingsAdminProps {
  content: SiteContent;
  update: (path: string[], value: any) => void;
  tab: string;
}

const uid = (prefix: string) => `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`;

/**
 * Admin → Weddings.
 * Controls the public /weddings experience: hero, day-of timeline ("story
 * sections"), real wedding stories (couples, galleries, films) and the
 * approach principles.
 */
export const WeddingsAdmin: React.FC<WeddingsAdminProps> = ({ content, update, tab }) => {
  const timeline = content.weddingTimeline || [];
  const stories = content.weddingStories || [];
  const approach = content.weddingApproach || [];

  // ── Timeline stage helpers ──
  const patchStage = (id: string, patch: any) =>
    update(['weddingTimeline'], timeline.map(s => (s.id === id ? { ...s, ...patch } : s)));
  const moveStage = (idx: number, dir: -1 | 1) => {
    const t = idx + dir;
    if (t < 0 || t >= timeline.length) return;
    const next = [...timeline];
    [next[idx], next[t]] = [next[t], next[idx]];
    update(['weddingTimeline'], next);
  };

  // ── Story helpers ──
  const patchStory = (id: string, patch: any) =>
    update(['weddingStories'], stories.map(s => (s.id === id ? { ...s, ...patch } : s)));
  const deleteStory = (s: any) => {
    if (!confirm(`Delete the wedding story "${s.couple}"? This cannot be undone.`)) return;
    update(['weddingStories'], stories.filter(x => x.id !== s.id));
  };
  const addStory = () => {
    const fresh = {
      id: uid('story'),
      slug: `wedding-${Date.now()}`,
      storyNumber: String(stories.length + 1).padStart(2, '0'),
      couple: 'NEW COUPLE',
      location: 'COLOMBO, SRI LANKA',
      venue: '',
      date: '',
      coverImage: '',
      heroImage: '',
      thumbnail: '',
      storyQuote: '',
      storyParagraphs: ['Tell their story here — first paragraph.', 'Second paragraph.'],
      gallery: [],
      videoUrl: '',
      videoPoster: '',
      highlights: [
        { title: 'COVERAGE STYLE', desc: 'Natural, candid, and cinematic storytelling' },
        { title: 'LIGHTING', desc: 'Warm natural light and elegant ambient lighting' },
        { title: 'EXPERIENCE', desc: 'Relaxed, unobtrusive, and genuinely fun' },
      ],
      details: { photographer: '', cinematographer: '', cameraFormat: '', deliveredFrames: '' },
      status: 'draft',
    };
    update(['weddingStories'], [...stories, fresh]);
  };

  // ── Approach helpers ──
  const patchPrinciple = (idx: number, patch: any) =>
    update(['weddingApproach'], approach.map((p, i) => (i === idx ? { ...p, ...patch } : p)));

  // ── HERO TAB ──
  if (tab === 'Hero') {
    return (
      <div className="space-y-6">
        <PageHeader title="Weddings hero" description="Headline and media at the top of the /weddings experience." />
        <CollapseSection title="Hero content" defaultOpen>
          <TextArea label="Hero headline (line breaks allowed)" value={content.weddings?.heroHeadline || ''} onChange={v => update(['weddings', 'heroHeadline'], v)} rows={4} />
          <TextInput label="Top label" value={content.weddings?.heroSubtitle || ''} onChange={v => update(['weddings', 'heroSubtitle'], v)} />
          <TextArea label="Description" value={content.weddings?.heroDescription || ''} onChange={v => update(['weddings', 'heroDescription'], v)} rows={2} />
          <ImageUpload label="Hero image" currentSrc={content.weddings?.heroImage || ''} onUpload={url => update(['weddings', 'heroImage'], url)} />
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <TextInput label="Reel button label" value={content.weddings?.heroReelLabel || ''} onChange={v => update(['weddings', 'heroReelLabel'], v)} />
            <TextInput label="Image caption (bottom-right)" value={content.weddings?.heroCaption || ''} onChange={v => update(['weddings', 'heroCaption'], v)} />
          </div>
        </CollapseSection>

        <CollapseSection title="Section headings">
          <TextArea label="Timeline headline" value={content.weddings?.timelineHeadline || ''} onChange={v => update(['weddings', 'timelineHeadline'], v)} rows={2} />
          <TextArea label="Timeline intro" value={content.weddings?.timelineIntro || ''} onChange={v => update(['weddings', 'timelineIntro'], v)} rows={2} />
          <TextArea label="Stories headline" value={content.weddings?.storiesHeadline || ''} onChange={v => update(['weddings', 'storiesHeadline'], v)} rows={2} />
          <TextArea label="Approach headline" value={content.weddings?.approachHeadline || ''} onChange={v => update(['weddings', 'approachHeadline'], v)} rows={2} />
        </CollapseSection>
      </div>
    );
  }

  // ── TIMELINE TAB ──
  if (tab === 'Timeline') {
    return (
      <div className="space-y-6">
        <PageHeader title="Wedding day timeline" description={`The ${timeline.length} chapter tabs visitors switch through in “Your Wedding Day Timeline”.`} />

        {timeline.map((stage: any, idx: number) => (
          <CollapseSection
            key={stage.id}
            title={`Chapter ${stage.stageNumber} — ${stage.title}`}
            defaultOpen={false}
            badge={`${stage.moments?.length || 0} moments`}
          >
            <div className="flex items-center justify-between pb-3 border-b border-gray-100">
              <span className="text-xs text-gray-500">Order on page: <b>#{idx + 1}</b></span>
              <div className="flex gap-1.5">
                <button type="button" onClick={() => moveStage(idx, -1)} disabled={idx === 0}
                  className="px-2.5 py-1 text-xs bg-white border border-gray-300 rounded hover:bg-gray-100 disabled:opacity-30 cursor-pointer flex items-center gap-1"><ArrowUp className="w-3 h-3" /> Up</button>
                <button type="button" onClick={() => moveStage(idx, 1)} disabled={idx === timeline.length - 1}
                  className="px-2.5 py-1 text-xs bg-white border border-gray-300 rounded hover:bg-gray-100 disabled:opacity-30 cursor-pointer flex items-center gap-1"><ArrowDown className="w-3 h-3" /> Down</button>
                <button type="button"
                  onClick={() => { if (confirm('Delete this chapter?')) update(['weddingTimeline'], timeline.filter((_: any, i: number) => i !== idx)); }}
                  className="px-2.5 py-1 text-xs text-red-600 bg-white border border-red-200 rounded hover:bg-red-50 cursor-pointer flex items-center gap-1"><Trash2 className="w-3 h-3" /> Delete</button>
              </div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <TextInput label="Chapter number" value={stage.stageNumber || ''} onChange={v => patchStage(stage.id, { stageNumber: v })} />
              <TextInput label="Chapter title" value={stage.title || ''} onChange={v => patchStage(stage.id, { title: v })} />
              <TextInput label="Mood note (photo overlay)" value={stage.moodNote || ''} onChange={v => patchStage(stage.id, { moodNote: v })} />
            </div>
            <TextArea label="Short description (tab tooltip)" value={stage.shortDesc || ''} onChange={v => patchStage(stage.id, { shortDesc: v })} rows={2} />
            <TextArea label="Full description" value={stage.fullDesc || ''} onChange={v => patchStage(stage.id, { fullDesc: v })} rows={3} />
            <ImageUpload label="Chapter image" currentSrc={stage.image || ''} onUpload={url => patchStage(stage.id, { image: url })} />
            <TextArea
              label='"What we capture" moments (one per line)'
              value={(stage.moments || []).join('\n')}
              onChange={v => patchStage(stage.id, { moments: v.split('\n').map((s: string) => s.trim()).filter(Boolean) })}
              rows={4}
            />
          </CollapseSection>
        ))}

        <button
          type="button"
          onClick={() => update(['weddingTimeline'], [
            ...timeline,
            { id: uid('stage'), stageNumber: String(timeline.length + 1).padStart(2, '0'), title: 'NEW CHAPTER', shortDesc: '', fullDesc: '', image: '/img/wedding/Ravindu & Malikshi/DSC09233.jpg', moodNote: '', moments: [] },
          ])}
          className="w-full flex items-center justify-center gap-2 border border-dashed border-gray-300 rounded-lg py-3.5 text-sm font-medium text-gray-600 hover:border-gray-900 hover:text-gray-900 transition-colors cursor-pointer"
        >
          <Plus className="w-4 h-4" /> Add a new chapter
        </button>
      </div>
    );
  }

  // ── APPROACH TAB ──
  if (tab === 'Approach') {
    return (
      <div className="space-y-6">
        <PageHeader title="Approach & philosophy" description="The principle cards in the philosophy section." />
        {approach.map((p: any, idx: number) => (
          <CollapseSection key={idx} title={`Principle ${p.number} — ${p.title}`} defaultOpen={idx === 0}>
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <TextInput label="Number" value={p.number || ''} onChange={v => patchPrinciple(idx, { number: v })} />
              <TextInput label="Title" value={p.title || ''} onChange={v => patchPrinciple(idx, { title: v })} />
              <div className="flex items-end justify-end gap-2">
                <button type="button" onClick={() => { if (confirm('Delete this principle?')) update(['weddingApproach'], approach.filter((_: any, i: number) => i !== idx)); }}
                  className="p-2 text-red-500 hover:bg-red-50 rounded-md border border-red-200 cursor-pointer"><Trash2 className="w-4 h-4" /></button>
              </div>
            </div>
            <TextArea label="Description" value={p.desc || ''} onChange={v => patchPrinciple(idx, { desc: v })} rows={3} />
          </CollapseSection>
        ))}
        <button
          type="button"
          onClick={() => update(['weddingApproach'], [...approach, { number: String(approach.length + 1).padStart(2, '0'), title: 'NEW PRINCIPLE', desc: '' }])}
          className="w-full flex items-center justify-center gap-2 border border-dashed border-gray-300 rounded-lg py-3.5 text-sm font-medium text-gray-600 hover:border-gray-900 hover:text-gray-900 transition-colors cursor-pointer"
        >
          <Plus className="w-4 h-4" /> Add a principle
        </button>
      </div>
    );
  }

  // ── STORIES TAB (default) ──
  return (
    <div className="space-y-6">
      <PageHeader title="Wedding stories" description={`${stories.length} real wedding records — couples, galleries and films shown on /weddings.`} />

      <div className="flex justify-end">
        <button
          type="button"
          onClick={addStory}
          className="flex items-center gap-2 px-5 py-2.5 bg-gray-900 text-white text-xs font-semibold rounded-md hover:bg-gray-700 transition-colors cursor-pointer"
        >
          <Plus className="w-4 h-4" /> New wedding story
        </button>
      </div>

      {stories.map((story: any, idx: number) => (
        <CollapseSection key={story.id} title={`${String(idx + 1).padStart(2, '0')} — ${story.couple}`} badge={story.status ?? 'published'}>
          <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <TextInput label="Couple names" value={story.couple || ''} onChange={v => patchStory(story.id, { couple: v.toUpperCase() })} />
            <TextInput label="Location" value={story.location || ''} onChange={v => patchStory(story.id, { location: v })} />
            <TextInput label="Venue" value={story.venue || ''} onChange={v => patchStory(story.id, { venue: v })} />
            <TextInput label="Date label" value={story.date || ''} onChange={v => patchStory(story.id, { date: v })} placeholder="JANUARY 2026" />
          </div>

          <TextArea label="Quote from the couple" value={story.storyQuote || ''} onChange={v => patchStory(story.id, { storyQuote: v })} rows={2} />

          <TextArea
            label="Story paragraphs (one per line)"
            value={(story.storyParagraphs || []).join('\n')}
            onChange={v => patchStory(story.id, { storyParagraphs: v.split('\n').map((s: string) => s.trim()).filter(Boolean) })}
            rows={4}
          />

          <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <ImageUpload label="Cover image" currentSrc={story.coverImage || ''} onUpload={url => patchStory(story.id, { coverImage: url })} />
            <ImageUpload label="Hero image" currentSrc={story.heroImage || ''} onUpload={url => patchStory(story.id, { heroImage: url })} />
            <ImageUpload label="Thumbnail" currentSrc={story.thumbnail || ''} onUpload={url => patchStory(story.id, { thumbnail: url })} />
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <TextArea label="Film video URL" value={story.videoUrl || ''} onChange={v => patchStory(story.id, { videoUrl: v })} rows={2} placeholder="/uploads/film.mp4 or Google Drive link" />
            <ImageUpload label="Film poster frame" currentSrc={story.videoPoster || ''} onUpload={url => patchStory(story.id, { videoPoster: url })} />
          </div>

          <div className="border-t border-gray-100 pt-4">
            <GalleryMediaManager
              label="Story gallery"
              items={story.gallery || []}
              onChange={(g: any[]) => patchStory(story.id, { gallery: g })}
              hint="Shown in the case-study lightbox. ✎ adds captions."
            />
          </div>

          <div className="border-t border-gray-100 pt-4 space-y-3">
            <p className="text-xs font-semibold text-gray-700 uppercase tracking-wide">Production details</p>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <TextInput label="Photographer" value={story.details?.photographer || ''} onChange={v => patchStory(story.id, { details: { ...(story.details || {}), photographer: v } })} />
              <TextInput label="Cinematographer" value={story.details?.cinematographer || ''} onChange={v => patchStory(story.id, { details: { ...(story.details || {}), cinematographer: v } })} />
              <TextInput label="Camera format" value={story.details?.cameraFormat || ''} onChange={v => patchStory(story.id, { details: { ...(story.details || {}), cameraFormat: v } })} />
              <TextInput label="Delivered frames" value={story.details?.deliveredFrames || ''} onChange={v => patchStory(story.id, { details: { ...(story.details || {}), deliveredFrames: v } })} />
            </div>
          </div>

          <div className="border-t border-gray-100 pt-4 grid grid-cols-1 sm:grid-cols-2 gap-3 items-start">
            <div>
              <p className="text-xs font-semibold text-gray-700 uppercase tracking-wide mb-1.5">Status</p>
              <select
                value={story.status ?? 'published'}
                onChange={e => patchStory(story.id, { status: e.target.value })}
                className="w-full bg-white border border-gray-300 rounded-md px-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-gray-900 cursor-pointer"
              >
                <option value="published">Published</option>
                <option value="draft">Draft (hidden)</option>
                <option value="archived">Archived</option>
              </select>
            </div>
            <div className="flex sm:justify-end items-end">
              <button
                type="button"
                onClick={() => deleteStory(story)}
                className="flex items-center gap-1.5 text-red-600 hover:text-red-700 text-[11px] font-mono uppercase tracking-widest px-3 py-2 bg-red-50 rounded-md border border-red-200 transition-colors cursor-pointer"
              >
                <Trash2 className="w-3.5 h-3.5" /> Delete story
              </button>
            </div>
          </div>
        </CollapseSection>
      ))}
    </div>
  );
};
