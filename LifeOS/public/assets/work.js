(() => {
  let drag=null;
  const applyDataStyles=()=>{
    for(const element of document.querySelectorAll('[data-work-color]')) {
      if(/^#[a-f0-9]{6}$/i.test(element.dataset.workColor)) element.style.setProperty('--work-color',element.dataset.workColor);
    }
    for(const element of document.querySelectorAll('[data-work-left][data-work-width]')) {
      const left=Number(element.dataset.workLeft),width=Number(element.dataset.workWidth);
      if(Number.isFinite(left)&&left>=0&&left<=100&&Number.isFinite(width)&&width>0&&width<=100) {
        element.style.left=left+'%';element.style.width=width+'%';
      }
    }
  };
  applyDataStyles();document.addEventListener('htmx:afterSwap',applyDataStyles);
  const updateTimers=()=>{for(const timer of document.querySelectorAll('[data-work-timer]')){const seconds=Math.max(0,Math.floor(Date.now()/1000)-Number(timer.dataset.workTimer));timer.textContent=[Math.floor(seconds/3600),Math.floor(seconds/60)%60,seconds%60].map(n=>String(n).padStart(2,'0')).join(':');}};
  updateTimers();setInterval(updateTimers,1000);document.addEventListener('htmx:afterSwap',updateTimers);
  document.addEventListener('change',event=>{
    if(event.target.matches('[data-work-navigate]')&&/^\/work\?workspace=[1-9]\d*$/.test(event.target.value))location.assign(event.target.value);
    if(event.target.matches('[data-work-bulk-field]')){const values=event.target.closest('form').querySelector('[data-work-bulk-values]');for(const option of values.options){option.hidden=option.dataset.field!==event.target.value;option.disabled=option.hidden;}const first=[...values.options].find(o=>!o.hidden);if(first)first.selected=true;}
  });
  document.addEventListener('click',event=>{
    const button=event.target.closest('[data-work-format]');if(!button)return;const textarea=button.closest('label')?.querySelector('textarea');if(!textarea)return;event.preventDefault();const start=textarea.selectionStart,end=textarea.selectionEnd,selected=textarea.value.slice(start,end)||'text';const replacement=button.dataset.workFormat==='bold'?'**'+selected+'**':button.dataset.workFormat==='heading'?'## '+selected:'- '+selected;textarea.setRangeText(replacement,start,end,'select');textarea.focus();textarea.dispatchEvent(new Event('input',{bubbles:true}));
  });
  document.addEventListener('dragstart',event=>{const card=event.target.closest('[data-work-card][draggable=true]');if(!card)return;const board=card.closest('[data-work-board]');drag={id:card.dataset.workCard,version:card.dataset.version,project:board.dataset.project};card.classList.add('is-dragging');event.dataTransfer.effectAllowed='move';event.dataTransfer.setData('text/plain',card.dataset.workCard);});
  document.addEventListener('dragend',()=>{drag=null;document.querySelectorAll('.is-dragging,.is-drop-target').forEach(el=>el.classList.remove('is-dragging','is-drop-target'));});
  document.addEventListener('dragover',event=>{const column=event.target.closest('[data-work-state]');if(!column||!drag||column.closest('[data-work-board]').dataset.project!==drag.project)return;event.preventDefault();event.dataTransfer.dropEffect='move';column.classList.add('is-drop-target');});
  document.addEventListener('dragleave',event=>{const column=event.target.closest('[data-work-state]');if(column&&!column.contains(event.relatedTarget))column.classList.remove('is-drop-target');});
  document.addEventListener('drop',async event=>{
    const column=event.target.closest('[data-work-state]');if(!column||!drag)return;const board=column.closest('[data-work-board]');if(board.dataset.project!==drag.project)return;event.preventDefault();const item=drag;drag=null;const status=document.querySelector('[data-work-board-status]');const csrf=document.querySelector('input[name=_csrf]')?.value;if(!csrf)return;const scroll=board.scrollLeft;const position=scrollY;column.classList.remove('is-drop-target');if(status)status.textContent='Saving workflow change…';
    try{const response=await fetch('/work/project/'+item.project+'/issues/'+item.id+'/move',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'LifeOS-Work-Board'},body:new URLSearchParams({_csrf:csrf,state_id:column.dataset.workState,version:item.version}),credentials:'same-origin'});if(!response.ok)throw new Error('The card changed or could not be saved. Refresh the board before retrying.');const result=await response.json();if(!result.ok)throw new Error('Workflow update was not confirmed.');if(window.htmx){await htmx.ajax('GET',location.pathname+location.search,{target:'#main',swap:'outerHTML',select:'#main'});const refreshed=document.querySelector('[data-work-board]');if(refreshed)refreshed.scrollLeft=scroll;scrollTo(0,position);const message=document.querySelector('[data-work-board-status]');if(message)message.textContent='Work item moved and saved.';}else location.reload();}catch(error){if(status)status.textContent=error.message;document.querySelectorAll('.is-dragging').forEach(el=>el.classList.remove('is-dragging'));}
  });
})();
