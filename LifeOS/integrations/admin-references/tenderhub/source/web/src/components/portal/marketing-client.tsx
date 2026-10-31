'use client';

import React, { useState, useEffect, useCallback } from 'react';
import Image from 'next/image';
import { Building2, CalendarClock, ExternalLink, Lock } from 'lucide-react';
import { Alert, Badge, Card, CardBody, EmptyState, Stat, StatGrid, StatusBadge, Table, TBody, TD, TEmpty, TH, THead, TR } from '@/components/console/ui';
import { Button, ConfirmDialog, Field, Modal, inputClass } from '@/components/console/interactive';
import { Toast } from '@/components/ds/overlay';

interface MarketingPost {
  id: number;
  tender_id: number;
  status: string;
  active_version_id: number;
  reference: string;
  title: string;
  buyer: string;
  category: string;
  sector: string;
  district: string;
  closing_at: string;
  tender_url: string;
  headline?: string;
  caption?: string;
  hashtags?: string;
  call_to_action?: string;
  template_id?: string;
  poster_url?: string;
  poster_error?: string;
  poster_width?: number;
  poster_height?: number;
  published_url?: string;
  published_at?: string;
}

interface TenderItem {
  id: number;
  source_id: string;
  reference: string;
  title: string;
  buyer: string;
  category: string;
  closing_at: string;
  tender_url: string;
  post_id?: number | null;
  post_status?: string | null;
}

interface MarketingJob {
  id: string;
  operation: 'sync' | 'generate';
  running: boolean;
  phase: string;
  fetched: number;
  imported: number;
  total: number;
  processed: number;
  generated: number;
  failures: { tenderId: number; error: string }[];
  error?: string;
}
type MarketingVersion = {
  id: number;
  headline?: string | null;
  caption?: string | null;
  hashtags?: string | null;
  call_to_action?: string | null;
  poster_url?: string | null;
  poster_error?: string | null;
};

function hasFutureDeadline(value?: string): boolean {
  if (!value) return false;
  const deadline = Date.parse(value.includes('T') ? value : value.replace(' ', 'T') + 'Z');
  return Number.isFinite(deadline) && deadline > Date.now();
}

