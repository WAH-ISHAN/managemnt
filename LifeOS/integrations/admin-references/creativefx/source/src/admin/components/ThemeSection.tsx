import React, { useState } from 'react';
import { Palette, Check, Type, Sparkles, Sliders, Layers, Eye } from 'lucide-react';
import { TextInput, CollapseSection, ImageUpload, ToggleSwitch, PageHeader } from './AdminFields';
import type { SiteContent } from '../../context/ContentContext';

interface ThemeSectionProps {
  content: SiteContent;
  update: (path: string[], value: any) => void;
}

const presetAccents = [
  { name: 'Signature Gold', hex: '#fcbf13' },
  { name: 'Neon Cyan', hex: '#00f0ff' },
  { name: 'Electric Violet', hex: '#8b5cf6' },
  { name: 'Crimson Flame', hex: '#ff0055' },
  { name: 'Emerald Luxe', hex: '#10b981' },
  { name: 'Pure Platinum', hex: '#ffffff' },
  { name: 'Cyber Amber', hex: '#ff9900' },
  { name: 'Rose Quartz', hex: '#f43f5e' },
];

const presetFonts = [
  { name: 'Forum (Classic Editorial)', family: 'Forum' },
  { name: 'JetBrains Mono (Futuristic Tech)', family: 'JetBrains Mono' },
  { name: 'League Spartan (Modern Bold)', family: 'League Spartan' },
  { name: 'Inter (Clean Neutral)', family: 'Inter' },
  { name: 'Cinzel (Luxury Serif)', family: 'Cinzel' },
  { name: 'Syne (Avant-Garde Art)', family: 'Syne' },
  { name: 'Montserrat (Geometric)', family: 'Montserrat' },
  { name: 'Space Grotesk (Tech Editorial)', family: 'Space Grotesk' },
  { name: 'Plus Jakarta Sans (Crisp Modern)', family: 'Plus Jakarta Sans' },
  { name: 'Playfair Display (High Fashion)', family: 'Playfair Display' },
];

const SECTIONS_LIST = [
  { key: 'hero', name: 'Hero Section (Main Banner)' },
  { key: 'intro', name: 'Introduction / Showreel' },
  { key: 'featuredWork', name: 'Featured Work (Pinned Scroll)' },
  { key: 'portfolio', name: 'Portfolio / Selected Work' },
  { key: 'services', name: 'Services & Capabilities' },
  { key: 'about', name: 'About Us / Studio Story' },
  { key: 'contact', name: 'Contact & Inquiry Form' },
  { key: 'cta', name: 'Final Call to Action' },
  { key: 'footer', name: 'Footer & Socials' },
  { key: 'weddings', name: 'Weddings Experience Page' },
];

