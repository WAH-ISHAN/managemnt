(() => {
  const state={query:'',filter:'all',sort:'name',business:''};
  let dashboard=null, entries=[], timer=null, frame=null, sortedBy='';
  const normalize=value=>value.normalize('NFKC').toLocaleLowerCase().trim();
  function apply() {
    if (!dashboard?.isConnected) return;
    const now=Math.floor(Date.now()/1000);
    let signed=0, visible=0;
    for (const entry of entries) {
      const card=entry.card;
      if (card.dataset.status==='signed' && Number(card.dataset.expires)<=now) {
        card.dataset.status='signin';
        card.querySelector('[data-website-status-label]').textContent='Sign in required';
        card.querySelector('[data-website-context]').textContent='Session ended. Sign in to continue.';
        card.querySelector('[data-website-open-label]').textContent='Sign in to website';
        card.querySelector('.website-open').classList.add('secondary');
      }
      if (card.dataset.status==='signed') {
        signed++;
        const expiry=card.querySelector('[data-website-expiry]');
        if (expiry) expiry.textContent=`${Math.max(1,Math.ceil((Number(card.dataset.expires)-now)/60))} min left`;
      }
      const matches=(!state.query || entry.search.includes(normalize(state.query))) && (state.filter==='all' || card.dataset.status===state.filter) && (!state.business || card.dataset.business===state.business);
      card.hidden=!matches;
      if (matches) visible++;
    }
    dashboard.querySelector('[data-website-signed-count]').textContent=String(signed);
    if (sortedBy!==state.sort) {
      const sorted=[...entries].sort((a,b)=>{
        if (state.sort==='recent') { const order=b.recent.localeCompare(a.recent); if (order) return order; }
        if (state.sort==='setup') { const order=Number(b.card.dataset.status==='setup')-Number(a.card.dataset.status==='setup'); if (order) return order; }
        return a.name.localeCompare(b.name,undefined,{numeric:true,sensitivity:'base'});
      });
      const grid=dashboard.querySelector('#website-grid');
      if (grid) grid.append(...sorted.map(entry=>entry.card));
      sortedBy=state.sort;
    }
    dashboard.querySelectorAll('[data-website-filter]').forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.websiteFilter===state.filter)));
    const count=dashboard.querySelector('[data-website-results]');
    if (count) {
      const text=`${visible} of ${entries.length} website${entries.length===1 ? '' : 's'}`;
      if (count.textContent!==text) count.textContent=text;
    }
    const empty=dashboard.querySelector('[data-website-no-results]');
    if (empty) empty.hidden=visible!==0;
  }
  function init() {
    clearInterval(timer); timer=null;
    dashboard=document.querySelector('[data-website-dashboard]');
    entries=[]; sortedBy='';
    if (!dashboard) return;
    entries=[...dashboard.querySelectorAll('[data-website-card]')].map(card=>({card,search:normalize(card.dataset.search),name:card.dataset.siteName,recent:card.dataset.recent}));
    const search=dashboard.querySelector('[data-website-search]');
    const sort=dashboard.querySelector('[data-website-sort]');
    const business=dashboard.querySelector('[data-website-business]');
    if (business) {
      if (![...business.options].some(option=>option.value===state.business)) state.business='';
      business.value=state.business;
    }
    if (search) search.value=state.query;
    if (sort) sort.value=state.sort;
    const controls=dashboard.querySelector('[data-website-controls]');
    if (controls) controls.hidden=false;
    apply();
    if (entries.length) timer=setInterval(()=>{ if (!document.hidden) apply(); },30000);
  }
  document.addEventListener('input',event=>{
    if (!event.target.matches('[data-website-search]')) return;
    state.query=event.target.value;
    if (frame===null) frame=requestAnimationFrame(()=>{frame=null;apply();});
  });
  document.addEventListener('change',event=>{
    if (event.target.matches('[data-website-business]')) { state.business=event.target.value; apply(); return; }
    if (!event.target.matches('[data-website-sort]')) return;
    state.sort=event.target.value; apply();
  });
  document.addEventListener('click',event=>{
    const toggle=event.target.closest('[data-website-password]');
    if (toggle) {
      const input=toggle.parentElement.querySelector('input');
      const show=input.type==='password'; input.type=show ? 'text' : 'password';
      toggle.textContent=show ? 'Hide' : 'Show';
      toggle.setAttribute('aria-label',show ? 'Hide password' : 'Show password');
      toggle.setAttribute('aria-pressed',String(show));
      return;
    }
    const add=event.target.closest('[data-website-add]');
    if (add) {
      const section=document.getElementById('new-website');
      if (section) {
        event.preventDefault(); section.open=true;
        section.scrollIntoView({behavior:matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth',block:'start'});
        section.querySelector('input[name=name]')?.focus({preventScroll:true});
      }
      return;
    }
    const filter=event.target.closest('[data-website-filter]');
    if (filter) { state.filter=filter.dataset.websiteFilter; apply(); }
    if (event.target.closest('[data-website-clear]')) {
      state.query=''; state.filter='all'; state.business='';
      const business=dashboard?.querySelector('[data-website-business]');
      if (business) business.value='';
      const search=dashboard?.querySelector('[data-website-search]');
      if (search) {search.value='';search.focus();}
      apply();
    }
  });
  document.addEventListener('visibilitychange',()=>{ if (!document.hidden) apply(); });
  document.addEventListener('htmx:afterSwap',init);
  document.addEventListener('htmx:beforeCleanupElement',event=>{
    if (event.detail.elt===dashboard || event.detail.elt?.contains(dashboard)) {clearInterval(timer);timer=null;}
  });
  init();
})();
