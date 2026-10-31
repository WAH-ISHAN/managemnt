import React, { useRef, useState, useEffect } from 'react';
import { Upload, Loader, CheckCircle, AlertCircle, ChevronDown, Link, Image as ImageIcon, Video, Play, ExternalLink } from 'lucide-react';
import { API_BASE } from '../../context/ContentContext';
import { parseDriveUrl, isDriveUrl } from '../../utils/driveUtils';

export const inputClass =
  'w-full bg-white border border-gray-300 rounded-md px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:outline-none focus:ring-1 focus:ring-gray-400 focus:border-gray-400 transition-shadow';

export const FieldLabel: React.FC<{ label: string; hint?: string }> = ({ label, hint }) => (
  <div className="mb-1.5">
    <label className="block text-xs font-semibold text-gray-700 uppercase tracking-wide">{label}</label>
    {hint && <p className="text-[11px] text-gray-400 mt-0.5">{hint}</p>}
  </div>
);

export const SelectField: React.FC<{
  label: string; value: string; onChange: (v: string) => void;
  options: { value: string; label: string }[]; hint?: string;
}> = ({ label, value, onChange, options, hint }) => (
  <div>
    <FieldLabel label={label} hint={hint} />
    <select
      value={value}
      onChange={e => onChange(e.target.value)}
      className={`${inputClass} cursor-pointer`}
    >
      {options.map(o => (
        <option key={o.value} value={o.value}>{o.label}</option>
      ))}
    </select>
  </div>
);

export const TextInput: React.FC<{
  label: string; value: string; onChange: (v: string) => void;
  placeholder?: string; hint?: string; readOnly?: boolean; type?: string;
}> = ({ label, value, onChange, placeholder, hint, readOnly, type = 'text' }) => (
  <div>
    <FieldLabel label={label} hint={hint} />
    <input
      type={type}
      value={value ?? ''}
      onChange={e => onChange(e.target.value)}
      placeholder={placeholder}
      readOnly={readOnly}
      className={inputClass}
    />
  </div>
);

export const TextArea: React.FC<{
  label: string; value: string; onChange: (v: string) => void;
  rows?: number; placeholder?: string;
}> = ({ label, value, onChange, rows = 4, placeholder }) => (
  <div>
    <FieldLabel label={label} />
    <textarea
      value={value ?? ''}
      onChange={e => onChange(e.target.value)}
      rows={rows}
      placeholder={placeholder}
      className={`${inputClass} resize-y`}
    />
  </div>
);

export const ToggleSwitch: React.FC<{
  label: string; checked: boolean; onChange: (v: boolean) => void; description?: string;
}> = ({ label, checked, onChange, description }) => (
  <div className="flex items-start justify-between gap-4">
    <div>
      <p className="text-sm font-medium text-gray-800">{label}</p>
      {description && <p className="text-[11px] text-gray-500 mt-0.5">{description}</p>}
    </div>
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      onClick={() => onChange(!checked)}
      className={`relative inline-flex h-5 w-9 items-center rounded-full transition-colors flex-shrink-0 ${
        checked ? 'bg-gray-900' : 'bg-gray-300'
      }`}
    >
      <span
        className={`inline-block h-4 w-4 transform rounded-full bg-white shadow-sm transition-transform ${
          checked ? 'translate-x-4' : 'translate-x-0.5'
        }`}
      />
    </button>
  </div>
);

// ── Shared cloud import helper ──────────────────────────────────────────────
async function importFromCloudUrl(
  linkUrl: string,
  mimeHint: 'image' | 'video'
): Promise<{ url: string; type: string } | null> {
  const parsed = parseDriveUrl(linkUrl);
  const token = sessionStorage.getItem('cfx_admin_token') || '';

  if (parsed) {
    // Recognised Drive / OneDrive link
    try {
      const res = await fetch(`${API_BASE}/api/import-drive`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'x-admin-token': token },
        body: JSON.stringify({
          fileId: parsed.fileId,
          driveUrl: linkUrl,
          provider: parsed.provider,
          mimeHint,
        }),
      });
      const data = await res.json();
      return data.url ? data : { url: parsed.embedUrl, type: 'embed' };
    } catch {
      return { url: parsed.embedUrl, type: 'embed' };
    }
  } else {
    // Generic direct URL (e.g. a public .jpg / .mp4)
    try {
      const res = await fetch(`${API_BASE}/api/import-url`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'x-admin-token': token },
        body: JSON.stringify({ url: linkUrl, mimeHint }),
      });
      const data = await res.json();
      return data.url ? data : { url: linkUrl, type: 'direct' };
    } catch {
      return { url: linkUrl, type: 'direct' };
    }
  }
}

