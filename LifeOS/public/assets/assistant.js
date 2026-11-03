(() => {
 let poll;
 function sync(){
  clearTimeout(poll);
  const state=document.querySelector('[data-ai-job-id]');
  const bridge=window.chrome?.webview;
  const desktop=document.querySelector('[data-ai-desktop-missing]');if(desktop)desktop.hidden=!!bridge;
  if(!state||!bridge)return;
  const id=state.dataset.aiJobId;
  async function check(){
   if(!state.isConnected)return;
   try{
    const response=await fetch('/intelligence/chat/'+id,{headers:{Accept:'application/json'},cache:'no-store'});
    if(!response.ok)return;const job=await response.json();
    if(job.status==='complete'||job.status==='failed'){location.reload();return;}
    if(job.status==='queued')bridge.postMessage({type:'lifeos-ai-job',id});
   }catch{}poll=setTimeout(check,2000);
  }
  bridge.postMessage({type:'lifeos-ai-job',id});poll=setTimeout(check,1000);
 }
 document.addEventListener('htmx:afterSwap',sync);sync();
})();
