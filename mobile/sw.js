/* VERO Campo — service worker (A4-12 protótipo).
   Cache-first do shell (funciona sem internet — rede rural). */
var CACHE = 'vero-campo-v1';
var SHELL = [
  './', './index.html', './manifest.webmanifest',
  '../assets/img/logo_vero.png',
  '../assets/vendor/fonts/vero-fonts.css'
];

self.addEventListener('install', function (e) {
  e.waitUntil(
    caches.open(CACHE).then(function (c) { return c.addAll(SHELL); })
      .then(function () { return self.skipWaiting(); })
      .catch(function () {})
  );
});

self.addEventListener('activate', function (e) {
  e.waitUntil(
    caches.keys().then(function (ks) {
      return Promise.all(ks.filter(function (k) { return k !== CACHE; })
        .map(function (k) { return caches.delete(k); }));
    }).then(function () { return self.clients.claim(); })
  );
});

self.addEventListener('fetch', function (e) {
  if (e.request.method !== 'GET') return;
  e.respondWith(
    caches.match(e.request).then(function (r) {
      return r || fetch(e.request).then(function (resp) {
        var cp = resp.clone();
        caches.open(CACHE).then(function (c) { c.put(e.request, cp); }).catch(function () {});
        return resp;
      }).catch(function () { return caches.match('./index.html'); });
    })
  );
});