// ── ImageUpload — file upload + Drive/OneDrive link import ──────────────────
export const ImageUpload: React.FC<{
  label: string;
  currentSrc: string;
  onUpload: (url: string) => void;
}> = ({ label, currentSrc, onUpload }) => {
  const [uploading, setUploading] = useState(false);
  const [importing, setImporting] = useState(false);
  const [status, setStatus] = useState<'idle' | 'success' | 'error'>('idle');
  const [statusMsg, setStatusMsg] = useState('');
  const [linkInput, setLinkInput] = useState('');
  const [showLinkInput, setShowLinkInput] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);

  const handleFile = async (file: File) => {
    setUploading(true);
    setStatus('idle');
    const formData = new FormData();
    formData.append('file', file);
    try {
      const res = await fetch(`${API_BASE}/api/upload`, {
        method: 'POST',
        headers: { 'x-admin-token': sessionStorage.getItem('cfx_admin_token') || '' },
        body: formData,
      });
      const data = await res.json();
      if (data.url) {
        onUpload(data.url);
        setStatus('success');
        setStatusMsg('Image uploaded successfully ✓');
      } else {
        setStatus('error');
        setStatusMsg('Upload failed');
      }
    } catch {
      setStatus('error');
      setStatusMsg('Upload failed — check server is running');
    } finally {
      setUploading(false);
    }
  };

  const handleLinkImport = async (targetLink?: string) => {
    const rawLink = targetLink || linkInput;
    if (!rawLink.trim()) return;
    setImporting(true);
    setStatus('idle');
    try {
      const result = await importFromCloudUrl(rawLink.trim(), 'image');
      if (result?.url) {
        onUpload(result.url);
        setStatus('success');
        setStatusMsg(
          result.type === 'embed'
            ? 'Applied Google Drive / OneDrive stream ✓'
            : 'Image downloaded & saved to server ✓'
        );
        setLinkInput('');
        setShowLinkInput(false);
      } else {
        setStatus('error');
        setStatusMsg('Could not process image link. Make sure it is public.');
      }
    } catch {
      setStatus('error');
      setStatusMsg('Import failed');
    } finally {
      setImporting(false);
    }
  };

  return (
    <div>
      <FieldLabel label={label} />
      <div className="flex gap-3 items-start">
        {/* Preview */}
        {currentSrc ? (
          <img loading="lazy" decoding="async"
            src={currentSrc}
            alt="preview"
            className="w-16 h-16 object-cover rounded-md border border-gray-200 flex-shrink-0 bg-gray-50 shadow-sm"
            onError={e => (e.currentTarget.style.display = 'none')}
          />
        ) : (
          <div className="w-16 h-16 rounded-md border border-dashed border-gray-300 flex items-center justify-center flex-shrink-0 bg-gray-50">
            <ImageIcon className="w-5 h-5 text-gray-300" />
          </div>
        )}

        <div className="flex-1 space-y-2">
          <input
            type="file"
            ref={inputRef}
            accept="image/*"
            className="hidden"
            onChange={e => e.target.files?.[0] && handleFile(e.target.files[0])}
          />
          <div className="flex gap-2 flex-wrap">
            <button
              type="button"
              onClick={() => inputRef.current?.click()}
              disabled={uploading || importing}
              className="flex items-center gap-1.5 bg-white border border-gray-300 hover:border-gray-900 text-gray-700 hover:text-gray-900 text-xs font-medium px-3 py-2 rounded-md transition-colors disabled:opacity-40"
            >
              {uploading ? <Loader className="w-3.5 h-3.5 animate-spin" /> : <Upload className="w-3.5 h-3.5" />}
              {uploading ? 'Uploading…' : 'Upload image file'}
            </button>
            <button
              type="button"
              onClick={() => setShowLinkInput(!showLinkInput)}
              disabled={uploading || importing}
              className={`flex items-center gap-1.5 border text-xs font-medium px-3 py-2 rounded-md transition-colors disabled:opacity-40 ${
                showLinkInput
                  ? 'bg-gray-900 text-white border-gray-900'
                  : 'bg-white border-gray-300 hover:border-gray-900 text-gray-700'
              }`}
            >
              <Link className="w-3.5 h-3.5" />
              Drive / OneDrive Link
            </button>
          </div>

          {showLinkInput && (
            <div className="flex gap-2">
              <input
                type="url"
                value={linkInput}
                onChange={e => setLinkInput(e.target.value)}
                onKeyDown={e => e.key === 'Enter' && handleLinkImport()}
                placeholder="Paste Google Drive or OneDrive link and hit Import…"
                className="flex-1 bg-white border border-gray-300 rounded-md px-2.5 py-1.5 text-xs text-gray-900 placeholder:text-gray-400 focus:outline-none focus:border-gray-900"
                autoFocus
              />
              <button
                type="button"
                onClick={() => handleLinkImport()}
                disabled={!linkInput.trim() || importing}
                className="flex items-center gap-1 bg-gray-900 hover:bg-gray-700 text-white text-xs font-medium px-3.5 py-1.5 rounded-md transition-colors disabled:opacity-40"
              >
                {importing ? <Loader className="w-3 h-3 animate-spin" /> : 'Import'}
              </button>
            </div>
          )}

          {status === 'success' && (
            <p className="text-green-600 text-[11px] flex items-center gap-1 font-medium">
              <CheckCircle className="w-3.5 h-3.5" /> {statusMsg}
            </p>
          )}
          {status === 'error' && (
            <p className="text-red-600 text-[11px] flex items-center gap-1 font-medium">
              <AlertCircle className="w-3.5 h-3.5" /> {statusMsg}
            </p>
          )}
        </div>
      </div>
    </div>
  );
};

