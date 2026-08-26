self.addEventListener('install', (e) => self.skipWaiting());
self.addEventListener('activate', (e) => self.clients.claim());
self.addEventListener('fetch', (e) => {
    // Để nguyên cho Fetch gọi trực tiếp về PHP backend
});
