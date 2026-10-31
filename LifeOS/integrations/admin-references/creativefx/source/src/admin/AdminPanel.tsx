import React, { useState, useEffect } from 'react';
import {
  X, Upload, RotateCcw, Loader, CheckCircle, LayoutDashboard, Globe, FolderKanban,
  Image as ImageIcon, FileText, Briefcase, Heart, Palette, Mail,
  Search, BarChart3, Settings, ShieldCheck, Home, Menu, AlertCircle
} from 'lucide-react';
import { useContent } from '../context/ContentContext';
import { DashboardSection } from './components/DashboardSection';
import { WebsiteSection } from './components/WebsiteSection';
import { WorksSection } from './components/WorksSection';
import { MediaLibrarySection } from './components/MediaLibrarySection';
import { PagesSection } from './components/PagesSection';
import { HomepageSection } from './components/HomepageSection';
import { WeddingsAdmin } from './components/WeddingsAdmin';
import { ServicesAdmin } from './components/ServicesAdmin';
import { AboutAdmin } from './components/AboutAdmin';
import { CreativePoolAdmin } from './components/CreativePoolAdmin';
import { ThemeSection } from './components/ThemeSection';
import { ContactInquiriesSection } from './components/ContactSection';
import { SEOSection } from './components/SEOSection';
import { AnalyticsSection } from './components/AnalyticsSection';
import { SettingsSection } from './components/SettingsSection';
import { AdminUsersSection } from './components/AdminUsersSection';

interface AdminPanelProps {
  onClose: () => void;
}

// ─── Menu definitions — each item maps to a public website section ───────────
const MENU = [
  { category: 'Dashboard', items: ['Dashboard'], icon: LayoutDashboard },
  { category: 'Homepage', items: ['Homepage'], icon: Home },
  { category: 'Works / Projects', items: ['All Projects', 'Add Project', 'Photography', 'Video'], icon: FolderKanban },
  { category: 'Weddings', items: ['Stories', 'Timeline', 'Hero', 'Approach'], icon: Heart },
  { category: 'Services', items: ['Services'], icon: Briefcase },
  { category: 'About', items: ['About'], icon: FileText },
  { category: 'Creative Pool', items: ['Creative Pool'], icon: ImageIcon },
  { category: 'Pages', items: ['Works', 'Contact'], icon: FileText },
  { category: 'Media Library', items: ['Images', 'Videos', 'Uploaded Files', 'Upload / Replace'], icon: ImageIcon },
  { category: 'Navigation & Footer', items: ['Navigation', 'Footer', 'Page Visibility'], icon: Globe },
  { category: 'Contact / Inquiries', items: ['Contact / Inquiries'], icon: Mail },
  { category: 'SEO', items: ['SEO'], icon: Search },
  { category: 'Theme / Branding', items: ['Theme / Branding'], icon: Palette },
  { category: 'Analytics', items: ['Analytics'], icon: BarChart3 },
  { category: 'Settings', items: ['Settings'], icon: Settings },
  { category: 'Admin Users', items: ['Admin Users'], icon: ShieldCheck }
];