// ── VideoInput — video URL + Drive/OneDrive import + direct video file upload ─
export const VideoInput: React.FC<{
  label: string;
  value: string;
  onChange: (v: string) => void;
}> = ({ label, value, onChange }) => {
  const [driveInput, setDriveInput] = useState('');
  const [importing, setImporting] = useState(false);
  const [driveStatus, setDriveStatus] = useState<'idle' | 'success' | 'error'>('idle');
  const [driveMsg, setDriveMsg] = useState('');
  const fileRef = useRef<HTMLInputElement>(null);
    const [quality, setQuality] = useState('original');
  const [uploading, setUploading] = useState(false);

  const handleDirectChange = async (newVal: string) => {
    onChange(newVal);
    // If user pasted a Google Drive or OneDrive link directly into the text box, auto-process it
    if (isDriveUrl(newVal)) {
      setImporting(true);
      setDriveStatus('idle');
      try {
        const result = await importFromCloudUrl(newVal.trim(), 'video');
        if (result?.url) {
          onChange(result.url);
          setDriveStatus('success');
          setDriveMsg('Drive video stream imported & connected to page ✓');
        }
      } catch {
        // keep whatever was entered
      } finally {
        setImporting(false);
      }
    }
  };

  const importFromLink = async () => {
    if (!driveInput.trim()) return;
    setImporting(true);
    setDriveStatus('idle');
    try {
      const result = await importFromCloudUrl(driveInput.trim(), 'video');
      if (result?.url) {
        onChange(result.url);
        setDriveStatus('success');
        setDriveMsg(
          result.type === 'embed'
            ? 'Connected via Google Drive stream player ✓'
            : `Downloaded & saved to server: ${result.url}`
        );
        setDriveInput('');
      } else {
        setDriveStatus('error');
        setDriveMsg('Import failed — make sure the link is publicly accessible');
      }
    } catch {
      setDriveStatus('error');
      setDriveMsg('Import failed');
    } finally {
      setImporting(false);
    }
  };

  const handleVideoFile = async (file: File) => {
    setUploading(true);
    setDriveStatus('idle');
    const formData = new FormData();
    formData.append('file', file);
    try {
      const res = await fetch(`${API_BASE}/api/upload`, {
        method: 'POST',
        headers: { 'x-admin-token': sessionStorage.getItem('cfx_admin_token') || '' },
        body: formData,
      });
      const data = await res.json();
      if (data.url) {
        onChange(data.url);
        setDriveStatus('success');
        setDriveMsg(`Uploaded: ${data.url} ✓`);
      } else {
        setDriveStatus('error');
        setDriveMsg('Upload failed');
      }
    } catch {
      setDriveStatus('error');
      setDriveMsg('Upload failed — check server');
    } finally {
      setUploading(false);
    }
  };

  return (
    <div>
      <FieldLabel label={label} />
      
      {/* Direct text input */}
      <input
        type="text"
        value={value ?? ''}
        onChange={e => handleDirectChange(e.target.value)}
        placeholder="/uploads/video.mp4 or paste Google Drive / OneDrive link"
        className={`${inputClass} mb-2.5`}
      />

      {/* Cloud Import Box */}
      <div className="space-y-2 bg-gray-50 border border-gray-200 rounded-md p-3">
        <p className="text-[10px] text-gray-500 uppercase tracking-widest font-semibold flex items-center gap-1.5">
          <Link className="w-3 h-3 text-gray-500" />
          Import from Google Drive or OneDrive (Auto-connects to page)
        </p>
        
        <div className="flex gap-2">
          <input
            type="url"
            value={driveInput}
            onChange={e => setDriveInput(e.target.value)}
            onKeyDown={e => e.key === 'Enter' && importFromLink()}
            placeholder="Paste share link: https://drive.google.com/file/d/..."
            className="flex-1 bg-white border border-gray-300 rounded-md px-3 py-1.5 text-xs text-gray-900 placeholder:text-gray-400 focus:outline-none focus:border-gray-900"
          />
          <button
            type="button"
            onClick={importFromLink}
            disabled={!driveInput.trim() || importing}
            className="flex items-center gap-1.5 bg-gray-900 hover:bg-gray-700 text-white text-xs font-medium px-4 py-1.5 rounded-md transition-colors disabled:opacity-40 whitespace-nowrap"
          >
            {importing ? <Loader className="w-3.5 h-3.5 animate-spin" /> : <Link className="w-3.5 h-3.5" />}
            {importing ? 'Importing…' : 'Import to Page'}
          </button>
        </div>

        <div className="flex items-center gap-2 pt-1">
          <input
            type="file"
            ref={fileRef}
            accept="video/*"
            className="hidden"
            onChange={e => e.target.files?.[0] && handleVideoFile(e.target.files[0])}
          />
          <button
            type="button"
            onClick={() => fileRef.current?.click()}
            disabled={uploading || importing}
            className="flex items-center gap-1.5 bg-white border border-gray-300 hover:border-gray-900 text-gray-700 text-xs font-medium px-3 py-1.5 rounded-md transition-colors disabled:opacity-40"
          >
            {uploading ? <Loader className="w-3.5 h-3.5 animate-spin" /> : <Video className="w-3.5 h-3.5" />}
            {uploading ? 'Uploading…' : 'Or upload video file directly'}
          </button>
        </div>

        {driveStatus === 'success' && (
          <p className="text-green-600 text-[11px] flex items-center gap-1 font-medium mt-1">
            <CheckCircle className="w-3.5 h-3.5" /> {driveMsg}
          </p>
        )}
        {driveStatus === 'error' && (
          <p className="text-red-600 text-[11px] flex items-center gap-1 font-medium mt-1">
            <AlertCircle className="w-3.5 h-3.5" /> {driveMsg}
          </p>
        )}
      </div>
    </div>
  );
};