export function MarketingConsoleClient() {
  const [activeTab, setActiveTab] = useState<'pending' | 'published' | 'tenders'>('pending');
  const [stats, setStats] = useState<{ totalTenders: number; pendingApproval: number; published: number; facebookConfigured: boolean; automation?: MarketingJob | null }>({ totalTenders: 0, pendingApproval: 0, published: 0, facebookConfigured: false, automation: null });
  const [startingBatch, setStartingBatch] = useState(false);
  const [generatingTender, setGeneratingTender] = useState<number | null>(null);
  const batchBusy = startingBatch || !!stats.automation?.running;
  const [posts, setPosts] = useState<MarketingPost[]>([]);
  const [tenders, setTenders] = useState<TenderItem[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [toast, setToast] = useState<{ m: string; t: 'ok' | 'bad' } | null>(null);

  // Review Modal State
  const [selectedPost, setSelectedPost] = useState<MarketingPost | null>(null);
  const [modalCaption, setModalCaption] = useState('');
  const [modalHeadline, setModalHeadline] = useState('');
  const [modalHashtags, setModalHashtags] = useState('');
  const [isPublishing, setIsPublishing] = useState(false);
  const [isRegenerating, setIsRegenerating] = useState(false);
  const [deletingPost, setDeletingPost] = useState<number | null>(null);
  const [confirmDelete, setConfirmDelete] = useState<MarketingPost | null>(null);
  const [confirmPublish, setConfirmPublish] = useState(false);

  // Fetch telemetry & data
  const fetchData = useCallback(async () => {
    try {
      const [statsRes, postsRes, tendersRes] = await Promise.all([
        fetch('/api/admin/marketing/stats'),
        fetch('/api/admin/marketing/marketing-posts'),
        fetch('/api/admin/marketing/tenders'),
      ]);

      const statsData = await statsRes.json();
      const postsData = await postsRes.json();
      const tendersData = await tendersRes.json();
      if (!statsRes.ok || !postsRes.ok || !tendersRes.ok || !statsData.success || !postsData.success || !tendersData.success) {
        throw new Error('Marketing data is unavailable.');
      }

      if (statsData.success) setStats(statsData.data);
      if (postsData.success) setPosts(postsData.data);
      if (tendersData.success) setTenders(tendersData.data);
      setLoaded(true);
      setLoadError(null);
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : String(err);
      setToast({ m: 'Error connecting to marketing engine: ' + msg, t: 'bad' });
      setLoadError('Could not load marketing data. Please try again.');
    }
  }, []);

  useEffect(() => {
    if (!stats.automation?.running) return;
    const timer = setInterval(() => { void fetchData(); }, 2000);
    return () => clearInterval(timer);
  }, [stats.automation?.running, fetchData]);

  useEffect(() => {
    let active = true;
    Promise.all([
      fetch('/api/admin/marketing/stats').then((r) => r.json()),
      fetch('/api/admin/marketing/marketing-posts').then((r) => r.json()),
      fetch('/api/admin/marketing/tenders').then((r) => r.json()),
    ])
      .then(([statsData, postsData, tendersData]) => {
        if (!active) return;
        if (!statsData.success || !postsData.success || !tendersData.success) {
          throw new Error('Marketing data is unavailable.');
        }
        if (statsData.success) setStats(statsData.data);
        if (postsData.success) setPosts(postsData.data);
        if (tendersData.success) setTenders(tendersData.data);
        setLoaded(true);
      })
      .catch((err: unknown) => {
        if (!active) return;
        const msg = err instanceof Error ? err.message : String(err);
        setToast({ m: 'Error connecting to marketing engine: ' + msg, t: 'bad' });
        setLoadError('Could not load marketing data. Please try again.');
      });
    return () => {
      active = false;
    };
  }, []);

  // Open review modal
  const openReviewModal = async (post: MarketingPost) => {
    try {
      const res = await fetch(`/api/admin/marketing/marketing-posts/${post.id}`);
      const data = await res.json();
      if (data.success) {
        const full = data.data;
        const versions = (full.versions ?? []) as MarketingVersion[];
        const activeVer = versions.find((v) => v.id === full.active_version_id) || versions[0] || {};
        setSelectedPost({
          ...full,
          headline: activeVer.headline || post.headline || '',
          caption: activeVer.caption || post.caption || '',
          hashtags: activeVer.hashtags || post.hashtags || '',
          call_to_action: activeVer.call_to_action || '',
          poster_url: activeVer.poster_url || post.poster_url || '',
          poster_error: activeVer.poster_error || post.poster_error,
        });
        setModalHeadline(activeVer.headline || post.headline || '');
        setModalCaption(activeVer.caption || post.caption || '');
        setModalHashtags(activeVer.hashtags || post.hashtags || '');
      } else {
        setSelectedPost(post);
        setModalHeadline(post.headline || '');
        setModalCaption(post.caption || '');
        setModalHashtags(post.hashtags || '');
      }
    } catch {
      setSelectedPost(post);
      setModalHeadline(post.headline || '');
      setModalCaption(post.caption || '');
      setModalHashtags(post.hashtags || '');
    }
  };

  const handleDeleteDraft = (post: MarketingPost) => {
    if (batchBusy || deletingPost !== null) return;
    setConfirmDelete(post);
  };

  const deleteDraft = async (post: MarketingPost) => {
    setConfirmDelete(null);
    if (batchBusy || deletingPost !== null) return;
    setDeletingPost(post.id);
    try {
      const response = await fetch(`/api/admin/marketing/marketing-posts/${post.id}`, { method: 'DELETE' });
      const data = await response.json();
      if (!response.ok || !data.success) throw new Error(data.error || 'Draft could not be deleted.');
      setToast({ m: data.message, t: 'ok' });
      if (selectedPost?.id === post.id) setSelectedPost(null);
      await fetchData();
    } catch (error) { setToast({ m: error instanceof Error ? error.message : 'Deletion failed.', t: 'bad' }); }
    finally { setDeletingPost(null); }
  };

  // Sync Tenders
  const handleSyncTenders = async () => {
    if (batchBusy) return;
    setStartingBatch(true);
    setToast({ m: 'Synchronizing latest tenders from TenderHub...', t: 'ok' });
    try {
      const res = await fetch('/api/admin/marketing/ingest/sync-existing?limit=100', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ limit: 100 }),
      });
      const data = await res.json();
      if (res.ok && data.success && data.queued) {
        setStats(prev => ({ ...prev, automation: data.job }));
        setToast({ m: 'Live-tender sync started. Follow the progress below; nothing is published automatically.', t: 'ok' });
      } else {
        setToast({ m: data.error || 'Failed to sync tenders', t: 'bad' });
      }
    } catch (err: unknown) {
      setToast({ m: err instanceof Error ? err.message : 'Request failed.', t: 'bad' });
    } finally { setStartingBatch(false); }
  };

  // Generate All Active Drafts (Rule 1 & Rule 2 Main Template)
  const handleGenerateAllActive = async () => {
    if (batchBusy) return;
    setStartingBatch(true);
    setToast({ m: 'Starting approved-template posters (25% document header) for active tenders...', t: 'ok' });
    try {
      const res = await fetch('/api/admin/marketing/posts/generate-active?limit=50', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
      });
      const data = await res.json();
      if (res.ok && data.success && data.queued) {
        setStats(prev => ({ ...prev, automation: data.job }));
        setToast({ m: 'Draft generation started. Progress and failed documents appear below.', t: 'ok' });
      } else {
        setToast({ m: data.error || 'Batch generation failed', t: 'bad' });
      }
    } catch (err: unknown) {
      setToast({ m: err instanceof Error ? err.message : 'Request failed.', t: 'bad' });
    } finally { setStartingBatch(false); }
  };

  // Generate Draft for Tender
  const handleGenerateDraft = async (tenderId: number) => {
    if (batchBusy || generatingTender !== null) return;
    setGeneratingTender(tenderId);
    setToast({ m: 'Generating factual copy and the approved 25% document-header poster...', t: 'ok' });
    try {
      const res = await fetch(`/api/admin/marketing/tenders/${tenderId}/generate-draft`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({}),
      });
      const data = await res.json();
      if (data.success) {
        setToast({ m: 'Draft created and queued for review!', t: 'ok' });
        fetchData();
        setActiveTab('pending');
      } else {
        setToast({ m: data.error || 'Draft generation failed', t: 'bad' });
      }
    } catch (err: unknown) {
      setToast({ m: err instanceof Error ? err.message : 'Request failed.', t: 'bad' });
    } finally { setGeneratingTender(null); }
  };

  // Regenerate Poster
  const handleRegeneratePoster = async () => {
    if (!selectedPost) return;
    setIsRegenerating(true);
    try {
      const res = await fetch(`/api/admin/marketing/marketing-posts/${selectedPost.id}/regenerate-poster`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          format: 'square',
          templateId: 'tenderhub-coastal-v1',
        }),
      });
      const data = await res.json();
      if (data.success) {
        setToast({ m: 'TenderHub template rendered with document header (top 25%).', t: 'ok' });
        setSelectedPost((prev) => prev ? { ...prev, poster_url: data.poster.fileUrl, poster_error: undefined } : null);
        fetchData();
      } else {
        setToast({ m: data.error || 'Poster rendering failed', t: 'bad' });
      }
    } catch (err: unknown) {
      setToast({ m: err instanceof Error ? err.message : 'Request failed.', t: 'bad' });
    } finally {
      setIsRegenerating(false);
    }
  };

  const editsDirty = !!selectedPost && (
    modalHeadline !== (selectedPost.headline ?? '') ||
    modalCaption !== (selectedPost.caption ?? '') ||
    modalHashtags !== (selectedPost.hashtags ?? '')
  );
  const [isSaving, setIsSaving] = useState(false);

  /**
   * Persist the modal edits as a new post version (engine PUT
   * /marketing-posts/:id). Edits used to live only in component state: they
   * were discarded on close AND on publish, which sent the stored version.
   */
  const handleSaveEdits = async (): Promise<boolean> => {
    if (!selectedPost) return false;
    setIsSaving(true);
    try {
      const res = await fetch(`/api/admin/marketing/marketing-posts/${selectedPost.id}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ headline: modalHeadline, caption: modalCaption, hashtags: modalHashtags, change_summary: 'Edited in console' }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.success) {
        setToast({ m: data.error || 'Your edits could not be saved.', t: 'bad' });
        return false;
      }
      // Reload from the engine so the modal shows exactly what was stored
      // (the engine re-applies TenderHub branding rules on save).
      await openReviewModal(selectedPost);
      setToast({ m: 'Edits saved as a new version.', t: 'ok' });
      fetchData();
      return true;
    } catch {
      setToast({ m: 'Network error — edits not saved.', t: 'bad' });
      return false;
    } finally {
      setIsSaving(false);
    }
  };

  // Approve & Publish to Facebook
  const handleApproveAndPublish = async () => {
    if (!selectedPost) return;
    // Never publish a version the reviewer did not see: save pending edits first.
    if (editsDirty && !(await handleSaveEdits())) return;
    setConfirmPublish(true);
  };

  const publishSelected = async () => {
    setConfirmPublish(false);
    if (!selectedPost) return;
    setIsPublishing(true);
    try {
      const res = await fetch(`/api/admin/marketing/marketing-posts/${selectedPost.id}/approve-and-publish`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        // The approving staff member is identified server-side from the session.
        body: JSON.stringify({}),
      });
      const data = await res.json();
      if (data.success) {
        // Only a real Meta Graph API publication reaches here now — the engine
        // no longer fabricates a "simulated" success when credentials are absent.
        setToast({ m: 'Post published to the official Facebook page.', t: 'ok' });
        setSelectedPost(null);
        fetchData();
      } else if (data.notConfigured) {
        setToast({
          m: 'Not configured: Facebook credentials (FACEBOOK_PAGE_ID / FACEBOOK_PAGE_ACCESS_TOKEN) are not set. Nothing was published.',
          t: 'bad',
        });
        fetchData();
      } else {
        setToast({ m: data.error || data.errorMessage || 'Publishing failed — nothing was published.', t: 'bad' });
        fetchData();
      }
    } catch (err: unknown) {
      setToast({ m: err instanceof Error ? err.message : 'Request failed.', t: 'bad' });
    } finally {
      setIsPublishing(false);
    }
  };

  // Filter posts
  const pendingPosts = posts.filter((p) => ['PENDING_APPROVAL', 'EDITED', 'APPROVED'].includes(p.status));
  const publishedPosts = posts.filter((p) => p.status === 'PUBLISHED');

  const getPosterSrc = (url?: string) => {
    if (!url) return '';
    if (url.startsWith('/storage/')) {
      return `/api/admin/marketing-storage/${url.replace('/storage/', '')}`;
    }
    return url;
  };

  if (loadError) return <Alert tone="error" title={loadError} actions={<Button variant="secondary" size="sm" onClick={fetchData}>Retry</Button>} />;
  if (!loaded) return <p role="status" className="py-8 text-sm text-gray-500">Loading marketing data...</p>;


  const tabClass = (active: boolean) =>
    `shrink-0 whitespace-nowrap border-b-2 px-1 py-3 text-sm font-medium transition-colors ${active ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'}`;
  const closing = (v?: string) => (v ? new Date(v).toLocaleDateString('en-GB') : 'Soon');

  return (
    <div className="space-y-6">
      <Alert
        tone={stats.facebookConfigured ? 'info' : 'warning'}
        title={`Facebook publishing: ${stats.facebookConfigured ? 'Credentials configured. A post appears as published only after Meta accepts it.' : 'Not configured. Add the official page ID and access token on the server to publish.'}`}
      />
      {stats.automation && (
        <Card>
          <CardBody>
            <section role="status" className="space-y-2 text-sm text-gray-700">
              <p className="flex flex-wrap items-center gap-2 font-semibold text-gray-900">
                Marketing automation:
                <Badge tone={stats.automation.running ? 'yellow' : stats.automation.phase === 'failed' ? 'red' : 'green'} dot>
                  {stats.automation.running ? stats.automation.phase : stats.automation.phase === 'failed' ? 'Failed' : 'Completed'}
                </Badge>
              </p>
              <p>Live notices fetched: {stats.automation.fetched} · New records: {stats.automation.imported} · Drafts created: {stats.automation.generated} · Processed: {stats.automation.processed}/{stats.automation.total}</p>
              {!stats.automation.running && stats.automation.generated === 0 && !stats.automation.error && <p>No new drafts were created. Existing approved-template drafts are preserved; unavailable documents are listed below.</p>}
              {stats.automation.error && <p role="alert" className="text-red-700">{stats.automation.error}</p>}
              {stats.automation.failures.length > 0 && <details><summary className="cursor-pointer font-medium text-gray-900">{stats.automation.failures.length} document/draft failures</summary><ul className="mt-2 space-y-1 break-words">{stats.automation.failures.map(failure => <li key={failure.tenderId}>Tender #{failure.tenderId}: {failure.error}</li>)}</ul></details>}
            </section>
          </CardBody>
        </Card>
      )}
      {toast && <Toast message={toast.m} tone={toast.t} onDone={() => setToast(null)} />}

      <StatGrid>
        <Stat label="Ingested Notices" value={stats.totalTenders || 0} detail="Ready for marketing" />
        <Stat label="Pending Human Approval" value={stats.pendingApproval || 0} detail="Strict approval gate" tone={(stats.pendingApproval || 0) > 0 ? 'warn' : 'default'} />
        <Stat label="Published to Facebook" value={stats.published || 0} detail="Graph API v21.0" tone="good" />
        <div className="overflow-hidden rounded-lg bg-white px-4 py-5 shadow-sm ring-1 ring-gray-900/5 sm:p-6">
          <dt className="text-sm font-medium text-gray-500">Engine Actions</dt>
          <dd className="mt-1 text-sm text-gray-700">Live notice sync and draft generation</dd>
          <dd className="mt-3 flex flex-wrap gap-2">
            <Button size="sm" onClick={handleSyncTenders} disabled={batchBusy || generatingTender !== null}>
              Sync Notices ↻
            </Button>
            <Button size="sm" variant="soft" onClick={handleGenerateAllActive} disabled={batchBusy || generatingTender !== null}>
              Generate Active ⚡
            </Button>
          </dd>
        </div>
      </StatGrid>

      <nav className="-mx-1 flex gap-x-6 overflow-x-auto border-b border-gray-200 px-1" aria-label="Draft library sections">
        <button type="button" onClick={() => setActiveTab('pending')} aria-current={activeTab === 'pending' ? 'page' : undefined} className={tabClass(activeTab === 'pending')}>
          Review & Approval Queue ({pendingPosts.length})
        </button>
        <button type="button" onClick={() => setActiveTab('published')} aria-current={activeTab === 'published' ? 'page' : undefined} className={tabClass(activeTab === 'published')}>
          Published Feed ({publishedPosts.length})
        </button>
        <button type="button" onClick={() => setActiveTab('tenders')} aria-current={activeTab === 'tenders' ? 'page' : undefined} className={tabClass(activeTab === 'tenders')}>
          Tenders Ingestion Feed ({tenders.length})
        </button>
      </nav>

      {activeTab === 'pending' && (
        pendingPosts.length === 0 ? (
          <Card>
            <EmptyState
              title="All Clear! No Pending Drafts"
              description="All marketing posts have been reviewed or published. Switch to the Tenders tab to generate drafts for new notices."
            />
          </Card>
        ) : (
          <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
            {pendingPosts.map((post) => (
              <Card key={post.id} className="transition-shadow hover:shadow-md">
                <div className="flex flex-col sm:flex-row">
                  <div className="relative flex shrink-0 items-center justify-center overflow-hidden bg-gray-900 sm:size-48">
                    {post.poster_url ? (
                      <Image src={getPosterSrc(post.poster_url)} width={512} height={512} unoptimized loading="lazy" alt={post.title} className="h-full w-full object-cover" />
                    ) : (
                      <span className="p-4 text-center text-xs font-medium text-gray-400">{post.poster_error || 'No poster generated'}</span>
                    )}
                    <span className="absolute top-2 left-2"><Badge tone="yellow">PENDING</Badge></span>
                  </div>
                  <div className="flex min-w-0 flex-1 flex-col justify-between p-4">
                    <div className="min-w-0">
                      <div className="flex flex-wrap items-center gap-2">
                        <Badge tone="primary">{post.category || 'Tender'}</Badge>
                        <span className="text-xs font-medium break-all text-gray-400">{post.reference}</span>
                      </div>
                      <h4 className="mt-1.5 line-clamp-2 text-sm leading-snug font-semibold text-gray-900">{post.title}</h4>
                      <p className="mt-1 flex items-center gap-1 text-xs text-gray-500">
                        <Building2 aria-hidden="true" className="size-3.5 shrink-0" /> <span className="min-w-0 truncate">{post.buyer} &bull; {post.district || 'Sri Lanka'}</span>
                      </p>
                    </div>
                    <div className="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-gray-100 pt-3">
                      <span className="flex items-center gap-1 text-xs font-medium text-red-600">
                        <CalendarClock aria-hidden="true" className="size-3.5" /> Closing: {closing(post.closing_at)}
                      </span>
                      <div className="flex flex-wrap gap-2">
                        <Button size="sm" onClick={() => openReviewModal(post)}>Review & Publish →</Button>
                        <Button size="sm" variant="danger" disabled={batchBusy || deletingPost !== null || generatingTender !== null} onClick={() => handleDeleteDraft(post)}>{deletingPost === post.id ? 'Deleting…' : 'Delete Draft'}</Button>
                      </div>
                    </div>
                  </div>
                </div>
              </Card>
            ))}
          </div>
        )
      )}

      {activeTab === 'published' && (
        publishedPosts.length === 0 ? (
          <Card><EmptyState title="No posts have been published yet." /></Card>
        ) : (
          <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
            {publishedPosts.map((post) => (
              <Card key={post.id}>
                <div className="flex flex-col sm:flex-row">
                  <div className="relative flex shrink-0 items-center justify-center overflow-hidden bg-gray-900 sm:size-48">
                    {post.poster_url && (
                      <Image src={getPosterSrc(post.poster_url)} width={512} height={512} unoptimized loading="lazy" alt={post.title} className="h-full w-full object-cover" />
                    )}
                    <span className="absolute top-2 left-2"><Badge tone="green">PUBLISHED</Badge></span>
                  </div>
                  <div className="flex min-w-0 flex-1 flex-col justify-between p-4">
                    <div className="min-w-0">
                      <div className="flex flex-wrap items-center gap-2">
                        <Badge tone="green">{post.category || 'Tender'}</Badge>
                        <span className="text-xs font-medium break-all text-gray-400">{post.reference}</span>
                      </div>
                      <h4 className="mt-1.5 line-clamp-2 text-sm leading-snug font-semibold text-gray-900">{post.title}</h4>
                      <p className="mt-1 flex items-center gap-1 text-xs text-gray-500">
                        <Building2 aria-hidden="true" className="size-3.5 shrink-0" /> <span className="min-w-0 truncate">{post.buyer}</span>
                      </p>
                    </div>
                    <div className="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-gray-100 pt-3">
                      <span className="text-xs text-gray-400">
                        Published: {post.published_at ? new Date(post.published_at).toLocaleString('en-GB') : 'Verified'}
                      </span>
                      {post.published_url && (
                        <a href={post.published_url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-sm font-medium text-primary-600 hover:text-primary-500">
                          View Post <ExternalLink aria-hidden="true" className="size-3.5" />
                        </a>
                      )}
                    </div>
                  </div>
                </div>
              </Card>
            ))}
          </div>
        )
      )}

      {activeTab === 'tenders' && (
        <Card>
          <Table>
            <THead>
              <TH>Tender Reference</TH>
              <TH>Title & Buyer</TH>
              <TH>Category</TH>
              <TH>Deadline</TH>
              <TH align="right">Marketing Status</TH>
            </THead>
            <TBody>
              {tenders.map((t) => (
                <TR key={t.id}>
                  <TD className="font-mono text-xs whitespace-nowrap">{t.reference}</TD>
                  <TD className="max-w-xs">
                    <div className="truncate font-medium text-gray-900">{t.title}</div>
                    <div className="truncate text-xs text-gray-500">{t.buyer}</div>
                  </TD>
                  <TD><Badge>{t.category}</Badge></TD>
                  <TD className="whitespace-nowrap">{t.closing_at ? new Date(t.closing_at).toLocaleDateString('en-GB') : 'N/A'}</TD>
                  <TD align="right">
                    {t.post_status === 'PUBLISHED' ? (
                      <StatusBadge status="published" />
                    ) : ['PENDING_APPROVAL', 'EDITED', 'APPROVED'].includes(t.post_status || '') ? (
                      <Button
                        size="xs"
                        variant="soft"
                        onClick={() => {
                          const p = posts.find((x) => x.id === t.post_id);
                          if (p) openReviewModal(p);
                        }}
                      >
                        Review Draft
                      </Button>
                    ) : (
                      <Button
                        size="xs"
                        onClick={() => handleGenerateDraft(t.id)}
                        disabled={batchBusy || generatingTender !== null || !hasFutureDeadline(t.closing_at)}
                      >
                        {generatingTender === t.id ? 'Generating…' : !hasFutureDeadline(t.closing_at) ? 'Expired / unconfirmed deadline' : '+ Create Draft'}
                      </Button>
                    )}
                  </TD>
                </TR>
              ))}
              {tenders.length === 0 && <TEmpty colSpan={5} title="No tenders have been ingested for marketing yet." />}
            </TBody>
          </Table>
        </Card>
      )}

      <Modal
        open={!!selectedPost}
        onClose={() => setSelectedPost(null)}
        size="xl"
        title="Review Tender Announcement"
        description="Quality Control & Facebook Dispatch"
        footer={selectedPost ? (
          <>
            <Button
              onClick={handleApproveAndPublish}
              busy={isPublishing}
              disabled={isPublishing || isRegenerating || !stats.facebookConfigured || !selectedPost.poster_url || !hasFutureDeadline(selectedPost.closing_at)}
            >
              {isPublishing ? 'Publishing to Facebook...' : '✓ Approve & Publish to Facebook'}
            </Button>
            <Button variant="soft" onClick={() => void handleSaveEdits()} disabled={!editsDirty || isSaving || isPublishing}>
              {isSaving ? 'Saving…' : 'Save changes'}
            </Button>
            <Button variant="secondary" onClick={() => setSelectedPost(null)}>Cancel</Button>
          </>
        ) : undefined}
      >
        {selectedPost && (
          <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
            <div className="space-y-4">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="text-sm font-medium text-gray-900">Generated Poster</span>
                <span className="text-xs text-gray-500">TenderHub · 1:1 · Header 25%</span>
              </div>
              <div className="relative flex aspect-square items-center justify-center overflow-hidden rounded-lg bg-gray-900 ring-1 ring-gray-900/10">
                {selectedPost.poster_url ? (
                  <Image src={getPosterSrc(selectedPost.poster_url)} width={1024} height={1024} unoptimized alt="Tender Poster" className="h-full w-full object-contain" />
                ) : (
                  <span role="status" className="p-6 text-center text-xs text-gray-400">{isRegenerating ? 'Rendering poster...' : selectedPost.poster_error || 'No poster generated. Re-render from the authentic document.'}</span>
                )}
              </div>
              <div className="flex flex-wrap gap-2">
                <Button variant="secondary" className="flex-1" onClick={handleRegeneratePoster} disabled={isRegenerating}>
                  {isRegenerating ? 'Rendering Poster...' : '↻ Re-render TenderHub Template'}
                </Button>
                {!['PUBLISHED', 'PUBLISHING'].includes(selectedPost.status) && <Button variant="danger" disabled={batchBusy || deletingPost !== null} onClick={() => handleDeleteDraft(selectedPost)}>{deletingPost === selectedPost.id ? 'Deleting…' : 'Delete Draft'}</Button>}
              </div>
            </div>

            <div className="space-y-4">
              <Field label="Post Headline" htmlFor="mkt-headline">
                <input type="text" id="mkt-headline" value={modalHeadline} onChange={(e) => setModalHeadline(e.target.value)} className={inputClass} />
              </Field>
              <Field label="Facebook Post Caption (Verified Copy)" htmlFor="mkt-caption">
                <textarea rows={8} id="mkt-caption" value={modalCaption} onChange={(e) => setModalCaption(e.target.value)} className={inputClass} />
              </Field>
              <Field label="Hashtags" htmlFor="mkt-hashtags">
                <input type="text" id="mkt-hashtags" value={modalHashtags} onChange={(e) => setModalHashtags(e.target.value)} className={inputClass} />
              </Field>
              <div className="flex gap-2 rounded-md bg-gray-50 p-3 text-xs text-gray-600 ring-1 ring-gray-200">
                <Lock aria-hidden="true" className="size-4 shrink-0 text-gray-400" />
                <p><strong className="text-gray-900">Human Approval Invariant:</strong> Clicking below publishes directly to Meta Graph API v21.0. Zero automated publishing is permitted without this explicit click.</p>
              </div>
            </div>
          </div>
        )}
      </Modal>

      <ConfirmDialog
        open={!!confirmDelete}
        onClose={() => setConfirmDelete(null)}
        onConfirm={() => { if (confirmDelete) void deleteDraft(confirmDelete); }}
        title="Delete this draft?"
        message={<>Delete the draft for &ldquo;{confirmDelete?.title}&rdquo;? Original tender documents and publication history will be preserved.</>}
        confirmLabel="Delete draft"
      />
      <ConfirmDialog
        open={confirmPublish}
        onClose={() => setConfirmPublish(false)}
        onConfirm={() => void publishSelected()}
        destructive={false}
        title="Publish to Facebook?"
        message="Confirm publishing this tender announcement to the official Facebook page?"
        confirmLabel="Publish"
      />
    </div>
  );
}
