"use client";

import { useState, useEffect } from "react";
import { ExternalLink, Rocket } from "lucide-react";
import { Alert, Badge, Card, CardBody, CardHeader, EmptyState, ProgressBar, buttonClass, type Tone } from "@/components/console/ui";
import { Button, Select, inputClass } from "@/components/console/interactive";

const SEVERITY_TONE: Record<string, Tone> = { critical: "red", high: "yellow", medium: "primary", low: "gray" };

export interface SeoScores {
  overall: number;
  technicalSeo: number;
  onPage: number;
  content: number;
  architecture: number;
  internalLinking: number;
  structuredData: number;
  entity: number;
  aeo: number;
  aio: number;
  geo: number;
  accessibility: number;
  performance: number;
}

export interface CrawlStats {
  fetched: number;
  ok: number;
  redirects: number;
  clientErrors: number;
  serverErrors: number;
  totalBytes: number;
  avgResponseMs: number;
}

export interface SeoRecommendation {
  id: string;
  problemKey: string;
  category: string;
  contributingSignals: Array<{ engine: string; rule: string; severity: string; confidence: number }>;
  issue: string;
  detail: string;
  evidence: Array<{ kind: string; source: string; locator: string; note?: string }>;
  affectedUrls: string[];
  severity: "critical" | "high" | "medium" | "low";
  confidence: number;
  priority: number;
  currentState: string;
  recommendedState: string;
  implementationMethod: string;
  validationRule: string;
}

export interface SeoStatusData {
  is_auditing: boolean;
  engine_available: boolean;
  last_error: string | null;
  is_configured: boolean;
  last_audit_at: string | null;
  target_url: string;
  recent_logs?: string[];
  scores: SeoScores | null;
  crawl_stats: CrawlStats | null;
  summary: {
    capabilities_present: number;
    conflicts: number;
    recommendations_count: number;
    critical_count: number;
    high_count: number;
    medium_count: number;
    low_count: number;
  } | null;
  disclaimer: string;
  monitoring?: { enabled: boolean; interval_seconds: number; max_pages: number; next_audit_at: string | null } | null;
  platform_syncing?: boolean;
  google?: {
    configured: boolean; connected: boolean; property?: string; last_error?: string | null;
    last_synced_at?: string; date_range?: { startDate: string; endDate: string };
    metrics?: { clicks: number; impressions: number; ctr: number; position: number } | null;
  };
  analytics?: {
    configured: boolean; connected: boolean; property_id?: string | null; last_error?: string | null;
    last_synced_at?: string; date_range?: { startDate: string; endDate: string };
    metrics?: { active_users: number; new_users: number; sessions: number; page_views: number } | null;
  };
  bing?: {
    configured: boolean; connected: boolean; site_url?: string; last_error?: string | null;
    last_synced_at?: string;
    metrics?: { clicks: number; impressions: number; ctr: number } | null;
  };
}

