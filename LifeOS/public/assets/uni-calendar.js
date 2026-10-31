(() => {
  let config = null;
  let configuring = false;
  const desktop = !!window.chrome?.webview;
  const status = message => document.querySelectorAll('[data-nibm-status]').forEach(el => { el.textContent = message; });
  async function configure(force = false) {
    if (!desktop || configuring) return;
    if (!document.querySelector('.app-shell')) { config = null; window.chrome.webview.postMessage({type:'lifeos-nibm',action:'configure',enabled:false,batchId:''}); return; }
    if (config && !force) return;
    configuring = true;
    try {
      const response = await fetch('/uni/calendar/config', { cache:'no-store', headers: {Accept:'application/json'} });
      if (!response.ok || !response.headers.get('Content-Type')?.includes('application/json')) return;
      config = await response.json();
      window.chrome.webview.postMessage({type:'lifeos-nibm',action:'configure',enabled:config.enabled,batchId:config.batchId});
      document.querySelectorAll('[data-nibm-desktop-note]').forEach(el => { el.textContent='Your NIBM session is saved on this PC and refreshed automatically when possible. Saved lectures remain available offline. Sync resumes when LifeOS is open and unlocked.'; });
    } catch (_) {} finally { configuring = false; }
  }
  document.addEventListener('click', async event => {
    const button = event.target.closest('[data-nibm-action]');
    if (!button) return;
    if (!desktop) { status('Open the LifeOS Windows app to sign in and sync NIBM.'); return; }
    await configure(true);
    if (!config?.enabled) return;
    status(button.dataset.nibmAction==='open' ? 'Opening NIBM sign-in…' : 'Checking your NIBM calendar…');
    window.chrome.webview.postMessage({type:'lifeos-nibm',action:button.dataset.nibmAction,batchId:config.batchId});
  });
  if (desktop) window.chrome.webview.addEventListener('message', event => {
    if (event.data?.type!=='lifeos-nibm-state' || typeof event.data.message!=='string') return;
    status(event.data.message);
    if (event.data.status==='synced' && location.pathname==='/uni/calendar' && document.getElementById('uni-calendar-events')) {
      window.htmx?.ajax('GET',location.pathname+location.search,{target:'#uni-calendar-events',select:'#uni-calendar-events',swap:'outerHTML'});
    }
    if (event.data.status==='synced') window.dispatchEvent(new CustomEvent('lifeos:alerts-refresh'));
  });
  document.addEventListener('htmx:afterSettle', event => {
    if (event.detail?.elt?.id === 'main') configure(location.pathname === '/uni/calendar');
  });
  configure();
})();
