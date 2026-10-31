import React, { useEffect, useState } from 'react';
import { Image as ImageIcon, Video, Copy, Check, Upload, Link, Loader, Trash2, File as FileIcon } from 'lucide-react';
import { FieldLabel, PageHeader, EmptyState } from './AdminFields';
import { API_BASE, type SiteContent } from '../../context/ContentContext';
import { parseDriveUrl } from '../../utils/driveUtils';

interface MediaLibrarySectionProps {
  item: string;
  content: SiteContent;
  update: (path: string[], value: any) => void;
}

interface UploadRecord {
  name: string;
  url: string;
  size: number;
  uploadedAt: string;
}

export const MediaLibrarySection: React.FC<MediaLibrarySectionProps> = ({ item, content, update }) => {
  const [copiedUrl, setCopiedUrl] = useState<string | null>(null);
  const [uploading, setUploading] = useState(false);
  const [uploadProgress, setUploadProgress] = useState(0);
  const [uploadedUrl, setUploadedUrl] = useState<string | null>(null);
  const [errorMessage, setErrorMessage] = useState('');

  // Drive state
  const [driveUrl, setDriveUrl] = useState('');
  const [driveImporting, setDriveImporting] = useState(false);
  const [driveResult, setDriveResult] = useState<string | null>(null);

  // Uploaded files library
  const [files, setFiles] = useState<UploadRecord[]>([]);
  const [filesLoading, setFilesLoading] = useState(true);

  const copyToClipboard = async (url: string) => {
    try {
      await navigator.clipboard.writeText(url);
    } catch {
      const field = document.createElement('textarea');
      field.value = url;
      field.style.position = 'fixed';
      field.style.opacity = '0';
      document.body.appendChild(field);
      field.select();
      document.execCommand('copy');
      field.remove();
    }
    setCopiedUrl(url);
    window.setTimeout(() => setCopiedUrl(null), 2000);
  };

  const mediaUrl = (media: unknown) => {
    if (typeof media === 'string') return media;
    if (media && typeof media === 'object' && 'url' in media) return String((media as { url?: string }).url || '');
    return '';
  };

  const isVideoUrl = (url: string) => /\.(mp4|webm|mov|m4v)(\?|$)/i.test(url) || /drive\.google|1drv\.ms|sharepoint/i.test(url);

  // Collect all images from projects
  const allImages = React.useMemo(() => {
    const list: { url: string; title: string; category: string }[] = [];
    const projects = content.projects || [];
    projects.forEach(p => {
      if (p.coverImage) list.push({ url: p.coverImage, title: `${p.title} (Cover)`, category: p.category || 'Portfolio' });
      (p.gallery || []).forEach((g, gi) => {
        const url = mediaUrl(g);
        if (url && !isVideoUrl(url)) {
          list.push({ url, title: `${p.title} - Frame ${gi + 1}`, category: p.category || 'Gallery' });
        }
      });
    });
    return list;
  }, [content]);

  // Collect all videos
  const allVideos = React.useMemo(() => {
    const list: { url: string; title: string; category: string }[] = [];
    const projects = content.projects || [];
    projects.forEach(p => {
      if (p.videoUrl) list.push({ url: p.videoUrl, title: `${p.title} (Main video)`, category: p.category || 'Video' });
      (p.gallery || []).forEach((g, gi) => {
        const url = mediaUrl(g);
        if (url && isVideoUrl(url)) {
          list.push({ url, title: `${p.title} - Clip ${gi + 1}`, category: p.category || 'Gallery' });
        }
      });
    });
    return list;
  }, [content]);

  const loadFiles = () => {
    setFilesLoading(true);
    setErrorMessage('');
    fetch(`${API_BASE}/api/uploads?fresh=${Date.now()}`, {
      cache: 'no-store',
      headers: { 'x-admin-token': sessionStorage.getItem('cfx_admin_token') || '', 'Cache-Control': 'no-cache' },
    })
      .then(async r => {
        if (!r.ok) throw new Error(r.status === 401 ? 'Your admin session expired. Sign in again.' : 'Could not load uploaded files.');
        return r.json();
      })
      .then((list: UploadRecord[]) => setFiles(Array.isArray(list) ? list : []))
      .catch(error => { setFiles([]); setErrorMessage(error.message || 'Could not load uploaded files.'); })
      .finally(() => setFilesLoading(false));
  };

  useEffect(() => {
    if (item === 'Uploaded Files') loadFiles();
  }, [item]);

  const handleDeleteFile = async (name: string) => {
    if (!confirm(`Delete "${name}"? Projects referencing this file will lose it.`)) return;
    setFiles(prev => prev.filter(f => f.name !== name));
    try {
      await fetch(`${API_BASE}/api/uploads/${encodeURIComponent(name)}`, {
        method: 'DELETE',
        headers: { 'x-admin-token': sessionStorage.getItem('cfx_admin_token') || '' },
      });
    } catch {
      // Optimistic delete — refresh reconciles
    }
  };

  const handleFileUpload = async (file: File) => {
    setUploading(true);
    setErrorMessage('');
    setUploadedUrl(null);
    setUploadProgress(20);
    const formData = new FormData();
    formData.append('file', file);
    try {
      setUploadProgress(60);
      const res = await fetch(`${API_BASE}/api/upload`, {
        method: 'POST',
        headers: { 'x-admin-token': sessionStorage.getItem('cfx_admin_token') || '' },
        body: formData,
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.error || `Upload failed (${res.status})`);
      setUploadProgress(100);
      if (data.url) {
        setUploadedUrl(data.url);
        loadFiles();
      }
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : 'Upload failed. Please verify the backend server is running.');
    } finally {
      setUploading(false);
    }
  };

  const handleDriveDownload = async () => {
    const trimmed = driveUrl.trim();
    if (!trimmed) return alert('Please enter a Google Drive or OneDrive share link');
    setDriveImporting(true);
    setDriveResult(null);
    try {
      const parsed = parseDriveUrl(trimmed);
      const token = sessionStorage.getItem('cfx_admin_token') || '';
      let data: any;
      if (parsed) {
        const res = await fetch(`${API_BASE}/api/import-drive`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'x-admin-token': token },
          body: JSON.stringify({ fileId: parsed.fileId, driveUrl: trimmed, provider: parsed.provider }),
        });
        data = await res.json();
      } else {
        // Try as generic direct URL
        const res = await fetch(`${API_BASE}/api/import-url`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'x-admin-token': token },
          body: JSON.stringify({ url: trimmed }),
        });
        data = await res.json();
      }
      if (data?.url) {
        setDriveResult(data.url);
        loadFiles(); // refresh the uploaded files list
      } else if (parsed) {
        setDriveResult(parsed.embedUrl);
      } else {
        alert('Could not download. Make sure the file is set to "Anyone with the link can view".');
      }
    } catch {
      alert('Import failed. Make sure the server is running on port 4000.');
    } finally {
      setDriveImporting(false);
    }
  };

  const formatBytes = (bytes: number) => {
    if (!bytes) return '—';
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  };

  // ── 1. UPLOAD / REPLACE ──
  if (item === 'Upload / Replace') {
    return (
      <div className="space-y-6">
        <PageHeader
          icon={Upload}
          title="Upload media"
          description="Upload images or videos — or import from Google Drive / OneDrive. Files are downloaded and hosted on your VPS server."
        />

        {/* Drag and Drop Zone */}
        <div className="border-2 border-dashed border-gray-300 hover:border-gray-900 bg-white p-8 rounded-lg text-center transition-colors">
          <div className="max-w-md mx-auto space-y-4">
            <div className="w-12 h-12 bg-gray-100 rounded-full flex items-center justify-center mx-auto">
              <ImageIcon className="w-5 h-5 text-gray-500" />
            </div>
            <div>
              <h4 className="text-sm font-semibold text-gray-900">Upload image or video</h4>
              <p className="text-xs text-gray-500 mt-1">JPG, PNG, WEBP, GIF, AVIF, MP4, WEBM or MOV</p>
            </div>

            <input
              type="file"
              id="media-uploader-input"
              accept="image/*,video/*"
              className="hidden"
              onChange={e => e.target.files?.[0] && handleFileUpload(e.target.files[0])}
            />

            <label
              htmlFor="media-uploader-input"
              className="inline-flex items-center gap-2 bg-gray-900 text-white text-xs font-semibold px-6 py-2.5 rounded-md hover:bg-gray-700 transition-colors cursor-pointer"
            >
              {uploading ? <Loader className="w-4 h-4 animate-spin" /> : <Upload className="w-4 h-4" />}
              {uploading ? `Uploading (${uploadProgress}%)…` : 'Select file from computer'}
            </label>

            {uploadedUrl && (
              <div className="p-4 bg-green-50 border border-green-200 rounded-md text-left mt-4 space-y-2">
                <div className="flex items-center justify-between">
                  <span className="text-xs font-medium text-green-700">Upload complete</span>
                  <button
                    onClick={() => copyToClipboard(uploadedUrl)}
                    className="text-[11px] text-gray-600 bg-white border border-gray-300 hover:border-gray-900 px-2 py-1 rounded flex items-center gap-1 transition-colors"
                  >
                    {copiedUrl === uploadedUrl ? <Check className="w-3 h-3 text-green-600" /> : <Copy className="w-3 h-3" />}
                    {copiedUrl === uploadedUrl ? 'Copied' : 'Copy URL'}
                  </button>
                </div>
                <input
                  type="text"
                  readOnly
                  value={uploadedUrl}
                  className="w-full bg-white border border-gray-200 rounded-md px-2.5 py-1.5 text-xs text-gray-700"
                />
              </div>
            )}
            {errorMessage && (
              <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-left text-xs font-medium text-red-700">{errorMessage}</p>
            )}
          </div>
        </div>

        {/* Google Drive / OneDrive / Direct URL Importer */}
        <div className="border border-gray-200 bg-white p-6 rounded-lg space-y-4">
          <div className="flex items-center gap-2">
            <Video className="w-4 h-4 text-gray-400" />
            <h4 className="text-sm font-semibold text-gray-900">Cloud import — Google Drive / OneDrive</h4>
          </div>
          <p className="text-sm text-gray-500">
            Paste a public Google Drive or OneDrive share link. The server will download and host the file on your VPS.
            Works for both <strong>images</strong> and <strong>videos</strong>.
          </p>

          <div className="flex gap-2">
            <input
              type="text"
              value={driveUrl}
              onChange={e => setDriveUrl(e.target.value)}
              placeholder="https://drive.google.com/… or https://1drv.ms/…"
              className="flex-1 bg-white border border-gray-300 rounded-md px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:outline-none focus:border-gray-900 transition-shadow"
            />
            <button
              onClick={handleDriveDownload}
              disabled={!driveUrl || driveImporting}
              className="flex items-center gap-1.5 bg-gray-900 hover:bg-gray-700 text-white text-xs font-semibold px-4 py-2 rounded-md transition-colors disabled:opacity-40"
            >
              {driveImporting ? <Loader className="w-3.5 h-3.5 animate-spin" /> : <Link className="w-3.5 h-3.5" />}
              {driveImporting ? 'Downloading…' : 'Import'}
            </button>
          </div>

          {driveResult && (
            <div className="p-3 bg-blue-50 border border-blue-200 rounded-md flex items-center justify-between gap-3 text-xs">
              <span className="text-blue-800 truncate">{driveResult}</span>
              <button
                onClick={() => copyToClipboard(driveResult)}
                className="text-[11px] text-gray-600 bg-white border border-gray-300 hover:border-gray-900 px-2 py-1 rounded flex items-center gap-1 flex-shrink-0 transition-colors"
              >
                {copiedUrl === driveResult ? <Check className="w-3 h-3 text-green-600" /> : <Copy className="w-3 h-3" />}
                {copiedUrl === driveResult ? 'Copied' : 'Copy'}
              </button>
            </div>
          )}
        </div>
      </div>
    );
  }

  // ── 2. UPLOADED FILES ──
  if (item === 'Uploaded Files') {
    return (
      <div className="space-y-6">
        <PageHeader
          icon={FileIcon}
          title="Uploaded files"
          description={`${files.length} files in your server uploads folder (/uploads)`}
        />

        {filesLoading ? (
          <div className="py-12 flex items-center justify-center gap-2 text-sm text-gray-400">
            <Loader className="w-4 h-4 animate-spin" /> Loading files…
          </div>
        ) : errorMessage ? (
          <div role="alert" className="rounded-lg border border-red-200 bg-red-50 p-5 text-sm text-red-700">
            <p className="font-semibold">Media library could not be loaded</p>
            <p className="mt-1 text-xs">{errorMessage}</p>
            <button type="button" onClick={loadFiles} className="mt-3 rounded-md border border-red-300 bg-white px-3 py-2 text-xs font-semibold hover:bg-red-100">Try again</button>
          </div>
        ) : files.length === 0 ? (
          <EmptyState
            icon={FileIcon}
            title="No uploaded files yet"
            description="Files you upload through the Upload media page are stored here."
          />
        ) : (
          <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
            {files.map(f => {
              const isImage = /\.(jpe?g|png|webp|gif|avif)$/i.test(f.name);
              return (
                <div key={f.name} className="group bg-white border border-gray-200 rounded-lg overflow-hidden flex flex-col">
                  <div className="aspect-[4/3] bg-gray-50 relative overflow-hidden flex items-center justify-center">
                    {isImage ? (
                      <img loading="lazy" decoding="async" src={f.url} alt={f.name} className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" />
                    ) : (
                      <FileIcon className="w-8 h-8 text-gray-300" />
                    )}
                    <button
                      onClick={() => handleDeleteFile(f.name)}
                      title="Delete file"
                      className="absolute top-2 right-2 p-1.5 bg-white/90 border border-gray-200 rounded-md text-gray-400 hover:text-red-600 opacity-0 group-hover:opacity-100 transition-opacity"
                    >
                      <Trash2 className="w-3.5 h-3.5" />
                    </button>
                  </div>
                  <div className="p-3 flex-1 flex flex-col justify-between">
                    <h4 className="text-xs font-medium text-gray-900 truncate" title={f.name}>{f.name}</h4>
                    <div className="mt-2 flex items-center justify-between pt-2 border-t border-gray-100">
                      <span className="text-[11px] text-gray-400">{formatBytes(f.size)}</span>
                      <button
                        onClick={() => copyToClipboard(f.url)}
                        className="p-1.5 text-gray-400 hover:text-gray-900 rounded transition-colors"
                        title="Copy URL"
                      >
                        {copiedUrl === f.url ? <Check className="w-3.5 h-3.5 text-green-600" /> : <Copy className="w-3.5 h-3.5" />}
                      </button>
                    </div>
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </div>
    );
  }

  // ── 3. IMAGES ──
  if (item === 'Images') {
    return (
      <div className="space-y-6">
        <PageHeader
          icon={ImageIcon}
          title="Project images"
          description={`${allImages.length} image assets mapped to projects. Click copy to grab a URL.`}
        />

        {allImages.length === 0 ? (
          <EmptyState icon={ImageIcon} title="No images yet" description="Add cover images and gallery photos to your projects and they appear here." />
        ) : (
          <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
            {allImages.map((img, i) => (
              <div key={i} className="group bg-white border border-gray-200 rounded-lg overflow-hidden flex flex-col">
                <div className="aspect-[4/3] bg-gray-50 relative overflow-hidden">
                  <img loading="lazy" decoding="async"
                    src={img.url}
                    alt={img.title}
                    className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
                    onError={e => (e.currentTarget.style.display = 'none')}
                  />
                </div>
                <div className="p-3 flex-1 flex flex-col justify-between">
                  <div>
                    <h4 className="text-xs font-medium text-gray-900 truncate" title={img.title}>{img.title}</h4>
                    <span className="text-[11px] text-gray-400 capitalize">{img.category}</span>
                  </div>
                  <div className="mt-3 flex items-center justify-between pt-2 border-t border-gray-100">
                    <span className="text-[10px] text-gray-400 truncate max-w-[110px]" title={img.url}>{img.url}</span>
                    <button
                      onClick={() => copyToClipboard(img.url)}
                      className="p-1.5 text-gray-400 hover:text-gray-900 rounded transition-colors"
                      title="Copy image path"
                    >
                      {copiedUrl === img.url ? <Check className="w-3.5 h-3.5 text-green-600" /> : <Copy className="w-3.5 h-3.5" />}
                    </button>
                  </div>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    );
  }

  // ── 4. VIDEOS ──
  if (item === 'Videos') {
    return (
      <div className="space-y-6">
        <PageHeader
          icon={Video}
          title="Video assets"
          description={`${allVideos.length} video links used across your projects`}
        />

        {allVideos.length === 0 ? (
          <EmptyState icon={Video} title="No videos yet" description="Add main videos or gallery clips to your projects and they appear here." />
        ) : (
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            {allVideos.map((vid, i) => (
              <div key={i} className="p-5 bg-white border border-gray-200 rounded-lg space-y-3">
                <div className="flex items-center justify-between gap-3">
                  <div className="flex items-center gap-2 min-w-0">
                    <Video className="w-4 h-4 text-gray-400 flex-shrink-0" />
                    <h4 className="text-xs font-medium text-gray-900 truncate">{vid.title}</h4>
                  </div>
                  <span className="text-[10px] px-2 py-0.5 bg-gray-100 text-gray-500 border border-gray-200 rounded-full capitalize flex-shrink-0">{vid.category}</span>
                </div>

                <div className="bg-gray-50 p-2.5 rounded-md border border-gray-100 text-[11px] text-gray-600 break-all">
                  {vid.url}
                </div>

                <div className="flex justify-end pt-1">
                  <button
                    onClick={() => copyToClipboard(vid.url)}
                    className="flex items-center gap-1.5 px-3 py-1.5 bg-white hover:bg-gray-50 text-gray-700 text-xs font-medium rounded-md transition-colors border border-gray-300"
                  >
                    {copiedUrl === vid.url ? <Check className="w-3.5 h-3.5 text-green-600" /> : <Copy className="w-3.5 h-3.5" />}
                    {copiedUrl === vid.url ? 'Copied' : 'Copy URL'}
                  </button>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    );
  }

  return null;
};