// ─── Main AdminPanel ──────────────────────────────────────────────────────────
export const AdminPanel: React.FC<AdminPanelProps> = ({ onClose }) => {
  const { content, updateContent, saveDraft, publishContent, resetContent, isSaving, hasDraft, lastSaved } = useContent();
  const [activeCategory, setActiveCategory] = useState('Dashboard');
  const [activeItem, setActiveItem] = useState('Dashboard');
  const [localContent, setLocalContent] = useState(content);
  const [publishStatus, setPublishStatus] = useState<'idle' | 'publishing' | 'published' | 'error'>('idle');
  const [draftSaved, setDraftSaved] = useState(false);
  // On phones the fixed-width sidebar is an off-canvas drawer (desktop unchanged).
  const [sidebarOpen, setSidebarOpen] = useState(false);

  // Prevent background body scroll and completely remove/hide chat bot in Admin Panel
  useEffect(() => {
    const origOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    document.body.classList.add('admin-active');

    const hideChat = () => {
      document.querySelectorAll(
        '#worksuite-chat-widget, iframe[src*="worksuite"], [id*="worksuite"], [class*="worksuite"], div[class*="chat-widget"], .floating-chat-btn, .fc-widget-normal, #fc_frame'
      ).forEach(el => {
        (el as HTMLElement).style.setProperty('display', 'none', 'important');
        (el as HTMLElement).style.setProperty('visibility', 'hidden', 'important');
        (el as HTMLElement).style.setProperty('opacity', '0', 'important');
        (el as HTMLElement).style.setProperty('pointer-events', 'none', 'important');
        (el as HTMLElement).style.setProperty('z-index', '-9999', 'important');
      });
    };
    hideChat();
    const interval = setInterval(hideChat, 600);

    return () => {
      clearInterval(interval);
      document.body.style.overflow = origOverflow;
      document.body.classList.remove('admin-active');
      document.querySelectorAll(
        '#worksuite-chat-widget, iframe[src*="worksuite"], [id*="worksuite"], [class*="worksuite"], div[class*="chat-widget"], .floating-chat-btn, .fc-widget-normal, #fc_frame'
      ).forEach(el => {
        (el as HTMLElement).style.removeProperty('display');
        (el as HTMLElement).style.removeProperty('visibility');
        (el as HTMLElement).style.removeProperty('opacity');
        (el as HTMLElement).style.removeProperty('pointer-events');
        (el as HTMLElement).style.removeProperty('z-index');
      });
    };
  }, []);

  // Keep localContent in sync if external content changes (e.g. page load from server)
  useEffect(() => {
    setLocalContent(content);
  }, [content]);

  // Local update (immediate UI) then push to context
  const update = (path: string[], value: any) => {
    const newLocal = JSON.parse(JSON.stringify(localContent));
    if (path.length === 0) {
      setLocalContent(value);
      updateContent([], value);
      return;
    }
    let cur = newLocal;
    for (let i = 0; i < path.length - 1; i++) {
      const k = path[i];
      if (cur[k] === undefined) cur[k] = {};
      cur = cur[k];
    }
    cur[path[path.length - 1]] = value;
    setLocalContent(newLocal);
    updateContent(path, value);
  };

  const handlePublish = async () => {
    setPublishStatus('publishing');
    const ok = await publishContent();
    setPublishStatus(ok ? 'published' : 'error');
    setTimeout(() => setPublishStatus('idle'), ok ? 3000 : 6000);
  };

  const handleReset = async () => {
    if (!confirm('Reset ALL content to defaults? This cannot be undone.')) return;
    await resetContent();
    window.location.reload();
  };

  return (
    <div
      className="fixed inset-0 z-[9999] flex h-screen w-screen overflow-hidden select-auto"
      data-lenis-prevent="true"
      onWheel={e => e.stopPropagation()}
      role="dialog"
      aria-modal="true"
      aria-label="CreativeFX content management"
    >
      {/* Backdrop */}
      <button
        type="button"
        className="hidden flex-1 cursor-default bg-gray-950/60 backdrop-blur-sm sm:block"
        onClick={onClose}
        aria-label="Close admin panel"
      />

      {/* Panel Container */}
      <div
        className="cfx-admin flex h-full max-h-screen w-full max-w-[1480px] overflow-hidden border-l border-gray-300 bg-gray-100 shadow-2xl"
        data-lenis-prevent="true"
        onWheel={e => e.stopPropagation()}
      >

        {/* Mobile drawer backdrop — only when the sidebar is open on small screens */}
        {sidebarOpen && (
          <div
            className="md:hidden absolute inset-0 z-20 bg-gray-900/40"
            onClick={() => setSidebarOpen(false)}
            aria-hidden="true"
          />
        )}

        {/* Sidebar — off-canvas drawer on mobile, static column on md+ */}
        <aside
          className={`absolute md:static inset-y-0 left-0 z-30 w-60 bg-white border-r border-gray-200 flex flex-col flex-shrink-0 h-full overflow-y-auto overscroll-contain transition-transform duration-300 md:translate-x-0 ${
            sidebarOpen ? 'translate-x-0 shadow-2xl' : '-translate-x-full'
          }`}
          data-lenis-prevent="true"
        >
          <div className="flex flex-shrink-0 items-center justify-between border-b border-gray-200 px-5 py-4">
            <div>
              <h2 className="text-sm font-semibold text-gray-900">CreativeFX</h2>
              <p className="mt-0.5 text-[11px] text-gray-500">Content management</p>
            </div>
            <button
              type="button"
              onClick={() => setSidebarOpen(false)}
              className="rounded-md p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-900 md:hidden"
              aria-label="Close navigation menu"
            >
              <X className="h-4 w-4" />
            </button>
          </div>
          <nav className="py-3 flex-1 overflow-y-auto">
            {MENU.map(menu => {
              const Icon = menu.icon;
              const catActive = activeCategory === menu.category;
              return (
                <div key={menu.category} className="mb-1">
                  <button
                    type="button"
                    onClick={() => { setActiveCategory(menu.category); setActiveItem(menu.items[0]); if (menu.items.length <= 1) setSidebarOpen(false); }}
                    className={`w-full flex items-center gap-2.5 px-5 py-2.5 text-sm transition-colors cursor-pointer ${
                      catActive ? 'text-gray-900 font-semibold bg-gray-100' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50'
                    }`}
                    aria-expanded={catActive && menu.items.length > 1}
                    aria-current={catActive && menu.items.length === 1 ? 'page' : undefined}
                  >
                    <Icon className={`w-4 h-4 ${catActive ? 'text-gray-900' : 'text-gray-400'}`} />
                    <span className="truncate">{menu.category}</span>
                  </button>
                  {catActive && menu.items.length > 1 && (
                    <div className="pb-2">
                      {menu.items.map(item => {
                        const isActive = activeItem === item;
                        return (
                          <button
                            key={item}
                            type="button"
                            onClick={() => { setActiveCategory(menu.category); setActiveItem(item); setSidebarOpen(false); }}
                            className={`w-full text-left pl-[46px] pr-5 py-2 text-[13px] transition-colors cursor-pointer ${
                              isActive
                                ? 'text-gray-900 font-medium bg-gray-50'
                                : 'text-gray-500 hover:text-gray-900'
                            }`}
                            aria-current={isActive ? 'page' : undefined}
                          >
                            {item}
                          </button>
                        );
                      })}
                    </div>
                  )}
                </div>
              );
            })}
          </nav>
        </aside>

        {/* Main Content Area */}
        <div className="flex-1 flex flex-col min-w-0 h-full max-h-screen overflow-hidden">
          {/* Header */}
          <header className="flex flex-shrink-0 items-center justify-between border-b border-gray-200 bg-white px-4 py-3.5 sm:px-8">
            <div className="flex items-center gap-2 min-w-0">
              <button
                type="button"
                onClick={() => setSidebarOpen(true)}
                title="Open menu"
                aria-label="Open menu"
                className="md:hidden p-2 -ml-2 text-gray-500 hover:text-gray-900 rounded-md transition-colors cursor-pointer flex-shrink-0"
              >
                <Menu className="w-5 h-5" />
              </button>
              <div className="min-w-0">
              <nav aria-label="Admin location" className="flex items-center gap-1.5 text-xs text-gray-400">
                <span>{activeCategory}</span>
                {activeCategory !== activeItem && (
                  <>
                    <span>/</span>
                    <span className="text-gray-700 font-medium">{activeItem}</span>
                  </>
                )}
              </nav>
              <p className="text-[11px] mt-0.5">
                {isSaving
                  ? <span className="text-blue-600">Saving to server…</span>
                  : publishStatus === 'error'
                    ? <span className="text-red-600 font-medium">Publish failed - changes are still local</span>
                  : hasDraft
                    ? <span className="text-amber-600 font-medium flex items-center gap-1"><AlertCircle className="w-3 h-3 inline" /> Waiting to save</span>
                    : lastSaved
                      ? <span className="text-green-600">✓ Saved to server · {lastSaved.toLocaleTimeString()}</span>
                      : <span className="text-gray-500">Server content is up to date</span>
                }
              </p>
              </div>
            </div>
            <div className="flex items-center gap-2">
              <button
                type="button"
                onClick={handleReset}
                title="Reset all content to defaults"
                className="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-md transition-colors cursor-pointer"
              >
                <RotateCcw className="w-4 h-4" />
              </button>
              <button
                type="button"
                onClick={onClose}
                title="Close panel"
                className="p-2 text-gray-400 hover:text-gray-900 hover:bg-gray-100 rounded-md transition-colors cursor-pointer"
              >
                <X className="w-4 h-4" />
              </button>
            </div>
          </header>

          {/* Content Scroll Area */}
          <main
            className="h-full flex-1 overflow-y-auto overscroll-contain px-4 py-5 select-auto sm:px-8 sm:py-6"
            data-lenis-prevent="true"
            onWheel={e => e.stopPropagation()}
            style={{ WebkitOverflowScrolling: 'touch' }}
          >
            <div className="mx-auto max-w-5xl space-y-6 pb-16">
              {/* 1. Dashboard */}
              {activeCategory === 'Dashboard' && (
                <DashboardSection
                  content={localContent}
                  onNavigate={(cat, itm) => { setActiveCategory(cat); setActiveItem(itm); }}
                />
              )}

              {/* 2. Homepage */}
              {activeCategory === 'Homepage' && (
                <HomepageSection content={localContent} update={update} />
              )}

              {/* 3. Works / Projects (master project database) */}
              {activeCategory === 'Works / Projects' && (
                <WorksSection item={activeItem} content={localContent} update={update} />
              )}

              {/* 4. Weddings experience */}
              {activeCategory === 'Weddings' && (
                <WeddingsAdmin tab={activeItem} content={localContent} update={update} />
              )}

              {/* 5. Services */}
              {activeCategory === 'Services' && (
                <ServicesAdmin content={localContent} update={update} />
              )}

              {/* 6. About */}
              {activeCategory === 'About' && (
                <AboutAdmin content={localContent} update={update} />
              )}

              {activeCategory === 'Creative Pool' && (
                <CreativePoolAdmin content={localContent} update={update} />
              )}

              {/* 7. Page-level copy */}
              {activeCategory === 'Pages' && (
                <PagesSection item={activeItem} content={localContent} update={update} />
              )}

              {/* 8. Media Library */}
              {activeCategory === 'Media Library' && (
                <MediaLibrarySection item={activeItem} content={localContent} update={update} />
              )}

              {/* 9. Navigation & Footer & Visibility */}
              {(activeCategory === 'Navigation & Footer') && (
                <WebsiteSection item={activeItem} content={localContent} update={update} />
              )}

              {/* 10. Contact / Inquiries */}
              {activeCategory === 'Contact / Inquiries' && (
                <ContactInquiriesSection content={localContent} update={update} />
              )}

              {/* 11. SEO */}
              {activeCategory === 'SEO' && (
                <SEOSection content={localContent} update={update} />
              )}

              {/* 12. Theme / Branding */}
              {activeCategory === 'Theme / Branding' && (
                <ThemeSection content={localContent} update={update} />
              )}

              {/* 13. Analytics */}
              {activeCategory === 'Analytics' && (
                <AnalyticsSection content={localContent} update={update} />
              )}

              {/* 14. Settings */}
              {activeCategory === 'Settings' && (
                <SettingsSection
                  content={localContent}
                  update={update}
                  resetContent={resetContent}
                />
              )}

              {/* 15. Admin Users */}
              {activeCategory === 'Admin Users' && (
                <AdminUsersSection />
              )}
            </div>
          </main>

          {/* Footer Publish Bar */}
          <footer className="z-10 flex flex-shrink-0 items-center justify-between gap-3 border-t border-gray-200 bg-white px-4 py-3 sm:px-8">
            <p className="hidden text-[11px] text-gray-500 sm:block">
              {hasDraft
                ? 'Saving changes to the shared website database…'
                : 'All devices receive the latest server content'}
            </p>
            <button
              type="button"
              onClick={handlePublish}
              disabled={publishStatus === 'publishing' || !hasDraft}
              title={hasDraft ? 'Save pending changes to the shared server now' : 'All changes are saved to the server'}
              className={`flex min-h-10 w-full items-center justify-center gap-2 rounded-md px-5 py-2 text-xs font-semibold text-white shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 disabled:opacity-40 sm:w-auto ${
                publishStatus === 'published'
                  ? 'bg-green-600 hover:bg-green-700'
                  : publishStatus === 'error'
                    ? 'bg-red-600 hover:bg-red-700'
                  : hasDraft
                    ? 'bg-green-600 hover:bg-green-700 animate-pulse'
                    : 'bg-gray-400 cursor-not-allowed'
              }`}
            >
              {publishStatus === 'publishing'
                ? <><Loader className="w-3.5 h-3.5 animate-spin" /> Saving…</>
                : publishStatus === 'published'
                  ? <><CheckCircle className="w-3.5 h-3.5" /> Saved!</>
                  : publishStatus === 'error'
                    ? <><AlertCircle className="w-3.5 h-3.5" /> Retry save</>
                  : <><Upload className="w-3.5 h-3.5" /> Save to Website</>
              }
            </button>
          </footer>
        </div>

      </div>
    </div>
  );
};
