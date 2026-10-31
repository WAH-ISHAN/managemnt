(() => {
  const bridge=window.chrome?.webview;
  if (!bridge || document.getElementById('desktop-titlebar')) return;
  const systemTheme=matchMedia('(prefers-color-scheme: dark)');
  const send=action=>{
    const message={type:'lifeos-window',action};
    if (action==='state') {
      const theme=document.documentElement.dataset.theme;
      message.dark=theme==='dark' || (theme!=='light' && systemTheme.matches);
    }
    bridge.postMessage(message);
  };
  const bar=document.createElement('header');
  bar.id='desktop-titlebar';
  bar.className='window-chrome';
  bar.setAttribute('aria-label','LifeOS window');
  bar.innerHTML='<div class="window-controls" role="group" aria-label="Window controls">'+
    '<button type="button" class="window-control window-close" data-window-action="close" aria-label="Close LifeOS" title="Close LifeOS"><span class="window-control-dot"><svg viewBox="0 0 10 10" aria-hidden="true"><path d="m3 3 4 4m0-4L3 7"/></svg></span></button>'+
    '<button type="button" class="window-control window-minimize" data-window-action="minimize" aria-label="Minimize LifeOS" title="Minimize LifeOS"><span class="window-control-dot"><svg viewBox="0 0 10 10" aria-hidden="true"><path d="M2.5 5h5"/></svg></span></button>'+
    '<button type="button" class="window-control window-maximize" data-window-action="maximize" aria-label="Maximize LifeOS" title="Maximize LifeOS"><span class="window-control-dot"><svg viewBox="0 0 10 10" aria-hidden="true"><path d="M2.5 6.5v-4h4m1 1v4h-4"/></svg></span></button></div>'+
    '<div class="window-chrome-brand" aria-hidden="true"><img src="/assets/brand/lifeos-mark.png" alt="" width="22" height="22" draggable="false"><span>LifeOS</span></div>'+
    '<div class="window-chrome-status" aria-hidden="true"><span></span>Local workspace</div>';
  document.body.prepend(bar);
  document.documentElement.dataset.desktopChrome='true';
  // Resize inside the WebView instead of exposing a painted native padding ring.
  const grips=['n','ne','e','se','s','sw','w','nw'].map(edge=>{
    const grip=document.createElement('div');
    grip.className='window-resize-grip';
    grip.dataset.resizeEdge=edge;
    grip.setAttribute('aria-hidden','true');
    grip.addEventListener('pointerdown',event=>{
      if (event.button!==0 || document.documentElement.dataset.windowMaximized==='true') return;
      event.preventDefault();
      event.stopPropagation();
      bridge.postMessage({type:'lifeos-window',action:'resize',edge});
    });
    document.body.append(grip);
    return grip;
  });
  // Modal dialogs make the rest of the document inert. Keep the window header
  // and resize grips inside the active modal so native controls remain usable.
  const syncModal=()=>{
    const active=[...document.querySelectorAll('dialog[open]')].find(dialog=>dialog.matches(':modal'));
    const parent=active || document.body;
    if (bar.parentNode!==parent) parent.prepend(bar);
    for (const grip of grips) if (grip.parentNode!==parent) parent.append(grip);
  };
  for (const dialog of document.querySelectorAll('dialog')) {
    new MutationObserver(syncModal).observe(dialog,{attributes:true,attributeFilter:['open']});
  }

  bar.addEventListener('click',event=>{
    const button=event.target.closest('[data-window-action]');
    if (button && bar.contains(button)) send(button.dataset.windowAction);
  });
  // Start the native move loop only after a small movement. Simple clicks remain
  // browser clicks, which lets a normal double-click maximize the window.
  let drag=null;
  bar.addEventListener('pointerdown',event=>{
    if (event.button!==0 || event.target.closest('button')) return;
    drag={id:event.pointerId,x:event.clientX,y:event.clientY};
  });
  bar.addEventListener('pointermove',event=>{
    if (!drag || drag.id!==event.pointerId) return;
    if (!(event.buttons&1)) { drag=null; return; }
    if (Math.hypot(event.clientX-drag.x,event.clientY-drag.y)<4) return;
    drag=null;
    send('drag');
  });
  const release=()=>{drag=null;};
  window.addEventListener('pointerup',release);
  window.addEventListener('pointercancel',release);
  window.addEventListener('blur',release);
  bar.addEventListener('dblclick',event=>{
    if (event.button!==0 || event.target.closest('button')) return;
    drag=null;
    send('maximize');
  });
  bar.addEventListener('contextmenu',event=>{
    event.preventDefault();
    if (!event.target.closest('button')) send('menu');
  });
  bridge.addEventListener('message',event=>{
    const state=event.data;
    if (state?.type!=='lifeos-window-state' || typeof state.maximized!=='boolean') return;
    document.documentElement.dataset.windowMaximized=String(state.maximized);
    const button=bar.querySelector('.window-maximize');
    const label=state.maximized ? 'Restore LifeOS window' : 'Maximize LifeOS';
    button.setAttribute('aria-label',label);
    button.title=label;
    const close=bar.querySelector('.window-close');
    const closeLabel=state.resident ? 'Hide LifeOS to system tray' : 'Close LifeOS';
    close.setAttribute('aria-label',closeLabel);close.title=closeLabel;
  });
  new MutationObserver(()=>send('state')).observe(document.documentElement,{attributes:true,attributeFilter:['data-theme']});
  systemTheme.addEventListener('change',()=>send('state'));
  send('state');
})();
