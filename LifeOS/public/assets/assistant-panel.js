(() => {
 const settings=JSON.parse(document.getElementById('assistant-config').textContent);
 const t=key=>settings.copy[key]||key;
 const bridge=window.chrome?.webview;
 const feed=document.querySelector('[data-messages]'),welcome=document.querySelector('[data-welcome]'),scroller=document.querySelector('[data-conversation]');
 const form=document.querySelector('[data-assistant-composer]'),input=form.elements.message,send=form.querySelector('button'),status=document.querySelector('[data-assistant-status]'),newChat=document.querySelector('[data-new-chat]');
 const csrf=form.elements._csrf.value;
 let poll=null,busy=false,sending=false,hidden=false,lastState='';
 const node=(tag,className,text)=>{const el=document.createElement(tag);if(className)el.className=className;if(text!==undefined)el.textContent=text;return el;};
 function focus(){input.focus();}
 function controls(){send.disabled=busy||sending||!input.value.trim();newChat.disabled=busy||sending;input.style.height='auto';input.style.height=Math.min(144,Math.max(37,input.scrollHeight))+'px';}
 function inline(el,text){for(const part of text.split(/(\*\*[^*]+\*\*|`[^`]+`)/g)){if(part.startsWith('**')&&part.endsWith('**'))el.append(node('strong','',part.slice(2,-2)));else if(part.startsWith('`')&&part.endsWith('`'))el.append(node('code','',part.slice(1,-1)));else el.append(document.createTextNode(part));}}
 function markdown(container,text){let list=null,code=null,paragraph=[];const flush=()=>{if(paragraph.length){const p=node('p');inline(p,paragraph.join('\n'));container.append(p);paragraph=[];}};for(const line of text.split('\n')){
  if(/^```/.test(line)){flush();list=null;if(code){container.append(code);code=null;}else code=node('pre');continue;}
  if(code){code.textContent+=(code.textContent?'\n':'')+line;continue;}
  if(!line.trim()){flush();list=null;continue;}
  const bullet=line.match(/^\s*(?:[-*]|\d+\.)\s+(.+)/);if(bullet){flush();if(!list){list=node('ul');container.append(list);}const li=node('li');inline(li,bullet[1]);list.append(li);continue;}
  const heading=line.match(/^#{1,4}\s+(.+)/);if(heading){flush();list=null;const h=node('h3');inline(h,heading[1]);container.append(h);continue;}list=null;paragraph.push(line);
 }flush();if(code)container.append(code);}
 async function request(path,fields){const options={cache:'no-store',redirect:'follow',headers:{Accept:'application/json'}};if(fields){options.method='POST';options.body=new URLSearchParams({_csrf:csrf,...fields});}const response=await fetch(path,options);if(new URL(response.url).pathname==='/unlock'){status.textContent=t('Please unlock LifeOS to continue.');bridge?.postMessage({type:'lifeos-assistant-locked'});throw new Error('locked');}if(!response.ok||!response.headers.get('Content-Type')?.includes('application/json'))throw new Error('request');return response.json();}
 function field(form,label,key,value,type='text',full=false){const wrap=node('label',full?'full':'',t(label));const el=node('input');el.name=key;el.type=type;el.value=value??'';if(type!=='date')el.required=true;if(type==='number'){el.min='.01';el.step='.01';}wrap.append(el);form.append(wrap);return el;}
 function choice(form,label,key,options){const wrap=node('label','',t(label)),select=node('select');select.name=key;select.required=true;select.append(new Option(t('Choose…'),''));for(const option of options)select.append(new Option(option.name,String(option.id)));wrap.append(select);form.append(wrap);}
 function actionCard(action,message){const card=node('section','assistant-action');
  if(action.action==='summarize_week'){const link=node('a','',t('View weekly review'));link.href='/roles?view=week';link.addEventListener('click',event=>{if(bridge){event.preventDefault();bridge.postMessage({type:'lifeos-assistant-open',route:'/roles?view=week'});}});card.append(link);return card;}
  if(action.applied){card.append(node('p','action-applied','✓ '+t(action.action==='add_task'?'Added to Planner':'Expense recorded')));return card;}
  card.append(node('h4','',t(action.action==='add_task'?'Review task':'Review expense')));const review=node('form');review.dataset.actionForm='';
  if(action.action==='add_task'){field(review,'Title','title',action.title,'text',true);field(review,'Due date','due',action.due,'date');}
  else{field(review,'Amount in LKR','amount',action.amount,'number');field(review,'Date','date',action.date,'date');field(review,'Note','note',action.note,'text',true);choice(review,'Account','account_id',settings.accounts);choice(review,'Category','category_id',settings.categories);}
  const confirm=node('button','full',t(action.action==='add_task'?'Add task':'Record expense'));confirm.type='submit';review.append(confirm);review.addEventListener('submit',async event=>{event.preventDefault();confirm.disabled=true;status.textContent='';try{const fields=Object.fromEntries(new FormData(review));fields.index=String(action.index);render(await request('/assistant/action/'+message.id,fields),true);}catch{status.textContent=t('Something changed. Please review and try again.');confirm.disabled=false;}});card.append(review);return card;
 }
 function render(state,force=false){const signature=JSON.stringify(state);busy=state.messages.some(message=>['queued','running'].includes(message.status));controls();if(signature===lastState&&!force)return;lastState=signature;welcome.hidden=state.messages.length>0;feed.replaceChildren();for(const message of state.messages){const turn=node('article','chat-turn');turn.append(node('div','chat-question',message.question));const reply=node('div','chat-reply'),label=node('div','chat-reply-label'),icon=node('img');icon.src='/assets/brand/lifeos-mark.png';icon.alt='';label.append(icon,document.createTextNode('LifeOS AI'));reply.append(label);
   if(['queued','running'].includes(message.status)){const pending=node('div','chat-pending'),dots=node('span','typing-dots');for(let i=0;i<3;i++)dots.append(node('i'));dots.setAttribute('aria-hidden','true');pending.append(dots,node('span','',t('Thinking…')));reply.append(pending);}
   else if(message.status==='failed'){const error=node('div','chat-error'),retry=node('button','text-button',t('Try again'));retry.type='button';retry.addEventListener('click',()=>{input.value=message.question;controls();focus();});error.append(node('p','',message.error),retry);reply.append(error);}
   else{const content=node('div','chat-reply-content');markdown(content,message.answer);reply.append(content);for(const action of message.actions)reply.append(actionCard(action,message));}
   turn.append(reply);feed.append(turn);
  }requestAnimationFrame(()=>{scroller.scrollTop=scroller.scrollHeight;});
 }
 function schedule(state){clearTimeout(poll);if(hidden||!busy)return;for(const message of state.messages)if(message.status==='queued')bridge?.postMessage({type:'lifeos-ai-job',id:message.id});if(!bridge){status.textContent=t('Open the LifeOS Windows app to use your assistant.');return;}poll=setTimeout(load,1200);}
 async function load(){if(hidden)return;try{const state=await request('/assistant/state');render(state);schedule(state);}catch(error){if(error.message!=='locked')status.textContent=t('LifeOS AI is temporarily unavailable. Please try again shortly.');}}
 form.addEventListener('submit',async event=>{event.preventDefault();if(busy||sending||!input.value.trim())return;sending=true;controls();status.textContent='';try{const state=await request('/assistant/message',{message:input.value.trim()});input.value='';render(state,true);schedule(state);}catch(error){if(error.message!=='locked')status.textContent=t('LifeOS AI is temporarily unavailable. Please try again shortly.');}finally{sending=false;controls();focus();}});
 input.addEventListener('input',controls);input.addEventListener('keydown',event=>{if(event.key==='Enter'&&!event.shiftKey&&!event.isComposing){event.preventDefault();if(!send.disabled)form.requestSubmit();}});
 document.querySelectorAll('[data-suggestion]').forEach(button=>button.addEventListener('click',()=>{input.value=button.dataset.suggestion;controls();focus();}));
 newChat.addEventListener('click',async()=>{if(busy)return;try{render(await request('/assistant/new',{}),true);input.value='';status.textContent='';controls();focus();}catch{status.textContent=t('Something changed. Please review and try again.');}});
 document.querySelector('[data-assistant-hide]').addEventListener('click',()=>bridge?bridge.postMessage({type:'lifeos-assistant-window',action:'hide'}):window.close());
 document.addEventListener('keydown',event=>{if(event.key==='Escape')bridge?.postMessage({type:'lifeos-assistant-window',action:'hide'});});
 document.querySelector('[data-assistant-drag]').addEventListener('pointerdown',event=>{if(event.button!==0||event.target.closest('button'))return;bridge?.postMessage({type:'lifeos-assistant-window',action:'drag'});});
 document.querySelector('[data-assistant-resize]').addEventListener('pointerdown',event=>{if(event.button===0)bridge?.postMessage({type:'lifeos-assistant-window',action:'resize'});});
 window.addEventListener('lifeos:assistant-show',()=>{hidden=false;load();focus();});window.addEventListener('lifeos:assistant-hide',()=>{hidden=true;clearTimeout(poll);});
 if(bridge){document.documentElement.classList.add('native-assistant');const theme=()=>bridge.postMessage({type:'lifeos-assistant-theme',dark:document.documentElement.dataset.theme==='dark'||(document.documentElement.dataset.theme!=='light'&&matchMedia('(prefers-color-scheme:dark)').matches)});theme();matchMedia('(prefers-color-scheme:dark)').addEventListener('change',theme);}
 load();controls();focus();
})();
