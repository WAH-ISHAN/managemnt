'use strict';
// Request-count and message behavior of the actual calendar script, without a browser.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
let checks = 0, requests = 0, shell = true;
const check = (actual, expected, label) => { assert.equal(actual, expected, label); checks++; };
const document = new EventTarget();
document.querySelector = selector => selector === '.app-shell' && shell ? {} : null;
document.querySelectorAll = () => [];
const messages = [], window = new EventTarget();
window.chrome = { webview: { postMessage: message => messages.push(message), addEventListener() {} } };
const location = { pathname:'/planner', search:'' };
let saved = { enabled:true, batchId:'first-batch' };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../public/assets/uni-calendar.js'), 'utf8'), {
  document, window, location,
  fetch: async () => { requests++; return { ok:true, headers:{get:()=>'application/json'}, json:async()=>({...saved}) }; }
}, { filename:'public/assets/uni-calendar.js' });
const settle = id => {
  const event = new Event('htmx:afterSettle'); event.detail = { elt:{id} }; document.dispatchEvent(event);
};
const flush = () => new Promise(resolve => setImmediate(resolve));
(async () => {
  await flush();
  check(requests, 1, 'Startup reads authoritative saved calendar configuration once');
  check(messages.at(-1).batchId, 'first-batch', 'Startup tells native sync which batch is saved');
  for (let page=0; page<20; page++) settle('main');
  await flush();
  check(requests, 1, 'Twenty unrelated navigations create no redundant calendar configuration requests');
  location.pathname='/uni/calendar';
  saved={enabled:true,batchId:'changed-batch'};
  settle('main'); await flush();
  check(requests, 2, 'Opening or saving the calendar page refreshes its configuration');
  check(messages.at(-1).batchId, 'changed-batch', 'Changing calendar batch reaches native sync');
  settle('uni-calendar-events'); await flush();
  check(requests, 2, 'Refreshing the lecture list does not request configuration again');
  saved={enabled:false,batchId:'changed-batch'};
  settle('main'); await flush();
  check(messages.at(-1).enabled, false, 'Pausing sync on the calendar page reaches native sync');
  shell=false; location.pathname='/unlock'; settle('main'); await flush();
  check(messages.at(-1).enabled, false, 'Leaving the unlocked app pauses native sync');
  shell=true; location.pathname='/planner'; saved={enabled:true,batchId:'resumed-batch'};
  settle('main'); await flush();
  check(requests, 4, 'Unlock refreshes authoritative configuration even on another page');
  check(messages.at(-1).batchId, 'resumed-batch', 'Unlock can resume the selected batch');
  const button={dataset:{nibmAction:'sync'}};
  const click=new Event('click'); Object.defineProperty(click,'target',{value:{closest:()=>button}});
  document.dispatchEvent(click); await flush();
  check(requests, 5, 'Explicit Sync now refreshes saved configuration');
  check(messages.at(-1).action, 'sync', 'Explicit Sync now still sends the native action');
  console.log(`${checks} calendar UI request lifecycle checks passed.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
