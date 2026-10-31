(() => {
  const narrowMenu=matchMedia('(max-width: 900px)');
  const pendingForms=new Map(), pageRequests=new Set(), requestForms=new Map();
  let loadingDelay=null, totalsFrame=null;
  function releaseForm(form) {
    const pending=pendingForms.get(form);
    if (!pending) return;
    if (pending.busy===null) form.removeAttribute('aria-busy'); else form.setAttribute('aria-busy',pending.busy);
    for (const [button,value] of pending.buttons) {
      if (value===null) button.removeAttribute('aria-disabled'); else button.setAttribute('aria-disabled',value);
    }
    delete form.dataset.submitting;
    pendingForms.delete(form);
  }
  function holdForm(form) {
    if (pendingForms.has(form)) return;
    const buttons=[...form.querySelectorAll('button:not([type]),button[type=submit],input[type=submit],input[type=image]')].map(button=>[button,button.getAttribute('aria-disabled')]);
    const pending={busy:form.getAttribute('aria-busy'),buttons};
    pendingForms.set(form,pending);
    form.dataset.submitting='true'; form.setAttribute('aria-busy','true');
    for (const [button] of buttons) button.setAttribute('aria-disabled','true');
  }
  function clearPageLoading() {
    clearTimeout(loadingDelay); loadingDelay=null;
    delete document.documentElement.dataset.pageLoading;
    document.getElementById('main')?.removeAttribute('aria-busy');
  }
  function finishRequest(event) {
    const xhr=event.detail?.xhr;
    const form=requestForms.get(xhr);
    if (form) { releaseForm(form); requestForms.delete(xhr); }
    pageRequests.delete(xhr);
    if (!pageRequests.size) clearPageLoading();
  }
  document.addEventListener('htmx:beforeRequest',event=>{
    if (event.defaultPrevented) return;
    const {xhr,elt,target,requestConfig}=event.detail;
    // Background previews can post from inside a form; they must not lock Save.
    const form=elt?.matches('form') ? elt : null;
    if (form && requestConfig?.verb==='post') {
      holdForm(form);
      requestForms.set(xhr,form);
    }
    if (target?.id==='main') {
      pageRequests.add(xhr); target.setAttribute('aria-busy','true');
      if (loadingDelay===null) loadingDelay=setTimeout(()=>{
        if (pageRequests.size) document.documentElement.dataset.pageLoading='true';
      },120);
    }
    // Another handler may cancel this request after ours.
    queueMicrotask(()=>{ if (event.defaultPrevented) finishRequest(event); });
  });
  for (const name of ['htmx:afterRequest','htmx:sendAbort','htmx:sendError','htmx:timeout','htmx:onLoadError']) document.addEventListener(name,finishRequest);
  window.addEventListener('pageshow',()=>{
    for (const form of pendingForms.keys()) releaseForm(form);
    requestForms.clear(); pageRequests.clear(); clearPageLoading();
  });
  let menuOpener=null;
  function menu(open, restore=false) {
    const sidebar=document.querySelector('.sidebar');
    if (!sidebar) return;
    const visible=narrowMenu.matches && open;
    document.documentElement.dataset.menuOpen=String(visible);
    sidebar.inert=narrowMenu.matches && !visible;
    if (visible) { sidebar.setAttribute('role','dialog'); sidebar.setAttribute('aria-modal','true'); }
    else { sidebar.removeAttribute('role'); sidebar.removeAttribute('aria-modal'); }
    document.querySelector('[data-menu-toggle]')?.setAttribute('aria-expanded',String(visible));
    const backdrop=document.querySelector('.nav-backdrop');
    if (backdrop) backdrop.hidden=!visible;
    if (visible) { menuOpener=document.activeElement; (sidebar.querySelector('.nav-link.active') || sidebar.querySelector('a'))?.focus(); }
    else if (restore) menuOpener?.focus();
  }
  function dateShortcuts() {
    const today=document.querySelector('meta[name="lifeos-today"]')?.content;
    if (!today) return;
    document.querySelectorAll('input[type=date],input[type=month]').forEach(input=>{
      if (input.dataset.dateShortcuts || input.dataset.dateSuggestions==='off' || !input.closest('label')) return;
      input.dataset.dateShortcuts='ready';
      const future=/due|deadline|target/.test(input.name) || /\/uni\/exam/.test(input.form?.getAttribute('action') || '');
      const choices=input.type==='month' ? [['This month',0],['Next month',1]] : (future ? [['Today',0],['Tomorrow',1],['In a week',7]] : [['Today',0],['Yesterday',-1]]);
      const group=document.createElement('span'); group.className='date-shortcuts'; group.setAttribute('aria-label','Suggested dates');
      for (const [label,offset] of choices) {
        const button=document.createElement('button'); button.type='button'; button.textContent=label;
        const day=new Date(today+'T12:00:00Z');
        if (input.type==='month') { day.setUTCDate(1); day.setUTCMonth(day.getUTCMonth()+offset); } else day.setUTCDate(day.getUTCDate()+offset);
        const value=day.toISOString().slice(0,input.type==='month' ? 7 : 10);
        if ((input.min && value<input.min) || (input.max && value>input.max)) continue;
        button.setAttribute('aria-label',label+': '+value);
        button.addEventListener('click',()=>{ input.value=value; input.dispatchEvent(new Event('input',{bubbles:true})); input.dispatchEvent(new Event('change',{bubbles:true})); input.focus(); });
        group.append(button);
      }
      input.after(group);
    });
  }
  function focusDestination() {
    if (!location.hash) return false;
    let target;
    try { target=document.getElementById(decodeURIComponent(location.hash.slice(1))); } catch (_) { return false; }
    if (!target) return false;
    target.scrollIntoView({block:'start'});
    (target.querySelector('input[name=amount]') || target.querySelector('input:not([type=hidden]),select,textarea'))?.focus({preventScroll:true});
    return true;
  }
  function update() {
    dateShortcuts();
    document.querySelectorAll('canvas[data-chart]').forEach(canvas => {
      if (!window.Chart || Chart.getChart(canvas)) return;
      const config = JSON.parse(canvas.dataset.chart);
      const preference=document.documentElement.dataset.theme || 'system';
      const dark = preference==='dark' || (preference==='system' && matchMedia('(prefers-color-scheme: dark)').matches);
      Chart.defaults.color = dark ? '#aaaab1' : '#64646b';
      Chart.defaults.borderColor = dark ? 'rgba(164,189,225,.10)' : 'rgba(70,94,135,.09)';
      styleChart(config,canvas,dark);
      new Chart(canvas, config);
    });
    const path = location.pathname;
    document.querySelectorAll('.nav-link').forEach(a => {
      const href=a.getAttribute('href');
      const active = href==='/' ? path==='/' : path.startsWith(href);
      a.classList.toggle('active', active);
      if (active) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
      if (active && a.closest('.nav-more')) a.closest('.nav-more').open=true;
    });
    const label=document.querySelector('.theme-name');
    if (label) label.textContent=({system:'System',dark:'Dark',light:'Light'})[document.documentElement.dataset.theme || 'system'];
    const toolbar=document.getElementById('toolbar-title');
    if (toolbar) toolbar.textContent=document.title.replace(' · LifeOS','').replace(/^Overview$/,'Today');
    updateRuleTotal();
  }
  function styleChart(config,canvas,dark) {
    const reduced=matchMedia('(prefers-reduced-motion: reduce)').matches;
    const options=config.options ||= {};
    options.animation=reduced ? false : (options.animation===false ? false : {duration:720,easing:'easeOutQuart',...options.animation});
    options.interaction={mode:'index',intersect:false,...options.interaction};
    options.layout={padding:{top:12,right:8,bottom:0,left:0},...options.layout};
    options.plugins ||= {};
    const cashflowDepth=config.type==='bar' && options.plugins['lifeos-cashflow-depth']?.enabled && window.LifeOSCashflow3D;
    if (cashflowDepth) {
      config.plugins=[...(config.plugins || []),window.LifeOSCashflow3D];
      options.plugins['lifeos-cashflow-depth'].dark=dark;
      options.layout.padding={top:24,right:22,bottom:0,left:0};
    }
    const legend=options.plugins.legend ||= {};
    legend.labels={usePointStyle:true,pointStyle:'circle',boxWidth:7,boxHeight:7,padding:20,font:{size:11},...legend.labels};
    options.plugins.tooltip={backgroundColor:dark ? '#e9f0fc' : '#19243b',titleColor:dark ? '#19243b' : '#fff',bodyColor:dark ? '#33435e' : '#e4ecfa',padding:13,cornerRadius:12,displayColors:true,boxPadding:5,...options.plugins.tooltip};
    const radial=['doughnut','pie','polarArea','radar'].includes(config.type);
    if (!radial) {
      options.scales ||= {};
      options.scales.x={...options.scales.x,grid:{display:false,...options.scales.x?.grid},border:{display:false,...options.scales.x?.border},ticks:{maxRotation:0,padding:9,font:{size:10},...options.scales.x?.ticks}};
      options.scales.y={...options.scales.y,grid:{color:dark ? 'rgba(164,189,225,.10)' : 'rgba(70,94,135,.09)',drawTicks:false,...options.scales.y?.grid},border:{display:false,dash:[4,5],...options.scales.y?.border},ticks:{padding:10,maxTicksLimit:5,font:{size:10},...options.scales.y?.ticks}};
    }
    const colors=dark ? ['#7eb5ff','#f3b06a','#6ed2b2','#b6a0ff'] : ['#2879e9','#d88644','#2a9b80','#8e71da'];
    const aliases={'#0071e3':colors[0],'#bf6b22':colors[1],'#247541':colors[2],'#9faec3':dark ? '#9daec8' : '#a0afc5'};
    config.data.datasets.forEach((dataset,index)=>{
      const original=dataset.borderColor || dataset.backgroundColor;
      const color=cashflowDepth ? colors[index%colors.length] : (aliases[original] || original || colors[index%colors.length]);
      if (typeof color!=='string') return;
      if (config.type==='line') {
        dataset.borderColor=color; dataset.borderWidth=2.5; dataset.pointRadius=2; dataset.pointHoverRadius=5;
        dataset.pointBackgroundColor=color; dataset.pointBorderWidth=0; dataset.tension=.35;
        dataset.fill=true;
        dataset.backgroundColor=context=>{
          const area=context.chart.chartArea;
          if (!area || !/^#[\da-f]{6}$/i.test(color)) return color;
          const gradient=context.chart.ctx.createLinearGradient(0,area.top,0,area.bottom);
          gradient.addColorStop(0,color+'42'); gradient.addColorStop(1,color+'00'); return gradient;
        };
      } else if (config.type==='bar') {
        dataset.backgroundColor=context=>{
          const area=context.chart.chartArea;
          if (!area || !/^#[\da-f]{6}$/i.test(color)) return color;
          const gradient=context.chart.ctx.createLinearGradient(0,area.top,0,area.bottom);
          gradient.addColorStop(0,color); gradient.addColorStop(1,color+'a0'); return gradient;
        };
        dataset.borderWidth=0; dataset.borderRadius=7; dataset.maxBarThickness=34;
        dataset.categoryPercentage=.65; dataset.barPercentage=.8;
        if (cashflowDepth) {dataset.lifeosDepthColor=color;dataset.borderRadius=0;dataset.barPercentage=.7;}
      }
    });
  }
  function updateRuleTotal() {
    const total = document.getElementById('rule-total');
    if (total) {
      const form = total.closest('form');
      let sum = 0;
      form.querySelectorAll('tbody tr').forEach(row => {
        if (row.querySelector('[type=checkbox]').checked && row.querySelector('input').value.trim()) sum += Number(row.querySelector('[type=number]').value) || 0;
      });
      total.textContent = `Active total: ${sum.toFixed(2)}% · ${Math.max(0,100-sum).toFixed(2)}% unallocated`;
      total.classList.toggle('error-text', sum > 100.00001);
    }
  }
  document.addEventListener('submit', event => {
    const form=event.target;
    if (pendingForms.has(form)) { event.preventDefault(); event.stopImmediatePropagation(); return; }
    const message = event.target.dataset.confirm;
    if (message && !window.confirm(message)) { event.preventDefault(); event.stopImmediatePropagation(); return; }
  }, true);
  document.addEventListener('input',event=>{
    const form=document.getElementById('rule-total')?.closest('form');
    if (!form?.contains(event.target) || totalsFrame!==null) return;
    totalsFrame=requestAnimationFrame(()=>{ totalsFrame=null; updateRuleTotal(); });
  });
  const commands={
    openCommands() { menu(false); document.getElementById('quick-add-dialog')?.close(); const dialog=document.getElementById('command-dialog'); if (dialog && !dialog.open) { dialog.showModal(); document.getElementById('command-search').focus(); } },
    openQuickAdd() { menu(false); document.getElementById('command-dialog')?.close(); const dialog=document.getElementById('quick-add-dialog'); if (dialog && !dialog.open) dialog.showModal(); },
    go(path) { if (typeof path==='string' && path.startsWith('/') && !path.startsWith('//')) location.assign(path); }
  };
  window.LifeOS=commands;
  document.addEventListener('click', event => {
    if (event.target.closest('[data-print]')) window.print();
    if (event.target.closest('[data-command-open]')) { event.preventDefault(); commands.openCommands(); }
    if (event.target.closest('[data-quick-add]')) commands.openQuickAdd();
    if (event.target.closest('[data-dialog-close]')) event.target.closest('dialog')?.close();
    if (event.target.closest('[data-quick-result]')) document.getElementById('quick-add-dialog')?.close();
    if (event.target.closest('[data-menu-toggle]')) menu(document.documentElement.dataset.menuOpen!=='true');
    if (event.target.closest('[data-menu-close]')) menu(false,true);
    if (event.target.closest('.sidebar .nav-link,.sidebar .brand') && !event.target.closest('.nav-more')) { const more=document.querySelector('.nav-more'); if (more) more.open=false; }
    if (event.target.closest('.sidebar .nav-link,.sidebar .brand') && narrowMenu.matches) menu(false);
    if (event.target.closest('[data-command-close]')) document.getElementById('command-dialog')?.close();
    if (event.target.closest('[data-command-result]')) document.getElementById('command-dialog')?.close();
    if (event.target.closest('[data-theme-toggle]')) {
      const choices=['system','light','dark']; const next=choices[(choices.indexOf(document.documentElement.dataset.theme || 'system')+1)%3]; document.documentElement.dataset.theme=next;
      try { localStorage.setItem('lifeos-theme',next); } catch (_) {}
      if (window.Chart) document.querySelectorAll('canvas[data-chart]').forEach(c=>Chart.getChart(c)?.destroy());
      update();
    }
  });
  document.addEventListener('input',event=>{
    if (event.target.id==='command-search') {
      let visible=0;
      document.querySelectorAll('[data-command-result]').forEach(a=>{ a.hidden=!(a.textContent+' '+(a.dataset.search || '')).toLowerCase().includes(event.target.value.toLowerCase().trim()); if (!a.hidden) visible++; });
      const status=document.getElementById('command-search-status');
      if (status) status.textContent=visible ? '' : 'No matching pages. Try a different search.';
    }
  });
  document.addEventListener('keydown',event=>{
    if (narrowMenu.matches && document.documentElement.dataset.menuOpen==='true') {
      if (event.key==='Escape') { event.preventDefault(); menu(false,true); return; }
      if (event.key==='Tab') {
        const controls=[...document.querySelectorAll('.sidebar a,.sidebar button,.sidebar summary')].filter(e=>e.getClientRects().length);
        const first=controls[0], last=controls[controls.length-1];
        if (event.shiftKey && document.activeElement===first) { event.preventDefault(); last?.focus(); }
        else if (!event.shiftKey && document.activeElement===last) { event.preventDefault(); first?.focus(); }
      }
    }
    if (event.target.id==='command-search' && event.key==='Enter') {
      const result=document.querySelector('[data-command-result]:not([hidden])');
      if (result) { event.preventDefault(); document.getElementById('command-dialog').close(); result.click(); }
      return;
    }
    if (!event.ctrlKey || event.altKey) return;
    if (event.key.toLowerCase()==='k') { event.preventDefault(); commands.openCommands(); }
    else if (event.key.toLowerCase()==='n') { event.preventDefault(); commands.go('/planner?view=tasks#new-task'); }
    else if (event.shiftKey && event.key.toLowerCase()==='f') { event.preventDefault(); commands.go('/focus'); }
    else if (/^[1-9]$/.test(event.key)) { const link=document.querySelectorAll('.sidebar .nav-link')[Number(event.key)-1]; if (link) { event.preventDefault(); document.getElementById('command-dialog')?.close(); link.click(); } }
  });
  let lastTimerAlert=null;
  setInterval(()=>{
    const timer=document.querySelector('[data-focus-timer]'); if (!timer) return;
    const elapsed=Number(timer.dataset.elapsed)+(timer.dataset.status==='running' ? Math.max(0,Date.now()/1000-Number(timer.dataset.rendered)) : 0);
    const left=Math.max(0,Math.ceil(Number(timer.dataset.total)-elapsed));
    timer.textContent=String(Math.floor(left/60)).padStart(2,'0')+':'+String(left%60).padStart(2,'0');
    if (left===0 && timer!==lastTimerAlert) { lastTimerAlert=timer; const state=document.querySelector('[data-focus-state]'); if(state) state.textContent='Block complete. Save your session, then take a break.'; pollAlerts(); }
  },1000);
  let alertsInFlight=false, alertsRefreshQueued=false;
  async function pollAlerts() {
    if (!document.querySelector('.app-shell')) return;
    if (alertsInFlight) { alertsRefreshQueued=true; return; }
    alertsInFlight=true;
    try {
      do {
        alertsRefreshQueued=false;
        const response=await fetch('/insights/alerts',{headers:{Accept:'application/json'}});
        if (!response.ok || !response.headers.get('Content-Type')?.includes('application/json')) break;
        const result=await response.json(); const unread=Array.isArray(result.alerts) ? result.alerts.filter(alert=>!alert.read) : [];
        const badge=document.getElementById('alert-count');
        if (badge) { const count=Number.isInteger(result.unreadCount) ? result.unreadCount : unread.length; badge.textContent=String(count); badge.hidden=count===0; }
        if (window.chrome?.webview) window.chrome.webview.postMessage({type:'lifeos-alerts',enabled:result.enabled,alerts:unread});
        window.dispatchEvent(new CustomEvent('lifeos:alerts',{detail:result}));
      } while (alertsRefreshQueued);
    } catch (_) {} finally { alertsInFlight=false; }
  }
  window.addEventListener('lifeos:alerts-refresh',pollAlerts);
  setInterval(pollAlerts,60000);
  document.addEventListener('htmx:beforeCleanupElement', event => {
    const element=event.detail.elt;
    if (window.Chart) {
      if (element.matches('canvas[data-chart]')) Chart.getChart(element)?.destroy();
      element.querySelectorAll('canvas[data-chart]').forEach(canvas => Chart.getChart(canvas)?.destroy());
    }
  });
  document.addEventListener('toggle',event=>{
    if (event.target.tagName==='DETAILS' && event.target.open && window.Chart) requestAnimationFrame(()=>event.target.querySelectorAll('canvas[data-chart]').forEach(canvas=>Chart.getChart(canvas)?.resize()));
  },true);
  document.addEventListener('htmx:afterSettle', event => {
    update();
    if (event.detail.elt?.id === 'main' && !focusDestination()) document.getElementById('main')?.focus({ preventScroll: true });
  });
  document.addEventListener('htmx:beforeSwap', event => {
    if (event.detail.xhr.status === 422) { event.detail.shouldSwap = true; event.detail.isError = false; }
  });
  document.addEventListener('htmx:sendError', () => {
    const main = document.getElementById('main');
    if (!main || main.querySelector('[data-connection-error]')) return;
    const notice = document.createElement('p'); notice.dataset.connectionError = ''; notice.className = 'notice warning'; notice.setAttribute('role','alert');
    notice.textContent = 'LifeOS could not connect. Restart the app, then try again.'; main.prepend(notice);
  });
  update();
  menu(false);
  narrowMenu.addEventListener('change',()=>menu(false));
  requestAnimationFrame(focusDestination);
  pollAlerts();
  const bridge = window.chrome?.webview;
  if (bridge) {
    window.LifeOSUpdate = {
      check: () => bridge.postMessage({ type: 'lifeos-update', action: 'check' }),
      apply: () => bridge.postMessage({ type: 'lifeos-update', action: 'apply' }),
      state: () => bridge.postMessage({ type: 'lifeos-update', action: 'state' }),
    };
    bridge.addEventListener('message', event => {
      const data = event.data;
      if (!data || data.type !== 'lifeos-update-state') return;
      const statusEl = document.getElementById('app-update-status');
      const checkBtn = document.getElementById('app-update-check');
      const applyBtn = document.getElementById('app-update-apply');
      if (statusEl) {
        statusEl.textContent = data.message || (data.ready ? 'Update ready to install.' : 'Up to date.');
      }
      if (checkBtn) {
        checkBtn.disabled = !!data.busy;
        checkBtn.textContent = data.busy ? 'Checking…' : 'Check for updates';
      }
      if (applyBtn) {
        applyBtn.hidden = !data.ready;
      }
      let banner = document.getElementById('lifeos-update-banner');
      if (data.ready) {
        if (!banner) {
          banner = document.createElement('aside');
          banner.id = 'lifeos-update-banner';
          banner.className = 'notification-toast update-toast';
          banner.setAttribute('role', 'alert');
          banner.innerHTML = '<div><strong>Update Ready</strong><p>LifeOS ' + (data.availableVersion || 'new version') + ' is ready to install.</p><button type="button" class="btn" id="update-banner-apply" style="min-height:30px;padding:4px 12px;font-size:11px;margin-top:6px;">Restart &amp; Update</button></div><button type="button" class="quiet" id="update-banner-close" aria-label="Dismiss">×</button>';
          document.body.appendChild(banner);
          document.getElementById('update-banner-apply')?.addEventListener('click', () => {
            bridge.postMessage({ type: 'lifeos-update', action: 'apply' });
          });
          document.getElementById('update-banner-close')?.addEventListener('click', () => {
            banner.hidden = true;
          });
        }
        banner.hidden = false;
      } else if (banner) {
        banner.hidden = true;
      }
    });
    bridge.postMessage({ type: 'lifeos-update', action: 'state' });
    document.addEventListener('htmx:afterSwap', () => {
      bridge.postMessage({ type: 'lifeos-update', action: 'state' });
    });
  }
  matchMedia('(prefers-color-scheme: dark)').addEventListener('change',()=>{
    if ((document.documentElement.dataset.theme || 'system')!=='system') return;
    if (window.Chart) document.querySelectorAll('canvas[data-chart]').forEach(c=>Chart.getChart(c)?.destroy());
    update();
  });
  matchMedia('(prefers-reduced-motion: reduce)').addEventListener('change',event=>{
    if (!window.Chart) return;
    document.querySelectorAll('canvas[data-chart]').forEach(canvas=>{
      const instance=Chart.getChart(canvas); if (!instance) return;
      instance.stop(); instance.options.animation=event.matches ? false : {duration:720,easing:'easeOutQuart'};
      instance.update('none');
    });
  });
})();
