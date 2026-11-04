(() => {
 let timer;
 function sync(){clearInterval(timer);const clock=document.querySelector('[data-role-timer]');if(!clock)return;const draw=()=>{const seconds=Math.max(0,Math.floor(Date.now()/1000-Number(clock.dataset.roleTimer)));clock.textContent=[Math.floor(seconds/3600),Math.floor(seconds/60)%60,seconds%60].map(n=>String(n).padStart(2,'0')).join(':');};draw();timer=setInterval(draw,1000);}
 document.addEventListener('htmx:afterSwap',sync);sync();
})();
