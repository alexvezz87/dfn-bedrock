/**
 * DFN Mobile App Service Worker (PWA)
 */

const CACHE_NAME = 'dfn-mobile-app-v1';
const ASSETS_TO_CACHE = [
  '/gestione-eventi/',
  '/app/themes/dfn-theme/assets/css/dfn-mobile-app.css',
  '/app/themes/dfn-theme/assets/js/dfn-mobile-app.js',
  '/app/themes/dfn-theme/manifest.json'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return cache.addAll(ASSETS_TO_CACHE);
    }).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => {
      return Promise.all(
        keys.map((key) => {
          if (key !== CACHE_NAME) {
            return caches.delete(key);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  // Only handle GET requests and ignore AJAX/admin-ajax calls
  if (event.request.method !== 'GET' || event.request.url.includes('admin-ajax.php')) {
    return;
  }
  
  event.respondWith(
    fetch(event.request).catch(() => {
      return caches.match(event.request);
    })
  );
});
