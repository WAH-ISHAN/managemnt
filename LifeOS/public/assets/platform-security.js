(() => {
 if(window.__platformSecurity)return;window.__platformSecurity=true;
 let active=false,last=Date.now();for(const name of ['pointerdown','keydown','wheel'])document.addEventListener(name,()=>{active=true;last=Date.now()},{passive:true});
 setInterval(()=>{const seconds=Number(document.querySelector('[data-platform-idle]')?.dataset.platformIdle||0);if(seconds>0&&Date.now()-last>=seconds*1000)location.href='/idle-lock';},1000);
 setInterval(()=>{if(!active||!document.querySelector('[data-platform-idle]'))return;active=false;const csrf=document.querySelector('input[name="_csrf"]')?.value;if(csrf)fetch('/session/activity',{method:'POST',body:new URLSearchParams({_csrf:csrf}),redirect:'error'}).catch(()=>{});},15000);
 document.addEventListener('click',e=>{const b=e.target.closest('[data-windows-hello]');if(!b)return;if(!window.chrome?.webview){document.querySelector('[data-hello-status]').textContent=window.lifeosT('Windows Hello is available in the LifeOS Windows app. Use your PIN here.');return;}window.chrome.webview.postMessage({type:'lifeos-hello',challenge:b.dataset.challenge,profile:b.dataset.profile});});
})();
