(() => {
 function open(event){const trigger=event.target.closest('[data-assistant-open],a[href="/intelligence"]');if(!trigger)return;const bridge=window.chrome?.webview;if(bridge){event.preventDefault();event.stopImmediatePropagation();bridge.postMessage({type:'lifeos-assistant-panel'});}else if(trigger.hasAttribute('data-assistant-open')){event.preventDefault();location.assign('/assistant');}}
 document.addEventListener('click',open,true);
})();
