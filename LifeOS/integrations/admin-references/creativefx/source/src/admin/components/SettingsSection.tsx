import React, { useRef } from 'react';
import { Settings as SettingsIcon, Download, Upload, RotateCcw, ShieldAlert, Code2, Sparkles, Sliders } from 'lucide-react';
import { TextInput, TextArea, CollapseSection, ToggleSwitch, PageHeader } from './AdminFields';
import type { SiteContent } from '../../context/ContentContext';

interface SettingsSectionProps {
  content: SiteContent;
  update: (path: string[], value: any) => void;
  resetContent: () => Promise<void>;
}

export const SettingsSection: React.FC<SettingsSectionProps> = ({ content, update, resetContent }) => {
  const fileInputRef = useRef<HTMLInputElement>(null);
  const identity = { ...(content.settings?.identity || {}) };

  const handleExportBackup = () => {
    const dataStr = 'data:text/json;charset=utf-8,' + encodeURIComponent(JSON.stringify(content, null, 2));
    const downloadAnchor = document.createElement('a');
    downloadAnchor.setAttribute('href', dataStr);
    downloadAnchor.setAttribute('download', `creativefx-content-backup-${new Date().toISOString().slice(0, 10)}.json`);
    document.body.appendChild(downloadAnchor);
    downloadAnchor.click();
    downloadAnchor.remove();
  };

  const handleImportBackup = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = (event) => {
      try {
        const parsed = JSON.parse(event.target?.result as string);
        update([], parsed);
        alert('Backup imported and synced to the server successfully.');
      } catch {
        alert('Invalid JSON backup file.');
      }
    };
    reader.readAsText(file);
  };

  const handleFactoryReset = async () => {
    if (!confirm('This will reset ALL studio content, projects and media links back to the original defaults. This cannot be reversed. Continue?')) return;
    await resetContent();
    window.location.reload();
  };

  return (
    <div className="space-y-6">
      <PageHeader
        icon={SettingsIcon}
        title="Settings & Code Customizer"
        description="Custom CSS animations injector, studio identity, backups, and performance settings."
      />

      {/* ─── 1. CUSTOM CSS & ANIMATION CODE INJECTOR (RULE 4) ─── */}
      <CollapseSection title="Custom CSS & Animation Code Injector" defaultOpen>
        <div className="space-y-4">
          <p className="text-xs text-gray-500">
            Inject custom CSS rules, @keyframes animations, hover transitions, and bespoke styling directly into the live website.
          </p>

          <TextArea
            label="Custom CSS Code"
            rows={8}
            value={content.customCss || ''}
            onChange={v => update(['customCss'], v)}
            placeholder={`/* Example custom CSS / keyframe animation */\n@keyframes floatSlow {\n  0%, 100% { transform: translateY(0); }\n  50% { transform: translateY(-8px); }\n}\n\n.my-custom-badge {\n  animation: floatSlow 4s ease-in-out infinite;\n}`}
          />
        </div>
      </CollapseSection>

      {/* ─── 2. STUDIO IDENTITY ─── */}
      <CollapseSection title="Studio Identity" defaultOpen>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <TextInput label="Brand Name" value={identity.brandName || ''} onChange={v => update(['settings', 'identity', 'brandName'], v)} />
          <TextInput label="Operating Timezone" value={identity.timezone || ''} onChange={v => update(['settings', 'identity', 'timezone'], v)} />
          <TextInput label="Primary Currency" value={identity.currency || ''} onChange={v => update(['settings', 'identity', 'currency'], v)} />
          <TextInput label="System Language" value={identity.language || ''} onChange={v => update(['settings', 'identity', 'language'], v)} />
        </div>
      </CollapseSection>

      {/* ─── 3. DATABASE BACKUP & RESTORE ─── */}
      <CollapseSection title="Database Backup & Export" defaultOpen>
        <div className="space-y-4">
          <p className="text-xs text-gray-500">
            Download a complete JSON snapshot of all site content, media references, projects and styles, or import a saved backup.
          </p>

          <div className="flex flex-wrap gap-3">
            <button
              type="button"
              onClick={handleExportBackup}
              className="flex items-center gap-2 bg-gray-900 text-white text-xs font-semibold px-5 py-2.5 rounded-md hover:bg-gray-700 transition-colors cursor-pointer"
            >
              <Download className="w-4 h-4" /> Export JSON Backup
            </button>

            <input
              type="file"
              ref={fileInputRef}
              accept=".json"
              className="hidden"
              onChange={handleImportBackup}
            />

            <button
              type="button"
              onClick={() => fileInputRef.current?.click()}
              className="flex items-center gap-2 bg-white border border-gray-300 text-gray-700 hover:border-gray-900 hover:text-gray-900 text-xs font-medium px-5 py-2.5 rounded-md transition-colors cursor-pointer"
            >
              <Upload className="w-4 h-4" /> Import Backup
            </button>
          </div>
        </div>
      </CollapseSection>

      {/* ─── 4. DANGER ZONE ─── */}
      <CollapseSection title="Reset to Factory Defaults">
        <div className="space-y-4">
          <div className="p-4 bg-red-50 border border-red-200 rounded-lg flex items-start gap-3">
            <ShieldAlert className="w-5 h-5 text-red-600 flex-shrink-0 mt-0.5" />
            <div>
              <p className="text-xs font-bold text-red-900">Reset Content & Database</p>
              <p className="text-xs text-red-700 mt-1">
                Restores all project records, text copy, styles, and settings back to original defaults.
              </p>
              <button
                type="button"
                onClick={handleFactoryReset}
                className="mt-3 flex items-center gap-2 bg-red-600 hover:bg-red-700 text-white text-xs font-bold px-4 py-2 rounded-md transition-colors cursor-pointer"
              >
                <RotateCcw className="w-3.5 h-3.5" /> Reset Everything to Defaults
              </button>
            </div>
          </div>
        </div>
      </CollapseSection>
    </div>
  );
};
