(() => {
  const root = document.body.dataset.apiRoot;
  const state = {token:null,sites:[],site:null,manifest:null,page:null,expires:0};
  const $ = id => document.getElementById(id);
  function node(tag,text='',className='') { const e=document.createElement(tag); if(text!==null)e.textContent=String(text);if(className)e.className=className;return e; }
  function status(text,error=false) { $('status').textContent=text;$('status').classList.toggle('error',error); }
  async function api(path,method='GET',body=null) {
    const headers={'Accept':'application/json'};if(state.token)headers.Authorization='Bearer '+state.token;
    if(body!==null)headers['Content-Type']='application/json';
    const response=await fetch(root+'/api/v1'+path,{method,headers,body:body!==null?JSON.stringify(body):null,credentials:'omit',cache:'no-store'});
    const result=await response.json();if(!response.ok)throw new Error(result.error||'The request could not be completed.');return result;
  }
  const button=(label,action,secondary=false)=>{const b=node('button',label,secondary?'secondary':'');b.type='button';b.addEventListener('click',async()=>{b.disabled=true;try{await action();}catch(e){status(e.message,true);}finally{b.disabled=false;}});return b;};
  function logout() {state.token=null;state.site=null;state.sites=[];$('workspace').hidden=true;$('login-screen').hidden=false;$('signout').hidden=true;$('editor-area').replaceChildren();$('site-grid').replaceChildren();status('Signed out.');}
  function listSites() {
    state.site=null;$('site-workspace').hidden=true;$('site-grid').hidden=false;$('all-sites').hidden=true;$('workspace-title').textContent='Your websites';$('workspace-description').textContent='Choose a website to work on its saved cloud content.';$('site-grid').replaceChildren();
    if(!state.sites.length){$('site-grid').append(node('p','No websites are assigned to this account yet.','card'));return;}
    for(const site of state.sites){const card=node('article',null,'card site-card');card.append(node('span',site.render_mode+' rendering','eyebrow'),node('h2',site.name),node('p',site.url,'muted'),node('p',site.pages.length+' editable pages','fine'),button('Open website',()=>openSite(site)));$('site-grid').append(card);}
  }
  async function openSite(site) {
    state.site=site;state.manifest=await api('/sites/'+site.id+'/manifest');$('workspace-title').textContent=site.name;$('workspace-description').textContent=site.url;$('site-grid').hidden=true;$('site-workspace').hidden=false;$('all-sites').hidden=false;$('page-navigation').replaceChildren();
    for(const page of state.manifest.pages||[]){$('page-navigation').append(button(page.label,()=>openPage(page),true));}
    if(state.manifest.pages?.length)await openPage(state.manifest.pages[0]);else $('editor-area').replaceChildren(node('section','Your provider has not registered a page manifest yet.','card'));
  }
  async function openPage(definition) {
    const site=state.site;const page=await api('/sites/'+site.id+'/pages/'+definition.slug);state.page=page;$('editor-area').replaceChildren();
    const card=node('section',null,'card');const heading=node('div',null,'section-heading');heading.append(node('h2',definition.label),node('span',page.status+' · v'+page.version,'badge'));card.append(heading);
    const form=document.createElement('form');form.className='field-grid';const canEdit=site.permissions.includes('update');
    for(const field of definition.fields){const label=node('label',field.label+(field.required?' *':''));let input;
      if(['textarea','richtext'].includes(field.type)){input=document.createElement('textarea');input.rows=field.type==='richtext'?8:3;label.className='full';}
      else{input=document.createElement('input');input.type=({image:'url',url:'url',date:'date',number:'number',boolean:'checkbox'})[field.type]||'text';if(field.type==='number')input.step='any';}
      input.name=field.key;if(field.type==='boolean')input.checked=Boolean(page.fields[field.key]);else input.value=page.fields[field.key]??'';input.maxLength=field.max_length;input.required=field.required&&field.type!=='boolean';input.disabled=!canEdit;label.append(input);form.append(label);
    }
    if(canEdit){const save=node('button','Save cloud draft');const row=node('div',null,'full actions');row.append(save,node('small','Saving a draft does not change the live website.'));form.append(row);}
    form.addEventListener('submit',async e=>{e.preventDefault();const submit=form.querySelector('button');submit.disabled=true;try{const fields={};for(const field of definition.fields){const input=form.elements[field.key];fields[field.key]=field.type==='boolean'?input.checked:field.type==='number'?(input.value===''?null:Number(input.value)):input.value;}await api('/sites/'+site.id+'/pages/'+definition.slug,'PUT',{version:page.version,fields});status('Draft saved. Your provider can review and publish it.');await openPage(definition);}catch(error){status(error.message,true);}finally{submit.disabled=false;}});card.append(form);$('editor-area').append(card);
    const publish=node('section',null,'card');publish.append(node('h2','Publishing'),node('p','Published version: '+(page.published_version===null?'Not yet published':'v'+page.published_version)));
    if(site.permissions.includes('publish'))publish.append(button('Publish approved version',async()=>{if(!confirm('Publish this saved draft version?'))return;await api('/sites/'+site.id+'/pages/'+definition.slug+'/publish','POST',{version:page.version});status('Publication queued. Live status requires website confirmation.');await openPage(definition);}));else publish.append(node('p','Your account edits drafts. Your provider approves publishing.','fine'));
    $('editor-area').append(publish);
    const history=await api('/sites/'+site.id+'/pages/'+definition.slug+'/versions');const historyCard=node('section',null,'card');historyCard.append(node('h2','Version history'));
    if(!history.versions.length)historyCard.append(node('p','No saved versions yet.','muted'));
    for(const version of history.versions){const row=node('article',null,'record-row');row.append(node('strong','v'+version.version+' · '+version.stage),node('span',version.actor+' · '+version.created_at,'fine'));if(site.permissions.includes('publish')&&version.stage==='published'&&version.version!==page.version)row.append(button('Rollback to v'+version.version,async()=>{if(!confirm('Publish this earlier content as a new version?'))return;await api('/sites/'+site.id+'/pages/'+definition.slug+'/rollback','POST',{version:page.version,target_version:version.version});status('Rollback queued as a new publication.');await openPage(definition);},true));historyCard.append(row);}$('editor-area').append(historyCard);
    const jobs=await api('/sites/'+site.id+'/deployments');const jobsCard=node('section',null,'card');jobsCard.append(node('h2','Publish delivery'),button('Refresh status',()=>openPage(definition),true));if(!jobs.deployments.length)jobsCard.append(node('p','No publications yet.','muted'));for(const job of jobs.deployments){const row=node('article',null,'record-row');row.append(node('strong',job.page+' · v'+job.version),node('span',job.status,'badge'),node('small','Webhook '+job.delivery+(job.last_error?' · '+job.last_error:'')));jobsCard.append(row);}$('editor-area').append(jobsCard);
  }
  $('login-form').addEventListener('submit',async e=>{e.preventDefault();const f=e.currentTarget,b=f.querySelector('button');b.disabled=true;try{const login=await api('/login','POST',{username:f.elements.username.value,password:f.elements.password.value});state.token=login.token;state.expires=login.expires_at;f.elements.password.value='';const overview=await api('/overview');state.sites=overview.sites;$('login-screen').hidden=true;$('workspace').hidden=false;$('signout').hidden=false;listSites();status('Signed in. Only your assigned sites are available.');}catch(error){state.token=null;status(error.message,true);}finally{b.disabled=false;}});
  $('signout').addEventListener('click',async()=>{try{await api('/logout','POST',{});}finally{logout();}});$('all-sites').addEventListener('click',listSites);
  setInterval(()=>{if(state.token&&Date.now()/1000>=state.expires){logout();status('Your session expired. Sign in again.');}},15000);
})();
