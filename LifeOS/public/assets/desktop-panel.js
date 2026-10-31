(() => {
  const root=document.querySelector('main[data-csrf]'),itemsNode=document.querySelector('[data-items]'),dateNode=document.querySelector('[data-date]');
  const addForm=document.querySelector('[data-add-form]'),status=document.querySelector('[data-form-status]');
  let csrf=root?.dataset.csrf||'',preferences={},currentDate='';
  const text=(tag,className,value)=>{const node=document.createElement(tag);if(className)node.className=className;node.textContent=value;return node;};
  function timeLabel(value){
    const match=/^\d{4}-\d{2}-\d{2}[ T](\d{2}):(\d{2})/.exec(value||'');
    if(!match)return 'All day';
    const hour=Number(match[1]);return String(hour%12||12)+':'+match[2]+' '+(hour>=12?'PM':'AM');
  }
  async function post(path,fields={}){
    const response=await fetch(path,{method:'POST',headers:{Accept:'application/json'},body:new URLSearchParams({_csrf:csrf,...fields})});
    if(!response.ok)throw Error('update');return response.json();
  }
  function setAutoHide(){window.chrome?.webview?.postMessage({type:'lifeos-panel-autohide',enabled:preferences.autoHide!==false});}
  function showAdd(kind='task'){
    addForm.hidden=false;addForm.elements.kind.value=kind;
    if(kind==='task'&&!addForm.elements.due.value)addForm.elements.due.value=currentDate;
    addForm.querySelector('[data-note-body]').hidden=kind!=='note';
    addForm.querySelector('[data-due-field]').hidden=kind==='note';
    addForm.elements.title.focus();
  }
  async function savePreferences(){
    const fields={};for(const input of document.querySelectorAll('[data-panel-pref]'))fields[input.dataset.panelPref]=input.checked?'1':'0';
    fields.autoHide=document.querySelector('[name=autoHide]').checked?'1':'0';
    try{const result=await post('/desktop/panel/preferences',fields);preferences=result.preferences;setAutoHide();await refresh();}
    catch{status.textContent='Could not save panel settings.';}
  }
  function renderTodos(todos){
    const target=document.querySelector('[data-todos]');target.replaceChildren();
    if(!todos.length){target.append(text('p','empty','Your quick to-do list is clear.'));return;}
    for(const todo of todos){
      const row=text('div','check-row','');const done=document.createElement('button');done.type='button';done.className='check';done.setAttribute('aria-label','Complete '+todo.title);done.textContent='✓';
      const title=text('span','check-title',todo.title);row.append(done,title);
      if(todo.due)row.append(text('time','',todo.due));
      done.addEventListener('click',async()=>{done.disabled=true;try{await post('/desktop/panel/todo/'+todo.id+'/complete');await refresh();}catch{done.disabled=false;}});
      target.append(row);
    }
  }
  function renderNotes(notes){
    const target=document.querySelector('[data-notes]');target.replaceChildren();
    if(!notes.length){target.append(text('p','empty','Your notes will be here.'));return;}
    for(const note of notes){
      const row=text('article','note','');const content=text('div','note-content','');content.append(text('strong','',note.title));
      if(note.body)content.append(text('p','',note.body));
      const remove=document.createElement('button');remove.type='button';remove.className='icon-button';remove.textContent='×';remove.setAttribute('aria-label','Delete '+note.title);
      remove.addEventListener('click',async()=>{remove.disabled=true;try{await post('/desktop/panel/note/'+note.id+'/delete');await refresh();}catch{remove.disabled=false;}});
      row.append(content,remove);target.append(row);
    }
  }
  async function refresh(){
    try{
      const response=await fetch('/desktop/panel/data',{headers:{Accept:'application/json'},cache:'no-store'});
      if(!response.ok)throw Error('response');
      const data=await response.json();currentDate=data.today;preferences=data.preferences||preferences;setAutoHide();
      const date=new Date(data.today+'T12:00:00+05:30');dateNode.textContent=date.toLocaleDateString(undefined,{weekday:'long',month:'long',day:'numeric'});
      itemsNode.replaceChildren();
      document.querySelector('[data-schedule-count]').textContent=String(data.items.length);
      if(!data.items.length)itemsNode.append(text('p','empty','Nothing scheduled for today.'));
      else{
        const list=text('div','items','');
        for(const item of data.items){const row=text('div','event','');const stamp=text('time','',timeLabel(item.due));stamp.title=item.due;row.append(stamp,text('strong','',item.title),text('small','',item.kind));list.append(row);}
        itemsNode.append(list);
      }
      renderTodos(data.todos||[]);renderNotes(data.notes||[]);
      for(const input of document.querySelectorAll('[data-panel-pref]'))input.checked=preferences[input.dataset.panelPref]!==false;
      document.querySelector('[name=autoHide]').checked=preferences.autoHide!==false;
      document.querySelector('[data-todo-section]').hidden=preferences.showTodos===false;
      document.querySelector('[data-notes-section]').hidden=preferences.showNotes===false;
    }catch{itemsNode.replaceChildren(text('p','muted','Could not refresh today’s panel.'));}
  }
  document.querySelector('[data-close]').addEventListener('click',()=>window.chrome?.webview?.postMessage({type:'lifeos-panel-close'}));
  document.querySelector('[data-refresh]').addEventListener('click',refresh);
  document.querySelector('[data-add-toggle]').addEventListener('click',()=>showAdd());
  document.querySelector('[data-preferences-toggle]').addEventListener('click',event=>{const panel=document.querySelector('[data-preferences]');panel.hidden=!panel.hidden;event.currentTarget.setAttribute('aria-expanded',String(!panel.hidden));});
  document.querySelector('[data-open-planner]').addEventListener('click',event=>{if(!window.chrome?.webview)return;event.preventDefault();window.chrome.webview.postMessage({type:'lifeos-panel-open',route:'/planner'});});
  document.querySelectorAll('[data-section-add]').forEach(button=>button.addEventListener('click',()=>showAdd(button.dataset.sectionAdd)));
  document.querySelector('[data-cancel-add]').addEventListener('click',()=>{addForm.reset();addForm.hidden=true;status.textContent='';});
  document.querySelectorAll('[data-panel-pref],[name=autoHide]').forEach(input=>input.addEventListener('change',savePreferences));
  addForm.elements.kind.addEventListener('change',()=>{const kind=addForm.elements.kind.value;addForm.querySelector('[data-note-body]').hidden=kind!=='note';addForm.querySelector('[data-due-field]').hidden=kind==='note';});
  addForm.addEventListener('submit',async event=>{
    event.preventDefault();status.textContent='';const fields={kind:addForm.elements.kind.value,title:addForm.elements.title.value,body:addForm.elements.body.value,due:addForm.elements.due.value};
    const save=addForm.querySelector('[type=submit]');save.disabled=true;
    try{await post('/desktop/panel/add',fields);addForm.reset();addForm.hidden=true;await refresh();}
    catch{status.textContent='Could not save that item. Check the title and try again.';}
    finally{save.disabled=false;}
  });
  window.addEventListener('keydown',event=>{if(event.key==='Escape'){if(!addForm.hidden){addForm.hidden=true;addForm.reset();}else window.chrome?.webview?.postMessage({type:'lifeos-panel-close'});}});
  refresh();setInterval(()=>{if(!document.hidden)refresh();},60000);
})();