export const ThemeSection: React.FC<ThemeSectionProps> = ({ content, update }) => {
  const theme = content.theme || {
    accentColor: '#fcbf13',
    backgroundColor: '#050505',
    textColor: '#ffffff',
    textMutedColor: '#888888',
    borderColor: 'rgba(255, 255, 255, 0.16)',
    fontDisplay: 'Forum',
    fontBody: 'JetBrains Mono',
    fontMono: 'JetBrains Mono',
    customGoogleFontUrl: '',
    logoUrl: '/img/creativefx-bgr-logo.png',
  };

  const [selectedSectionKey, setSelectedSectionKey] = useState('hero');
  const currentSectionStyle = content.sectionStyles?.[selectedSectionKey] || {};

  const setAccent = (hex: string) => update(['theme', 'accentColor'], hex);

  const updateSectionStyle = (field: string, value: any) => {
    update(['sectionStyles', selectedSectionKey, field], value);
  };

  return (
    <div className="space-y-6">
      <PageHeader
        icon={Palette}
        title="Theme, Typography & Colors"
        description="Full control over global and section-by-section colors, Google fonts, text sizing, and branding."
      />

      {/* ─── 1. GLOBAL ACCENT COLOR ─── */}
      <CollapseSection title="Global Brand Accent Color" defaultOpen>
        <div className="space-y-4">
          <p className="text-xs text-gray-500">
            Drives button backgrounds, glowing borders, active tags, and highlight elements across the entire website.
          </p>

          <div className="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
            {presetAccents.map(preset => (
              <button
                key={preset.hex}
                type="button"
                onClick={() => setAccent(preset.hex)}
                className={`p-2.5 rounded-md border flex items-center gap-2.5 transition-all text-left ${
                  theme.accentColor?.toLowerCase() === preset.hex.toLowerCase()
                    ? 'border-gray-900 bg-gray-50 shadow-sm ring-1 ring-gray-900'
                    : 'border-gray-200 bg-white hover:border-gray-400'
                }`}
              >
                <div
                  className="w-4 h-4 rounded-full flex-shrink-0 flex items-center justify-center border border-black/10 shadow-inner"
                  style={{ backgroundColor: preset.hex }}
                >
                  {theme.accentColor?.toLowerCase() === preset.hex.toLowerCase() && (
                    <Check className="w-2.5 h-2.5 text-white mix-blend-difference" />
                  )}
                </div>
                <div className="truncate">
                  <div className="text-xs font-semibold text-gray-900 truncate">{preset.name}</div>
                  <div className="text-[10px] text-gray-400 font-mono uppercase">{preset.hex}</div>
                </div>
              </button>
            ))}
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2">
            <TextInput
              label="Custom Accent Color Hex"
              value={theme.accentColor || '#fcbf13'}
              onChange={v => /^#[0-9a-fA-F]{0,6}$/.test(v) && setAccent(v)}
              placeholder="#fcbf13"
              hint="Format: #RRGGBB"
            />
            <TextInput
              label="Global Site Background Color"
              value={theme.backgroundColor || '#050505'}
              onChange={v => update(['theme', 'backgroundColor'], v)}
              placeholder="#050505"
              hint="Default: #050505 (Dark Cinema)"
            />
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <TextInput
              label="Primary Text Color"
              value={theme.textColor || '#ffffff'}
              onChange={v => update(['theme', 'textColor'], v)}
              placeholder="#ffffff"
            />
            <TextInput
              label="Muted Text Color"
              value={theme.textMutedColor || '#888888'}
              onChange={v => update(['theme', 'textMutedColor'], v)}
              placeholder="#888888"
            />
            <TextInput
              label="Border & Line Color"
              value={theme.borderColor || 'rgba(255, 255, 255, 0.16)'}
              onChange={v => update(['theme', 'borderColor'], v)}
              placeholder="rgba(255, 255, 255, 0.16)"
            />
          </div>

          {/* Live Preview Strip */}
          <div className="p-3 bg-[#0a0a0a] rounded-lg border border-neutral-800 flex items-center justify-between gap-4">
            <div className="flex items-center gap-3">
              <span
                className="px-3 py-1 rounded text-xs font-bold text-black uppercase"
                style={{ backgroundColor: theme.accentColor || '#fcbf13' }}
              >
                Button Preview
              </span>
              <span className="text-xs font-medium" style={{ color: theme.accentColor || '#fcbf13' }}>
                ✦ Glowing Highlight
              </span>
            </div>
            <span className="text-xs text-neutral-400 font-mono">Live CSS Variable Output</span>
          </div>
        </div>
      </CollapseSection>

      {/* ─── 2. TYPOGRAPHY & GOOGLE FONTS (RULE 3) ─── */}
      <CollapseSection title="Site-Wide Typography & Google Fonts" defaultOpen>
        <div className="space-y-4">
          <p className="text-xs text-gray-500">
            Select high-performance Google Fonts or paste a custom Google Fonts embed URL to transform the typography of the entire website.
          </p>

          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
              <label className="block text-xs font-semibold text-gray-700 uppercase tracking-wide mb-1.5">
                Headings Display Font
              </label>
              <select
                value={theme.fontDisplay || 'Forum'}
                onChange={e => update(['theme', 'fontDisplay'], e.target.value)}
                className="w-full bg-white border border-gray-300 rounded-md px-3 py-2 text-xs font-medium text-gray-900 focus:outline-none focus:ring-1 focus:ring-gray-900"
              >
                {presetFonts.map(f => (
                  <option key={f.family} value={f.family}>{f.name}</option>
                ))}
              </select>
            </div>

            <div>
              <label className="block text-xs font-semibold text-gray-700 uppercase tracking-wide mb-1.5">
                Body & Paragraph Font
              </label>
              <select
                value={theme.fontBody || 'JetBrains Mono'}
                onChange={e => update(['theme', 'fontBody'], e.target.value)}
                className="w-full bg-white border border-gray-300 rounded-md px-3 py-2 text-xs font-medium text-gray-900 focus:outline-none focus:ring-1 focus:ring-gray-900"
              >
                {presetFonts.map(f => (
                  <option key={f.family} value={f.family}>{f.name}</option>
                ))}
              </select>
            </div>

            <div>
              <label className="block text-xs font-semibold text-gray-700 uppercase tracking-wide mb-1.5">
                Technical Monospace Font
              </label>
              <select
                value={theme.fontMono || 'JetBrains Mono'}
                onChange={e => update(['theme', 'fontMono'], e.target.value)}
                className="w-full bg-white border border-gray-300 rounded-md px-3 py-2 text-xs font-medium text-gray-900 focus:outline-none focus:ring-1 focus:ring-gray-900"
              >
                {presetFonts.map(f => (
                  <option key={f.family} value={f.family}>{f.name}</option>
                ))}
              </select>
            </div>
          </div>

          <TextInput
            label="Custom Google Fonts Embed URL"
            value={theme.customGoogleFontUrl || ''}
            onChange={v => update(['theme', 'customGoogleFontUrl'], v)}
            placeholder="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;700&display=swap"
            hint="Optional. Leaving this empty keeps the public site independent from third-party font networks."
          />
        </div>
      </CollapseSection>

      {/* ─── 3. SECTION-BY-SECTION CUSTOMIZER (RULES 1, 4, 6) ─── */}
      <CollapseSection title="Section-by-Section Color & Text Scaling" defaultOpen>
        <div className="space-y-4">
          <p className="text-xs text-gray-500">
            Customize individual sections with their own background color, text color, heading size scale, body size scale, and animation toggles.
          </p>

          {/* Section Picker Tabs */}
          <div className="flex gap-2 overflow-x-auto pb-2 border-b border-gray-200">
            {SECTIONS_LIST.map(sec => (
              <button
                key={sec.key}
                type="button"
                onClick={() => setSelectedSectionKey(sec.key)}
                className={`px-3 py-1.5 text-xs font-semibold rounded-md whitespace-nowrap transition-colors ${
                  selectedSectionKey === sec.key
                    ? 'bg-gray-900 text-white'
                    : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                }`}
              >
                {sec.name.split(' (')[0]}
              </button>
            ))}
          </div>

          <div className="bg-gray-50 border border-gray-200 rounded-lg p-4 space-y-4">
            <div className="flex items-center justify-between border-b border-gray-200 pb-2">
              <span className="text-xs font-bold text-gray-900 uppercase">
                Customizing: {SECTIONS_LIST.find(s => s.key === selectedSectionKey)?.name}
              </span>
              <button
                type="button"
                onClick={() => {
                  update(['sectionStyles', selectedSectionKey], {
                    bg: '', text: '', accent: '', headingScale: 1, bodyScale: 1, animationsEnabled: true, font: ''
                  });
                }}
                className="text-[11px] text-gray-500 hover:text-red-600 underline font-medium"
              >
                Reset Section to Defaults
              </button>
            </div>

            {/* Colors */}
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <TextInput
                label="Section Background Color"
                value={currentSectionStyle.bg || ''}
                onChange={v => updateSectionStyle('bg', v)}
                placeholder="Leave blank for site default"
                hint="e.g. #0a0f1d, #000000, #ffffff"
              />
              <TextInput
                label="Section Text Color"
                value={currentSectionStyle.text || ''}
                onChange={v => updateSectionStyle('text', v)}
                placeholder="Leave blank for site default"
                hint="e.g. #ffffff, #111111"
              />
              <TextInput
                label="Section Accent Color Override"
                value={currentSectionStyle.accent || ''}
                onChange={v => updateSectionStyle('accent', v)}
                placeholder="Leave blank for site default"
                hint="e.g. #fcbf13, #00f0ff"
              />
            </div>

            {/* Text Sizing & Font */}
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-2">
              <div>
                <label className="block text-xs font-semibold text-gray-700 uppercase tracking-wide mb-1">
                  Heading Size Scale ({((currentSectionStyle.headingScale ?? 1) * 100).toFixed(0)}%)
                </label>
                <input
                  type="range"
                  min="0.5"
                  max="1.8"
                  step="0.05"
                  value={currentSectionStyle.headingScale ?? 1}
                  onChange={e => updateSectionStyle('headingScale', parseFloat(e.target.value))}
                  className="w-full h-2 bg-gray-300 rounded-lg appearance-none cursor-pointer accent-gray-900"
                />
                <div className="flex justify-between text-[10px] text-gray-400 mt-1">
                  <span>50% (Compact)</span>
                  <span>100% (Normal)</span>
                  <span>180% (Massive)</span>
                </div>
              </div>

              <div>
                <label className="block text-xs font-semibold text-gray-700 uppercase tracking-wide mb-1">
                  Paragraph Size Scale ({((currentSectionStyle.bodyScale ?? 1) * 100).toFixed(0)}%)
                </label>
                <input
                  type="range"
                  min="0.5"
                  max="1.6"
                  step="0.05"
                  value={currentSectionStyle.bodyScale ?? 1}
                  onChange={e => updateSectionStyle('bodyScale', parseFloat(e.target.value))}
                  className="w-full h-2 bg-gray-300 rounded-lg appearance-none cursor-pointer accent-gray-900"
                />
                <div className="flex justify-between text-[10px] text-gray-400 mt-1">
                  <span>50% (Subtle)</span>
                  <span>100% (Normal)</span>
                  <span>160% (Large)</span>
                </div>
              </div>

              <div>
                <label className="block text-xs font-semibold text-gray-700 uppercase tracking-wide mb-1">
                  Section Font Override
                </label>
                <select
                  value={currentSectionStyle.font || ''}
                  onChange={e => updateSectionStyle('font', e.target.value)}
                  className="w-full bg-white border border-gray-300 rounded-md px-3 py-2 text-xs font-medium text-gray-900 focus:outline-none focus:ring-1 focus:ring-gray-900"
                >
                  <option value="">Use Site Global Font</option>
                  {presetFonts.map(f => (
                    <option key={f.family} value={f.family}>{f.name}</option>
                  ))}
                </select>
              </div>
            </div>

            {/* Animation Toggle */}
            <div className="pt-2 border-t border-gray-200">
              <ToggleSwitch
                label="Enable Animations for this Section"
                checked={currentSectionStyle.animationsEnabled ?? true}
                onChange={v => updateSectionStyle('animationsEnabled', v)}
                description="Controls GSAP scroll triggers, parallax reveals, and entrance transitions for this section."
              />
            </div>
          </div>
        </div>
      </CollapseSection>

      {/* ─── 4. LOGO & BRAND ASSETS ─── */}
      <CollapseSection title="Brand Logos & Assets">
        <div className="space-y-4">
          <p className="text-xs text-gray-500">
            Upload custom logo marks for navigation bar and metadata icons.
          </p>
          <ImageUpload
            label="Navbar Brand Logo (Transparent PNG recommended)"
            currentSrc={theme.logoUrl || ''}
            onUpload={url => update(['theme', 'logoUrl'], url)}
          />
        </div>
      </CollapseSection>
    </div>
  );
};
