'use strict';

// Runs the actual UI script against a small event/attribute DOM fixture.
// Browser rendering and real htmx integration are covered by WebView2 preflight.
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');

let checks=0;
function check(actual,expected,message) { assert.equal(actual,expected,message); checks++; }

class Element {
  constructor(id='') { this.id=id; this.dataset={}; this.attributes=new Map(); }
  getAttribute(name) { return this.attributes.get(name) ?? null; }
  setAttribute(name,value) { this.attributes.set(name,String(value)); }
  removeAttribute(name) { this.attributes.delete(name); }
  querySelectorAll() { return []; }
  // An existing notice keeps the unrelated connection-error renderer out of scope.
  querySelector() { return {}; }
  closest() { return null; }
  matches(selector) { return this===form && selector==='form'; }
}

const main=new Element('main'), form=new Element(), save=new Element(), unavailable=new Element();
save.setAttribute('aria-disabled','false'); save.name='action'; save.value='save'; save.disabled=false;
unavailable.setAttribute('aria-disabled','true'); unavailable.disabled=true;
form.setAttribute('aria-busy','false');
form.querySelectorAll=()=>[save,unavailable];
form.closest=()=>form;

const document=new EventTarget();
document.documentElement={dataset:{}};
document.querySelector=()=>null;
document.querySelectorAll=()=>[];
document.getElementById=id=>id==='main' ? main : null;
const window=new EventTarget();
window.confirm=()=>true;

vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../public/assets/app.js'),'utf8'),{
  document,window,matchMedia:()=>({matches:false,addEventListener(){}}),
  location:{pathname:'/planner',hash:''},setInterval(){},setTimeout,clearTimeout,queueMicrotask,
  requestAnimationFrame(){},Date
},{filename:'public/assets/app.js'});

function fire(name,detail={},target=document) {
  const event=new Event(name,{cancelable:true}); event.detail=detail;
  if (target!==document) Object.defineProperty(event,'target',{value:target});
  document.dispatchEvent(event);
  return event;
}
const post=xhr=>({xhr,elt:form,target:main,requestConfig:{verb:'post'}});
function idle(message) {
  check(form.dataset.submitting,undefined,message+': form unlocked');
  check(form.getAttribute('aria-busy'),'false',message+': previous form busy state restored');
  check(save.getAttribute('aria-disabled'),'false',message+': available submitter state restored');
  check(unavailable.getAttribute('aria-disabled'),'true',message+': unavailable submitter state retained');
  check(main.getAttribute('aria-busy'),null,message+': content no longer busy');
  check(document.documentElement.dataset.pageLoading,undefined,message+': progress cleared');
}

(async()=>{
  check(fire('submit',{},form).defaultPrevented,false,'Native submit remains available');
  idle('Native submit');

  const preview=new Element('plan-preview'); preview.closest=()=>form;
  fire('htmx:beforeRequest',{xhr:'preview',elt:preview,target:preview,requestConfig:{verb:'post'}});
  check(form.dataset.submitting,undefined,'Background preview does not lock its containing form');
  check(fire('submit',{},form).defaultPrevented,false,'Save remains usable during a background preview');
  fire('htmx:sendAbort',{xhr:'preview'});
  idle('Preview abort');

  fire('htmx:beforeRequest',post('write'));
  check(form.dataset.submitting,'true','An in-flight htmx form POST holds the form');
  check(form.getAttribute('aria-busy'),'true','Form announces busy state');
  check(save.getAttribute('aria-disabled'),'true','Submitter announces pending state');
  check(save.disabled,false,'Submitter stays enabled for request serialization');
  check(unavailable.disabled,true,'Pre-existing disabled control stays disabled');
  check(save.name,'action','Submitter name is preserved');
  check(save.value,'save','Submitter value is preserved');
  check(fire('submit',{},form).defaultPrevented,true,'Rapid repeated submit is prevented');
  check(main.getAttribute('aria-busy'),'true','Content announces navigation in progress');
  check(document.documentElement.dataset.pageLoading,undefined,'Fast requests do not flash the progress bar');

  fire('htmx:beforeRequest',{xhr:'successor',elt:new Element(),target:main,requestConfig:{verb:'get'}});
  await new Promise(resolve=>setTimeout(resolve,150));
  check(document.documentElement.dataset.pageLoading,'true','Slow requests show progress feedback');
  fire('htmx:sendAbort',{xhr:'write'});
  check(form.dataset.submitting,undefined,'Aborted write releases its form');
  check(document.documentElement.dataset.pageLoading,'true','Aborting an old request retains successor progress');
  check(main.getAttribute('aria-busy'),'true','Successor keeps content busy');
  fire('htmx:afterRequest',{xhr:'write'});
  check(document.documentElement.dataset.pageLoading,'true','Repeated old-request cleanup does not clear successor');
  fire('htmx:afterRequest',{xhr:'successor'});
  idle('Successor completed');

  document.addEventListener('htmx:beforeRequest',event=>{
    if (event.detail.xhr==='cancel') event.preventDefault();
  });
  fire('htmx:beforeRequest',post('cancel'));
  await new Promise(resolve=>queueMicrotask(resolve));
  idle('Request cancelled by a later handler');

  for (const eventName of ['htmx:sendError','htmx:timeout','htmx:onLoadError','htmx:afterRequest']) {
    fire('htmx:beforeRequest',post(eventName));
    fire(eventName,{xhr:eventName});
    idle(eventName);
  }

  fire('htmx:beforeRequest',post('bfcache'));
  window.dispatchEvent(new Event('pageshow'));
  idle('Back/forward cache restoration');
  check(fire('submit',{},form).defaultPrevented,false,'Restored page accepts another submit');

  console.log(`${checks} UI lifecycle checks passed.`);
})().catch(error=>{ console.error(error); process.exitCode=1; });