export const CollapseSection: React.FC<{
  title: string;
  children: React.ReactNode;
  defaultOpen?: boolean;
  badge?: string;
}> = ({ title, children, defaultOpen = false, badge }) => {
  const [open, setOpen] = useState(defaultOpen);
  return (
    <div className="border border-gray-200 rounded-lg overflow-hidden bg-white shadow-sm">
      <button
        type="button"
        onClick={() => setOpen(!open)}
        aria-expanded={open}
        className="w-full flex justify-between items-center px-4 sm:px-5 py-3.5 hover:bg-gray-50 transition-colors text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-gray-900"
      >
        <div className="flex items-center gap-3">
          <span className="text-base font-semibold text-gray-900">{title}</span>
          {badge && (
            <span className="text-[11px] px-2 py-0.5 bg-gray-100 text-gray-600 rounded-full border border-gray-200 font-medium">
              {badge}
            </span>
          )}
        </div>
        <ChevronDown className={`w-4 h-4 text-gray-400 transition-transform ${open ? 'rotate-180' : ''}`} />
      </button>
      {open && <div className="px-4 sm:px-5 py-4 space-y-4 border-t border-gray-100">{children}</div>}
    </div>
  );
};

export const PageHeader: React.FC<{
  title: string;
  description?: string;
  icon?: React.ElementType;
}> = ({ title, description, icon: Icon }) => (
  <div className="border-b border-gray-200 pb-4">
    <h3 className="text-xl font-semibold text-gray-900 flex items-center gap-2">
      {Icon && <Icon className="w-5 h-5 text-gray-400" />} {title}
    </h3>
    {description && <p className="text-sm text-gray-500 mt-1">{description}</p>}
  </div>
);

