import React, { useState } from 'react';
import { Globe, Plus, Trash2, Link as LinkIcon, Sparkles } from 'lucide-react';
import { TextInput, CollapseSection, ToggleSwitch, PageHeader } from './AdminFields';
import type { SiteContent } from '../../context/ContentContext';

interface WebsiteSectionProps {
  item: string;
  content: SiteContent;
  update: (path: string[], value: any) => void;
}

/** Admin → Website (Navigation / Footer / Page Visibility). */
export const WebsiteSection: React.FC<WebsiteSectionProps> = ({ item, content, update }) => {
  // ── NAVIGATION (RULE 5) ──
  if (item === 'Navigation') {
    const nav = content.nav || {
      work: 'PORTFOLIO',
      services: 'SERVICES',
      weddings: 'WEDDINGS',
      about: 'ABOUT',
      cta: 'INQUIRE',
      ctaUrl: '#section-contact',
      showWork: true,
      showServices: true,
      showWeddings: true,
      showAbout: true,
      showCta: true,
      transparentOnTop: true,
      glassBlur: true,
      customLinks: [],
    };
    const settings = content.settings || {};
    const customLinks = Array.isArray(nav.customLinks) ? nav.customLinks : [];

    const addCustomLink = () => {
      const newLinks = [
        ...customLinks,
        { id: `link-${Date.now()}`, label: 'NEW LINK', url: '#', active: true, isExternal: false }
      ];
      update(['nav', 'customLinks'], newLinks);
    };

    const updateCustomLink = (idx: number, field: string, value: any) => {
      const copy = [...customLinks];
      copy[idx] = { ...copy[idx], [field]: value };
      update(['nav', 'customLinks'], copy);
    };

    const removeCustomLink = (idx: number) => {
      const copy = customLinks.filter((_, i) => i !== idx);
      update(['nav', 'customLinks'], copy);
    };

    return (
      <div className="space-y-6">
        <PageHeader
          icon={Globe}
          title="Navigation & Header Customizer"
          description="Customize all navigation menu links, visibility toggles, call-to-action buttons, and announcement banner."
        />

        {/* Primary Navbar Links */}
        <CollapseSection title="Primary Navigation Menu Links" defaultOpen>
          <div className="space-y-5">
            <p className="text-xs text-gray-500">
              Customize the labels and toggle visibility for default menu links shown on desktop and mobile menus.
            </p>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div className="bg-gray-50 p-3 rounded-lg border border-gray-200 space-y-2">
                <TextInput label="Portfolio / Work Link Label" value={nav.work} onChange={v => update(['nav', 'work'], v)} />
                <ToggleSwitch label="Show Portfolio Link" checked={nav.showWork ?? true} onChange={v => update(['nav', 'showWork'], v)} />
              </div>

              <div className="bg-gray-50 p-3 rounded-lg border border-gray-200 space-y-2">
                <TextInput label="Services Link Label" value={nav.services} onChange={v => update(['nav', 'services'], v)} />
                <ToggleSwitch label="Show Services Link" checked={nav.showServices ?? true} onChange={v => update(['nav', 'showServices'], v)} />
              </div>

              <div className="bg-gray-50 p-3 rounded-lg border border-gray-200 space-y-2">
                <TextInput label="Weddings Link Label" value={nav.weddings} onChange={v => update(['nav', 'weddings'], v)} />
                <ToggleSwitch label="Show Weddings Link" checked={nav.showWeddings ?? true} onChange={v => update(['nav', 'showWeddings'], v)} />
              </div>

              <div className="bg-gray-50 p-3 rounded-lg border border-gray-200 space-y-2">
                <TextInput label="About Us Link Label" value={nav.about} onChange={v => update(['nav', 'about'], v)} />
                <ToggleSwitch label="Show About Link" checked={nav.showAbout ?? true} onChange={v => update(['nav', 'showAbout'], v)} />
              </div>
            </div>
          </div>
        </CollapseSection>

        {/* CTA Button Customization */}
        <CollapseSection title="Call-To-Action (CTA) Button" defaultOpen>
          <div className="space-y-4">
            <p className="text-xs text-gray-500">
              The primary action button on the top right of the navigation header.
            </p>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <TextInput
                label="CTA Button Text"
                value={nav.cta || 'INQUIRE'}
                onChange={v => update(['nav', 'cta'], v)}
                placeholder="e.g. INQUIRE, BOOK NOW, START PROJECT"
              />
              <TextInput
                label="CTA Action / Target URL"
                value={nav.ctaUrl || '#section-contact'}
                onChange={v => update(['nav', 'ctaUrl'], v)}
                placeholder="e.g. #section-contact, /contact, or WhatsApp URL"
              />
            </div>

            <ToggleSwitch
              label="Display CTA Button in Header"
              checked={nav.showCta ?? true}
              onChange={v => update(['nav', 'showCta'], v)}
              description="Turn off to hide the top-right button completely"
            />
          </div>
        </CollapseSection>

        {/* Custom Navigation Links */}
        <CollapseSection title="Custom Extra Navigation Links">
          <div className="space-y-4">
            <p className="text-xs text-gray-500">
              Add bespoke links to your navigation header (e.g. Client Portal, Blog, Shop, WhatsApp).
            </p>

            {customLinks.map((link, idx) => (
              <div key={link.id || idx} className="flex flex-col sm:flex-row gap-3 items-end p-3 bg-gray-50 rounded-lg border border-gray-200">
                <div className="flex-1">
                  <TextInput
                    label="Link Label"
                    value={link.label}
                    onChange={v => updateCustomLink(idx, 'label', v)}
                    placeholder="e.g. CLIENT PORTAL"
                  />
                </div>
                <div className="flex-1">
                  <TextInput
                    label="Target URL"
                    value={link.url}
                    onChange={v => updateCustomLink(idx, 'url', v)}
                    placeholder="https://..."
                  />
                </div>
                <div className="flex items-center gap-2 pb-1">
                  <button
                    type="button"
                    onClick={() => updateCustomLink(idx, 'isExternal', !link.isExternal)}
                    className={`px-3 py-2 text-xs font-semibold rounded border ${
                      link.isExternal ? 'bg-gray-900 text-white' : 'bg-white text-gray-700'
                    }`}
                  >
                    {link.isExternal ? 'New Tab ↗' : 'Same Page'}
                  </button>
                  <button
                    type="button"
                    onClick={() => removeCustomLink(idx)}
                    className="p-2 text-red-600 hover:bg-red-50 rounded"
                    title="Remove Link"
                  >
                    <Trash2 className="w-4 h-4" />
                  </button>
                </div>
              </div>
            ))}

            <button
              type="button"
              onClick={addCustomLink}
              className="flex items-center gap-2 bg-gray-900 hover:bg-gray-800 text-white text-xs font-semibold px-4 py-2 rounded-md transition-colors cursor-pointer"
            >
              <Plus className="w-3.5 h-3.5" />
              Add Custom Link
            </button>
          </div>
        </CollapseSection>

        {/* Announcement Bar */}
        <CollapseSection title="Announcement Strip Bar">
          <div className="space-y-4">
            <ToggleSwitch
              label="Enable Announcement Bar"
              checked={settings.announcementEnabled ?? false}
              onChange={v => update(['settings', 'announcementEnabled'], v)}
              description="Displays a sleek glowing banner above the navigation bar"
            />
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <TextInput
                label="Announcement Text"
                value={settings.announcementText || ''}
                onChange={v => update(['settings', 'announcementText'], v)}
                placeholder="e.g. NOW BOOKING WEDDING DATES FOR 2026 / 2027"
              />
              <TextInput
                label="Announcement Target Link (Optional)"
                value={settings.announcementUrl || ''}
                onChange={v => update(['settings', 'announcementUrl'], v)}
                placeholder="https://... or #section-contact"
              />
            </div>
          </div>
        </CollapseSection>
      </div>
    );
  }

  // ── FOOTER ──
  if (item === 'Footer') {
    return (
      <div className="space-y-6">
        <PageHeader title="Footer Customizer" description="Brand line, copyright notice and social links at the bottom of the site." />

        <CollapseSection title="Branding & Copyright" defaultOpen>
          <div className="space-y-4">
            <TextInput label="Studio Tagline" value={content.footer?.tagline || ''} onChange={v => update(['footer', 'tagline'], v)} />
            <TextInput label="Studio Address Line" value={content.contact?.location || ''} onChange={v => update(['contact', 'location'], v)} />
            <TextInput label="Copyright Notice" value={content.footer?.copyright || ''} onChange={v => update(['footer', 'copyright'], v)} hint="The year is dynamically added" />
          </div>
        </CollapseSection>

        <CollapseSection title="Social Media URLs & Contact Handles" defaultOpen>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <TextInput label="Instagram Profile URL" value={content.contact?.instagram || ''} onChange={v => update(['contact', 'instagram'], v)} />
            <TextInput label="Facebook Page URL" value={content.contact?.facebook || ''} onChange={v => update(['contact', 'facebook'], v)} />
            <TextInput label="TikTok Profile URL" value={content.contact?.tiktok || ''} onChange={v => update(['contact', 'tiktok'], v)} />
            <TextInput label="WhatsApp Direct Phone Number" value={content.contact?.whatsapp || ''} onChange={v => update(['contact', 'whatsapp'], v)} hint="e.g. 94777548671" />
          </div>
        </CollapseSection>
      </div>
    );
  }

  // ── PAGE VISIBILITY ──
  if (item === 'Page Visibility') {
    const settings = { ...content.settings };
    return (
      <div className="space-y-4">
        <PageHeader
          title="Page & Global Features Visibility"
          description="Toggle major website features, smooth cursor, and performance options."
        />

        <div className="space-y-4 bg-white p-4 rounded-lg border border-gray-200">
          <ToggleSwitch
            label="Weddings Experience Page"
            checked={settings.showWeddings ?? true}
            onChange={v => update(['settings', 'showWeddings'], v)}
            description="Enables the Weddings showcase experience and navigation link"
          />

          <ToggleSwitch
            label="Portfolio / Works Archive Page"
            checked={settings.showWorks ?? true}
            onChange={v => update(['settings', 'showWorks'], v)}
            description="Enables the comprehensive Works gallery archive (/works)"
          />

          <ToggleSwitch
            label="Custom Magnetic Audio Cursor"
            checked={settings.customCursor ?? true}
            onChange={v => update(['settings', 'customCursor'], v)}
            description="Enables the luxury trailing circle and hover cursor"
          />

          <ToggleSwitch
            label="Master Animations Switch"
            checked={settings.animationsMasterEnabled ?? true}
            onChange={v => update(['settings', 'animationsMasterEnabled'], v)}
            description="Master switch for all GSAP and scroll animations (Turn off for low-power mode)"
          />
        </div>
      </div>
    );
  }

  return null;
};
