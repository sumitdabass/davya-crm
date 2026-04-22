// Davya CRM service worker — installability only.
// Filament is server-rendered; offline mode would misrepresent data, so we
// deliberately do NOT cache app shell or API responses. The SW exists so
// browsers consider the app installable.

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', (event) => {
    // No-op: let the network handle every request.
});