export function SeoConsoleClient({ initialStatus }: { initialStatus: SeoStatusData }) {
  const [status, setStatus] = useState<SeoStatusData>(initialStatus);
  const [recommendations, setRecommendations] = useState<SeoRecommendation[]>([]);
  const [loadingRecs, setLoadingRecs] = useState(false);
  const [recommendationsError, setRecommendationsError] = useState(false);
  const [activeTab, setActiveTab] = useState<"queue" | "scores" | "dashboard">("queue");
  const [severityFilter, setSeverityFilter] = useState<string>("all");
  const [isAuditing, setIsAuditing] = useState(initialStatus?.is_auditing || false);
  const [auditTarget, setAuditTarget] = useState(initialStatus?.target_url || "https://tenderhub.lk");
  const [maxPages, setMaxPages] = useState<number>(20);
  const [message, setMessage] = useState<{ text: string; type: "ok" | "err" } | null>(null);

  // Load recommendations on mount
  useEffect(() => {
    async function loadRecommendations() {
      setLoadingRecs(true);
      try {
        const res = await fetch("/api/admin/admin/seo/recommendations");
        if (res.ok) {
          const json = await res.json();
          setRecommendations(json.data || []);
          setRecommendationsError(false);
        } else {
          setRecommendationsError(true);
        }
      } catch (e) {
        setRecommendationsError(true);
        console.error("Failed to load SEO recommendations", e);
      } finally {
        setLoadingRecs(false);
      }
    }
    loadRecommendations();
  }, []);

  // Also observe audits started by the background scheduler.
  useEffect(() => {
    let active = true;
    let polling = false;
    const timer = setInterval(async () => {
      if (polling) return;
      polling = true;
      try {
        const res = await fetch("/api/admin/admin/seo/status");
        if (res.ok) {
          const json = await res.json();
          if (active && json.data) {
            setStatus(json.data);
            if (json.data.is_auditing) setIsAuditing(true);
            if (!json.data.is_auditing) {
              setIsAuditing(false);
              if (isAuditing && json.data.last_error) {
                setMessage({ text: `SEO audit failed: ${json.data.last_error}`, type: "err" });
                return;
              }
              // Only claim a completed audit when a REAL report actually exists.
              // The poller used to announce "completed successfully" purely
              // because is_auditing was false — which it always is — so an audit
              // that never ran reported success.
              if (json.data.is_configured) {
                if (isAuditing) setMessage({ text: "SEO audit report loaded.", type: "ok" });
                const rRes = await fetch("/api/admin/admin/seo/recommendations");
                if (rRes.ok) {
                  const rJson = await rRes.json();
                  if (active) { setRecommendations(rJson.data || []); setRecommendationsError(false); }
                } else {
                  if (active) setRecommendationsError(true);
                }
              } else if (isAuditing) {
                setMessage({
                  text: "The audit finished but no SEO report was produced. Check the audit service logs.",
                  type: "err",
                });
              }
            }
          }
        }
      } catch {
        if (active) setMessage({ text: "Could not refresh SEO monitoring status. Please retry.", type: "err" });
      } finally { polling = false; }
    }, isAuditing ? 2500 : 15000);
    return () => { active = false; clearInterval(timer); };
  }, [isAuditing]);

  async function handleTriggerAudit() {
    setIsAuditing(true);
    setMessage(null);
    try {
      const res = await fetch("/api/admin/admin/seo/audit", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ targetUrl: auditTarget, maxPages }),
      });
      const json = await res.json();
      if (res.ok) {
        setMessage({
          text: `SEO Audit initiated for ${auditTarget} (${maxPages} pages). Processing in background...`,
          type: "ok",
        });
      } else {
        // The engine is out-of-band (503) — show the honest reason rather than
        // leaving the poller to report a phantom "completed".
        setIsAuditing(false);
        setMessage({ text: json.detail || "The SEO audit engine is not available from the console.", type: "err" });
      }
    } catch {
      setIsAuditing(false);
      setMessage({ text: "Network error triggering SEO audit.", type: "err" });
    }
  }

  async function handlePlatformSync() {
    setMessage(null);
    setStatus(current => ({ ...current, platform_syncing: true }));
    try {
      const res = await fetch("/api/admin/admin/seo/platforms/sync", { method: "POST" });
      const json = await res.json();
      if (!res.ok) throw new Error(json.message || json.data?.error || "Search platform sync could not be started.");
      setMessage({ text: "Google and Bing synchronization started. Results will refresh automatically.", type: "ok" });
    } catch (error) {
      setStatus(current => ({ ...current, platform_syncing: false }));
      setMessage({ text: error instanceof Error ? error.message : "Search platform sync failed.", type: "err" });
    }
  }

  const scores = status?.scores;

  const filteredRecs = recommendations.filter((r) => {
    if (severityFilter === "all") return true;
    return r.severity === severityFilter;
  });

  return (
    <div className="space-y-6">
      {!status.engine_available && (
        <Alert tone="warning" title="The SEO audit service is unavailable.">
          Report data below is the last stored audit, if any.
        </Alert>
      )}
      {status.last_error && !isAuditing && <Alert tone="warning" title={`Last audit failed: ${status.last_error}`} />}

      <Card>
        <CardHeader
          title="Search platform connections"
          actions={<Button size="xs" variant="secondary" disabled={status.platform_syncing || !status.engine_available} onClick={handlePlatformSync}>{status.platform_syncing ? "Syncing..." : "Sync now"}</Button>}
        />
        <CardBody className="space-y-3">
          <p className="text-sm text-gray-600">
            {status.monitoring?.enabled
              ? `Automatic on-page audit every ${status.monitoring.interval_seconds / 3600} hours (${status.monitoring.max_pages} public pages per audit). Search platform metrics sync daily.`
              : "Automatic audit schedule is unavailable."}
          </p>
          <div className="grid gap-4 lg:grid-cols-3">
            <PlatformCard
              title="Google Search Console"
              connected={Boolean(status.google?.connected)}
              configured={Boolean(status.google?.configured)}
              detail={status.google?.connected ? status.google.property || "tenderhub.lk" : status.google?.last_error || "Service account access is required."}
              syncedAt={status.google?.last_synced_at}
              metrics={status.google?.metrics ? [
                ["Clicks", status.google.metrics.clicks.toLocaleString()],
                ["Impressions", status.google.metrics.impressions.toLocaleString()],
                ["CTR", `${(status.google.metrics.ctr * 100).toFixed(2)}%`],
                ["Avg. position", status.google.metrics.position.toFixed(1)],
              ] : []}
            />
            <PlatformCard
              title="Google Analytics 4"
              connected={Boolean(status.analytics?.connected)}
              configured={Boolean(status.analytics?.configured)}
              detail={status.analytics?.connected ? `Property ${status.analytics.property_id}` : status.analytics?.last_error || "GA4 property access is required."}
              syncedAt={status.analytics?.last_synced_at}
              metrics={status.analytics?.metrics ? [
                ["Active users", status.analytics.metrics.active_users.toLocaleString()],
                ["New users", status.analytics.metrics.new_users.toLocaleString()],
                ["Sessions", status.analytics.metrics.sessions.toLocaleString()],
                ["Page views", status.analytics.metrics.page_views.toLocaleString()],
              ] : []}
            />
            <PlatformCard
              title="Bing Webmaster"
              connected={Boolean(status.bing?.connected)}
              configured={Boolean(status.bing?.configured)}
              detail={status.bing?.connected ? status.bing.site_url || "https://tenderhub.lk/" : status.bing?.last_error || "Bing Webmaster API access is required."}
              syncedAt={status.bing?.last_synced_at}
              metrics={status.bing?.metrics ? [
                ["Clicks", status.bing.metrics.clicks.toLocaleString()],
                ["Impressions", status.bing.metrics.impressions.toLocaleString()],
                ["CTR", `${(status.bing.metrics.ctr * 100).toFixed(2)}%`],
              ] : []}
            />
          </div>
          <p className="text-xs text-gray-500">Metrics come from each provider API. Search Console reports search visibility, Analytics reports site usage, and Bing reports Bing search performance.</p>
        </CardBody>
      </Card>

      {message && (
        <Alert
          tone={message.type === "ok" ? "success" : "error"}
          title={message.text}
          actions={<Button variant="ghost" size="xs" onClick={() => setMessage(null)}>Dismiss</Button>}
        />
      )}

      {/* Top Summary & Metric Banner */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Card>
          <CardBody className="flex h-full flex-col justify-between">
            <div className="flex items-center justify-between gap-2">
              <span className="text-sm font-medium text-gray-500">Optimization Score</span>
              <Badge tone="primary">UWOE v1.0</Badge>
            </div>
            <div className="my-4 flex items-baseline gap-2">
              <span className="text-4xl font-semibold tracking-tight text-gray-900">{scores ? scores.overall : "—"}</span>
              <span className="text-sm font-medium text-gray-500">/ 100</span>
            </div>
            <div className="flex min-w-0 items-center gap-1.5 text-xs text-gray-500">
              <span className={`inline-block size-2 shrink-0 rounded-full ${scores ? "bg-green-500" : "bg-yellow-500"}`} />
              <span className="truncate">Target: {status?.target_url || "https://tenderhub.lk"}{scores ? "" : " (Pending initial audit)"}</span>
            </div>
          </CardBody>
        </Card>

        <Card>
          <CardBody className="flex h-full flex-col justify-between">
            <span className="text-sm font-medium text-gray-500">Recommendation Queue</span>
            <div className="my-4 flex flex-wrap items-baseline gap-3">
              <span className="text-4xl font-semibold tracking-tight text-gray-900">{recommendations.length}</span>
              <Badge tone="yellow">
                {recommendations.filter((r) => r.severity === "high" || r.severity === "critical").length} High Priority
              </Badge>
            </div>
            <div className="text-xs text-gray-500">
              {status?.summary?.capabilities_present || 0} SEO & schema capabilities active
            </div>
          </CardBody>
        </Card>

        <Card>
          <CardBody className="flex h-full flex-col justify-between">
            <span className="text-sm font-medium text-gray-500">AI & Generative Readiness</span>
            <div className="my-4 grid grid-cols-3 gap-2 text-center">
              {([["AEO", scores?.aeo], ["AIO", scores?.aio], ["GEO", scores?.geo]] as const).map(([k, v]) => (
                <div key={k} className="rounded-md bg-primary-50 p-2">
                  <div className="text-xs font-semibold text-primary-700">{k}</div>
                  <div className="text-lg font-semibold text-gray-900">{scores ? `${v}%` : "—"}</div>
                </div>
              ))}
            </div>
            <div className="text-xs text-gray-500">Readiness scores come from the latest completed site audit.</div>
          </CardBody>
        </Card>

        <Card>
          <CardBody className="flex h-full flex-col justify-between gap-2">
            <span className="text-sm font-medium text-gray-500">Trigger Audit</span>
            <input
              type="text"
              aria-label="Audit target URL"
              value={auditTarget}
              onChange={(e) => setAuditTarget(e.target.value)}
              disabled={isAuditing}
              className={`${inputClass} font-mono`}
              placeholder="https://tenderhub.lk"
            />
            <Select<string>
              value={String(maxPages)}
              onChange={(v) => setMaxPages(Number(v))}
              disabled={isAuditing}
              placeholder={`${maxPages} pages`}
              options={[
                { value: "10", label: "10 pages (Quick)" },
                { value: "25", label: "25 pages (Standard)" },
                { value: "50", label: "50 pages (Deep)" },
                { value: "150", label: "150 pages (Comprehensive)" },
              ]}
            />
            <Button
              onClick={handleTriggerAudit}
              busy={isAuditing}
              disabled={!status.engine_available}
              className="w-full justify-center"
            >
              {isAuditing ? "Auditing Site..." : (<><Rocket aria-hidden="true" className="size-4" />Run Audit Now</>)}
            </Button>
          </CardBody>
        </Card>
      </div>

      {/* Navigation Tabs */}
      <div className="flex flex-col gap-2 border-b border-gray-200 sm:flex-row sm:items-end sm:justify-between">
        <nav role="tablist" aria-label="SEO views" className="-mb-px flex gap-x-6 overflow-x-auto">
          {([
            ["queue", `Prioritized Recommendations (${recommendations.length})`],
            ["scores", "Category Scores & Metrics"],
            ["dashboard", "Full Interactive UWOE Dashboard"],
          ] as const).map(([key, label]) => (
            <button
              key={key}
              type="button"
              role="tab"
              aria-selected={activeTab === key}
              onClick={() => setActiveTab(key)}
              className={`shrink-0 whitespace-nowrap border-b-2 px-1 py-3 text-sm font-medium ${
                activeTab === key
                  ? "border-primary-600 text-primary-600"
                  : "border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700"
              }`}
            >
              {label}
            </button>
          ))}
        </nav>
        <div className="pb-2 text-xs text-gray-500">
          Last Crawl: {status?.last_audit_at ? new Date(status.last_audit_at).toLocaleString() : "Never"}
        </div>
      </div>

      {/* Tab 1: Recommendations Action Queue */}
      {activeTab === "queue" && (
        <div className="space-y-4">
          <div className="flex flex-col gap-3 rounded-lg bg-gray-50 p-3 ring-1 ring-gray-200 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex flex-wrap items-center gap-2">
              <span className="text-sm font-medium text-gray-700">Severity:</span>
              {(["all", "critical", "high", "medium", "low"] as const).map((sev) => (
                <Button
                  key={sev}
                  size="xs"
                  variant={severityFilter === sev ? "primary" : "secondary"}
                  aria-pressed={severityFilter === sev}
                  onClick={() => setSeverityFilter(sev)}
                >
                  {sev.charAt(0).toUpperCase() + sev.slice(1)}
                </Button>
              ))}
            </div>
            <div className="text-xs text-gray-500">
              Showing {filteredRecs.length} of {recommendations.length} action items
            </div>
          </div>

          {loadingRecs ? (
            <Card><CardBody className="py-12 text-center text-sm text-gray-500">Loading recommendation queue...</CardBody></Card>
          ) : filteredRecs.length === 0 ? (
            <Card>
              <EmptyState
                title={recommendationsError ? "Recommendations unavailable" : !status.is_configured ? "No audit report yet" : "No recommendations in this filter"}
                description={recommendationsError ? "The recommendation request failed. Retry or refresh this page." : !status.is_configured ? "Run an audit to assess the public pages. No checks have been evaluated yet." : "The latest sampled audit reported no recommendations matching this filter. This does not mean every page passes every check."}
              />
            </Card>
          ) : (
            <div className="space-y-3">
              {filteredRecs.map((rec) => (
                <Card key={rec.id}>
                  <CardBody className="space-y-3">
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                      <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                          <Badge tone={SEVERITY_TONE[rec.severity] ?? "gray"}>{rec.severity}</Badge>
                          <Badge tone="gray">Priority {rec.priority}</Badge>
                          <span className="text-xs font-medium uppercase tracking-wide text-gray-500">
                            {rec.category.replace(/_/g, " ")}
                          </span>
                          <span className="text-xs text-gray-400">•</span>
                          <span className="text-xs text-gray-500">
                            Engines: {rec.contributingSignals.map((s) => s.engine).join(", ")}
                          </span>
                        </div>
                        <h4 className="mt-1 text-sm font-semibold text-gray-900">{rec.issue}</h4>
                      </div>
                      <div className="shrink-0">
                        <Badge tone="gray">{rec.affectedUrls.length} URL{rec.affectedUrls.length > 1 ? "s" : ""}</Badge>
                      </div>
                    </div>

                    <p className="text-sm text-gray-600">{rec.detail}</p>

                    <div className="grid grid-cols-1 gap-3 border-t border-gray-100 pt-3 text-sm md:grid-cols-2">
                      <div className="rounded-md bg-gray-50 p-3">
                        <span className="mb-0.5 block font-medium text-gray-700">Current State:</span>
                        <span className="text-gray-600">{rec.currentState}</span>
                      </div>
                      <div className="rounded-md bg-green-50 p-3">
                        <span className="mb-0.5 block font-medium text-green-800">Recommended State:</span>
                        <span className="text-green-900">{rec.recommendedState}</span>
                      </div>
                    </div>

                    {rec.evidence && rec.evidence.length > 0 && (
                      <div className="rounded-md bg-gray-50 p-3 text-sm">
                        <div className="mb-1 font-medium text-gray-700">Evidence ({rec.evidence.length} samples):</div>
                        <ul className="space-y-1 font-mono text-xs text-gray-600">
                          {rec.evidence.slice(0, 3).map((ev, i) => (
                            <li key={i} className="truncate">
                              [{ev.kind}] {ev.locator} — <span className="text-gray-500">{ev.note || ev.source}</span>
                            </li>
                          ))}
                        </ul>
                      </div>
                    )}

                    <div className="flex flex-col gap-1 text-xs text-gray-500 sm:flex-row sm:items-center sm:justify-between">
                      <span className="min-w-0 break-words">Validation: <code className="font-mono text-gray-700">{rec.validationRule}</code></span>
                      <span>Action: {rec.implementationMethod}</span>
                    </div>
                  </CardBody>
                </Card>
              ))}
            </div>
          )}
        </div>
      )}

      {/* Tab 2: Category Breakdown */}
      {activeTab === "scores" && (
        !scores ? (
          <Card>
            <EmptyState
              title="No Audit Scores Available"
              description="No audit has been performed for this target yet. Click “Run Audit Now” above to calculate live category scores."
            />
          </Card>
        ) : (
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
            {[
              { label: "Technical SEO", score: scores.technicalSeo, desc: "Robots, sitemaps, canonicals, status codes, indexing directives" },
              { label: "Structured Data", score: scores.structuredData, desc: "Schema.org vocabulary, JSON-LD completeness, entity attribution" },
              { label: "Architecture", score: scores.architecture, desc: "Crawl depth, orphan pages, URL structure, hierarchy" },
              { label: "Entity Graph", score: scores.entity, desc: "Brand disambiguation, authority, organization entity resolution" },
              { label: "Answer Engine (AEO)", score: scores.aeo, desc: "Direct Q&A extractability, FAQ markup, Perplexity query alignment" },
              { label: "AI Overview (AIO)", score: scores.aio, desc: "Syntactic structure, tables, definitions, SGE readability" },
              { label: "Generative Search (GEO)", score: scores.geo, desc: "Citation reliability, factual density, brand prominence" },
              { label: "Content Quality", score: scores.content, desc: "Thin page detection, reading ease, duplicate titles, headings" },
              { label: "Internal Linking", score: scores.internalLinking, desc: "PageRank flow, anchor text distribution, contextual linking" },
              { label: "Performance", score: scores.performance, desc: "Asset hygiene, dimension declarations, payload budgets" },
              { label: "Accessibility", score: scores.accessibility, desc: "Form controls, alt attributes, landmarks, contrast compliance" },
            ].map((cat, idx) => {
              const tone = cat.score >= 90 ? "green" : cat.score >= 75 ? "primary" : "yellow";
              return (
                <Card key={idx}>
                  <CardBody>
                    <div className="flex items-center justify-between gap-2">
                      <h4 className="text-sm font-semibold text-gray-900">{cat.label}</h4>
                      <Badge tone={tone}>{cat.score}%</Badge>
                    </div>
                    <div className="my-3">
                      <ProgressBar value={cat.score} max={100} tone={tone} label={cat.label} />
                    </div>
                    <p className="text-xs text-gray-500">{cat.desc}</p>
                  </CardBody>
                </Card>
              );
            })}
          </div>
        )
      )}

      {/* Tab 3: Full UWOE Interactive Standalone Dashboard */}
      {activeTab === "dashboard" && (
        <Card className="flex h-[70dvh] min-h-96 flex-col">
          <CardHeader
            title="UWOE Standalone Optimization Dashboard"
            actions={
              <a
                href="/api/admin/admin/seo/dashboard"
                target="_blank"
                rel="noreferrer"
                className={buttonClass("secondary", "sm")}
              >
                Open in New Window
                <ExternalLink aria-hidden="true" className="size-4" />
              </a>
            }
          />
          <iframe
            src="/api/admin/admin/seo/dashboard"
            className="w-full flex-1 border-0"
            title="Unified Website Optimization Engine Dashboard"
          />
        </Card>
      )}

      {/* Disclaimer */}
      <div className="rounded-lg bg-gray-50 p-4 text-xs text-gray-500 ring-1 ring-gray-200">
        <span className="font-semibold text-gray-700">Governance & Score Disclaimer: </span>
        {status?.disclaimer || "Scores measure how many of this platform's technical and content checks the site currently passes. They are not search rankings, traffic forecasts, or guarantees of inclusion in search results or AI-generated answers."}
      </div>
    </div>
  );
}

