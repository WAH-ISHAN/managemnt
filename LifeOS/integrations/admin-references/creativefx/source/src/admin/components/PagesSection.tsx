import React from 'react';
import { TextInput, TextArea, CollapseSection, PageHeader } from './AdminFields';
import type { SiteContent } from '../../context/ContentContext';

interface PagesSectionProps {
  item: string;
  content: SiteContent;
  update: (path: string[], value: any) => void;
}

/**
 * Admin → Pages.
 * Only page-level copy lives here; section content belongs to its own module
 * (Homepage / Weddings / Services / About / Contact).
 */
export const PagesSection: React.FC<PagesSectionProps> = ({ item, content, update }) => {
  // ── WORKS PAGE ──
  if (item === 'Works') {
    return (
      <div className="space-y-6">
        <PageHeader title="Works page" description="Header copy at the top of the works catalog (/works)." />

        <CollapseSection title="Catalog header" defaultOpen>
          <TextArea
            label="Page title (line breaks allowed)"
            value={content.pages?.worksTitle || ''}
            onChange={v => update(['pages', 'worksTitle'], v)}
            rows={2}
            placeholder={'WORKS /\nPROJECTS'}
          />
          <TextArea
            label="Page intro subtitle"
            value={content.pages?.worksIntro || ''}
            onChange={v => update(['pages', 'worksIntro'], v)}
            rows={3}
          />
          <p className="text-[11px] text-gray-400">
            Filter chips on this page are generated automatically from project categories — manage them per project under Works / Projects.
          </p>
        </CollapseSection>
      </div>
    );
  }

  // ── CONTACT PAGE ──
  if (item === 'Contact') {
    return (
      <div className="space-y-6">
        <PageHeader title="Contact page copy" description="Headline and form messaging. Studio details & socials live under Contact / Inquiries." />

        <CollapseSection title="Contact section copy" defaultOpen>
          <TextInput
            label="Section label"
            value={content.contactPage?.label || ''}
            onChange={v => update(['contactPage', 'label'], v)}
            placeholder="07 / CONTACT"
          />
          <TextArea
            label="Heading"
            value={content.contactPage?.headline || ''}
            onChange={v => update(['contactPage', 'headline'], v)}
            rows={2}
            placeholder={'Contact Us'}
          />
          <TextArea
            label="Description"
            value={content.contactPage?.description || ''}
            onChange={v => update(['contactPage', 'description'], v)}
            rows={3}
          />
        </CollapseSection>

        <CollapseSection title="Success state">
          <TextInput
            label="Success title"
            value={content.contactPage?.successTitle || ''}
            onChange={v => update(['contactPage', 'successTitle'], v)}
            placeholder="MESSAGE RECEIVED."
          />
          <TextArea
            label="Success message"
            value={content.contactPage?.successBody || ''}
            onChange={v => update(['contactPage', 'successBody'], v)}
            rows={2}
          />
        </CollapseSection>
      </div>
    );
  }

  return null;
};