export const EmptyState: React.FC<{
  icon?: React.ElementType;
  title: string;
  description?: string;
}> = ({ icon: Icon, title, description }) => (
  <div className="border border-dashed border-gray-300 rounded-lg bg-white p-10 text-center">
    {Icon && <Icon className="w-8 h-8 text-gray-300 mx-auto" />}
    <p className="text-sm font-medium text-gray-700 mt-3">{title}</p>
    {description && <p className="text-xs text-gray-500 mt-1 max-w-sm mx-auto">{description}</p>}
  </div>
);

// ── GalleryMediaManager — multi-item gallery with order rearrangement ───────
// Accepts legacy string[] galleries or rich GalleryMedia objects and always
// emits the rich format: { url, kind, alt?, caption?, poster? }.
export const GalleryMediaManager: React.FC<{
  label: string;
  items: (string | { url: string; alt?: string; caption?: string; poster?: string })[];
  onChange: (items: any[]) => void;
  hint?: string;
}> = ({ label, items = [], onChange, hint }) => {
  const [cloudInput, setCloudInput] = useState('');
  const [importing, setImporting] = useState(false);
  const [statusMsg, setStatusMsg] = useState('');
  const [expandedIdx, setExpandedIdx] = useState<number | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  // Normalized working copy
  const norm = items.map(it => (typeof it === 'string' ? { url: it } : it));

  const detectKind = (url: string) => {
    const u = url.toLowerCase();
    if (u.includes('drive.google.com') && u.includes('/preview')) return 'embed';
    if (u.includes('1drv.ms') || u.includes('sharepoint.com')) return 'embed';
    if (/\.(mp4|webm|mov|m4v)(\?|$)/.test(u)) return 'video';
    return 'image';
  };

  const commit = (next: typeof norm) => {
    onChange(next.map(m => ({ ...m, kind: m.kind || detectKind(m.url) })));
  };

  const moveUp = (idx: number) => {
    if (idx <= 0) return;
    const next = [...norm];
    [next[idx - 1], next[idx]] = [next[idx], next[idx - 1]];
    commit(next);
  };

  const moveDown = (idx: number) => {
    if (idx >= norm.length - 1) return;
    const next = [...norm];
    [next[idx + 1], next[idx]] = [next[idx], next[idx + 1]];
    commit(next);
  };

  const removeItem = (idx: number) => {
    commit(norm.filter((_, i) => i !== idx));
  };

  const patchItem = (idx: number, patch: Partial<{ url: string; alt: string; caption: string; poster: string }>) => {
    commit(norm.map((m, i) => (i === idx ? { ...m, ...patch } : m)));
  };

  const handleAddCloudLink = async () => {
    if (!cloudInput.trim()) return;
    setImporting(true);
    setStatusMsg('');
    try {
      const raw = cloudInput.trim();
      const isVid = /\.(mp4|webm|mov)(\?|$)/i.test(raw) || raw.includes('/video/');
      const result = await importFromCloudUrl(raw, isVid ? 'video' : 'image');
      const url = result?.url || raw;
      commit([...norm, { url, kind: isVid ? 'video' : 'image' } as any]);
      setCloudInput('');
      setStatusMsg(result?.type === 'embed' ? 'Stream link added to gallery ✓' : 'Media added to gallery ✓');
    } catch {
      commit([...norm, { url: cloudInput.trim() } as any]);
      setCloudInput('');
    } finally {
      setImporting(false);
    }
  };

  const handleFileUpload = async (file: File) => {
    setImporting(true);
    setStatusMsg('');
    const formData = new FormData();
    formData.append('file', file);
    try {
      const res = await fetch(`${API_BASE}/api/upload`, {
        method: 'POST',
        headers: { 'x-admin-token': sessionStorage.getItem('cfx_admin_token') || '' },
        body: formData,
      });
      const data = await res.json();
      if (data.url) {
        const isVid = (data.type || file.type || '').startsWith('video');
        commit([...norm, { url: data.url, kind: isVid ? 'video' : 'image', poster: '' } as any]);
        setStatusMsg('File uploaded and added to gallery ✓');
      } else {
        setStatusMsg(data.error || 'Upload failed');
      }
    } catch {
      setStatusMsg('Upload failed — check server is running');
    } finally {
      setImporting(false);
    }
  };

  return (
    <div className="space-y-3 pt-2">
      <div className="flex items-center justify-between">
        <div>
          <FieldLabel label={label} hint={hint || `${items.length} items (Arrange order using ▲ ▼ buttons)`} />
        </div>
        <span className="text-xs font-mono font-bold text-gray-500 bg-gray-100 px-2 py-0.5 rounded-full border border-gray-200">
          {items.length} Media Items
        </span>
      </div>

      {/* Add new media toolbar */}
      <div className="p-3 bg-gray-50 border border-gray-200 rounded-md space-y-2">
        <p className="text-[11px] font-semibold text-gray-700 uppercase tracking-wide">
          + Add Image / Video to this Product
        </p>

        <div className="flex gap-2">
          <input
            type="url"
            value={cloudInput}
            onChange={e => setCloudInput(e.target.value)}
            onKeyDown={e => e.key === 'Enter' && handleAddCloudLink()}
            placeholder="Paste Google Drive link, OneDrive link, or image/video URL…"
            className="flex-1 bg-white border border-gray-300 rounded-md px-3 py-1.5 text-xs text-gray-900 placeholder:text-gray-400 focus:outline-none focus:border-gray-900"
          />
          <button
            type="button"
            onClick={handleAddCloudLink}
            disabled={!cloudInput.trim() || importing}
            className="flex items-center gap-1.5 bg-gray-900 hover:bg-gray-700 text-white text-xs font-medium px-4 py-1.5 rounded-md transition-colors disabled:opacity-40 whitespace-nowrap cursor-pointer"
          >
            {importing ? <Loader className="w-3.5 h-3.5 animate-spin" /> : <Link className="w-3.5 h-3.5" />}
            <span>Add Link</span>
          </button>
        </div>

        <div className="flex items-center gap-2 pt-1">
          <input
            type="file"
            ref={fileInputRef}
            accept="image/*,video/*"
            className="hidden"
            onChange={e => e.target.files?.[0] && handleFileUpload(e.target.files[0])}
          />
          <button
            type="button"
            onClick={() => fileInputRef.current?.click()}
            disabled={importing}
            className="flex items-center gap-1.5 bg-white border border-gray-300 hover:border-gray-900 text-gray-700 text-xs font-medium px-3 py-1.5 rounded-md transition-colors disabled:opacity-40 cursor-pointer"
          >
            {importing ? <Loader className="w-3.5 h-3.5 animate-spin" /> : <Upload className="w-3.5 h-3.5" />}
            <span>Or upload image / video file from computer</span>
          </button>
        </div>

        {statusMsg && (
          <p className="text-green-600 text-[11px] font-medium flex items-center gap-1">
            <CheckCircle className="w-3.5 h-3.5" /> {statusMsg}
          </p>
        )}
      </div>

      {/* Ordered Media List with Move Up / Move Down controls */}
      {norm.length === 0 ? (
        <div className="p-5 border border-dashed border-gray-200 rounded-md text-center text-xs text-gray-400 bg-white">
          No gallery media yet. Add images/videos above — visitors see them in project order.
        </div>
      ) : (
        <div className="space-y-2 max-h-[32rem] overflow-y-auto pr-1">
          {norm.map((media, idx) => {
            const kind = media.kind || detectKind(media.url);
            const isVid = kind === 'video' || kind === 'embed';
            const expanded = expandedIdx === idx;
            return (
              <div
                key={idx}
                className="bg-white border border-gray-200 rounded-md hover:border-gray-300 transition-colors shadow-xs"
              >
                <div className="flex items-center justify-between gap-3 p-2.5">
                  {/* Drag / Index handle */}
                  <div className="flex items-center gap-2 min-w-0">
                    <span className="text-xs font-mono font-bold text-gray-400 w-5 flex-shrink-0">
                      {String(idx + 1).padStart(2, '0')}
                    </span>

                    {/* Thumbnail */}
                    <div className="w-12 h-12 bg-gray-100 border border-gray-200 rounded-sm overflow-hidden flex-shrink-0 flex items-center justify-center">
                      {isVid ? (
                        <div className="w-full h-full bg-gray-900 flex items-center justify-center text-yellow-400">
                          <Video className="w-5 h-5" />
                        </div>
                      ) : (
                        <img loading="lazy" decoding="async"
                          src={media.url}
                          alt={media.alt || `Media ${idx + 1}`}
                          className="w-full h-full object-cover"
                          onError={e => {
                            (e.currentTarget as HTMLElement).style.opacity = '0.15';
                          }}
                        />
                      )}
                    </div>

                    {/* Info */}
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-2">
                        <span className={`text-[10px] font-mono uppercase px-1.5 py-0.5 rounded-sm font-bold ${
                          isVid ? 'bg-amber-100 text-amber-800' : 'bg-blue-100 text-blue-800'
                        }`}>
                          {kind === 'embed' ? 'EMBED' : isVid ? 'VIDEO' : 'IMAGE'}
                        </span>
                        <span className="text-xs font-medium text-gray-700 truncate block max-w-[180px] sm:max-w-md">
                          {media.url}
                        </span>
                      </div>
                    </div>
                  </div>

                  {/* Action Controls: Expand, Move Up, Move Down, Delete */}
                  <div className="flex items-center gap-1 flex-shrink-0">
                    <button
                      type="button"
                      onClick={() => setExpandedIdx(expanded ? null : idx)}
                      title="Edit alt / caption / poster"
                      className="px-2 py-1.5 text-gray-600 hover:text-gray-900 hover:bg-gray-100 rounded border border-gray-200 transition-colors cursor-pointer"
                    >
                      <span className="font-bold text-xs">✎</span>
                    </button>
                    <button
                      type="button"
                      onClick={() => moveUp(idx)}
                      disabled={idx === 0}
                      title="Move Up in order"
                      className="p-1.5 text-gray-600 hover:text-gray-900 hover:bg-gray-100 rounded border border-gray-200 disabled:opacity-25 disabled:cursor-not-allowed transition-colors cursor-pointer"
                    >
                      <span className="font-bold text-xs">▲</span>
                    </button>
                    <button
                      type="button"
                      onClick={() => moveDown(idx)}
                      disabled={idx === norm.length - 1}
                      title="Move Down in order"
                      className="p-1.5 text-gray-600 hover:text-gray-900 hover:bg-gray-100 rounded border border-gray-200 disabled:opacity-25 disabled:cursor-not-allowed transition-colors cursor-pointer"
                    >
                      <span className="font-bold text-xs">▼</span>
                    </button>
                    <button
                      type="button"
                      onClick={() => removeItem(idx)}
                      title="Remove from gallery"
                      className="p-1.5 text-red-500 hover:text-red-700 hover:bg-red-50 rounded border border-red-200 transition-colors ml-1 cursor-pointer"
                    >
                      <span className="text-xs font-bold">✕</span>
                    </button>
                  </div>
                </div>

                {expanded && (
                  <div className="border-t border-gray-100 px-3 py-3 grid grid-cols-1 sm:grid-cols-3 gap-3 bg-gray-50/60">
                    <TextInput label="Alt text (accessibility / SEO)" value={media.alt || ''} onChange={v => patchItem(idx, { alt: v })} />
                    <TextInput label="Caption" value={media.caption || ''} onChange={v => patchItem(idx, { caption: v })} />
                    {kind !== 'image' && (
                      <TextInput label="Poster image URL (video thumbnail)" value={media.poster || ''} onChange={v => patchItem(idx, { poster: v })} />
                    )}
                  </div>
                )}
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
};