function PlatformCard({ title, connected, configured, detail, syncedAt, metrics }: {
  title: string;
  connected: boolean;
  configured: boolean;
  detail: string;
  syncedAt?: string;
  metrics: Array<[string, string]>;
}) {
  return (
    <section className="rounded-lg border border-gray-200 bg-white p-4">
      <div className="flex items-center justify-between gap-2">
        <h3 className="text-sm font-semibold text-gray-900">{title}</h3>
        <Badge tone={connected ? "green" : configured ? "yellow" : "gray"}>
          {connected ? "Connected" : configured ? "Needs attention" : "Not configured"}
        </Badge>
      </div>
      <p className="mt-2 min-h-10 text-xs text-gray-600">{detail}</p>
      {metrics.length > 0 ? (
        <dl className="mt-3 grid grid-cols-2 gap-2">
          {metrics.map(([label, value]) => (
            <div key={label} className="rounded-md bg-gray-50 px-2.5 py-2">
              <dt className="text-[11px] font-medium text-gray-500">{label}</dt>
              <dd className="text-sm font-semibold text-gray-900">{value}</dd>
            </div>
          ))}
        </dl>
      ) : null}
      {syncedAt ? <p className="mt-3 text-[11px] text-gray-500">Last sync: {new Date(syncedAt).toLocaleString()}</p> : null}
    </section>
  );
}
