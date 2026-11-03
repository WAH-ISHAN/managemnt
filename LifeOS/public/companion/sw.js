const CACHE='lifeos-companion-v1';
self.addEventListener('install',e=>e.waitUntil(caches.open(CACHE).then(c=>c.addAll(['/','/app.js','/style.css','/manifest.json']))));
self.addEventListener('fetch',e=>{const u=new URL(e.request.url);if(e.request.method==='GET'&&u.origin===self.location.origin&&['/','/app.js','/style.css','/manifest.json'].includes(u.pathname))e.respondWith(caches.match(e.request).then(r=>r||fetch(e.request)));});
