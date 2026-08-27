const CACHE_NAME = 'mpv-control-v3';

// Chỉ Precache các tài nguyên tĩnh BẤT BIẾN cục bộ
const STATIC_ASSETS = [
    './manifest.json',
    './icon-192.png',
    './icon-512.png'
];

// 1. Install Event - Cache Local Static Icons & Manifest
self.addEventListener('install', (e) => {
    e.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(STATIC_ASSETS);
        })
    );
    self.skipWaiting();
});

// 2. Activate Event - Force Control Clients & Cleanup Old Caches
self.addEventListener('activate', (e) => {
    e.waitUntil(
        caches.keys().then((keys) => {
            return Promise.all(
                keys.map((key) => {
                    if (key !== CACHE_NAME) {
                        return caches.delete(key);
                    }
                })
            );
        })
    );
    self.clients.claim();
});

// 3. Fetch Event - Bỏ qua hoàn toàn index.php / HTML Navigation / POST / XHR
self.addEventListener('fetch', (e) => {
    const request = e.request;

    // Bỏ qua tất cả POST request hoặc AJAX / XHR Requests
    if (request.method !== 'GET' || request.headers.get('X-Requested-With') === 'XMLHttpRequest') {
        return;
    }

    // Chỉ áp dụng Cache cho đúng các Static Assets trong danh sách STATIC_ASSETS
    const isStaticAsset = STATIC_ASSETS.some(asset => request.url.endsWith(asset.replace('./', '')));

    if (isStaticAsset) {
        e.respondWith(
            caches.match(request).then((cachedResponse) => {
                return cachedResponse || fetch(request);
            })
        );
        return;
    }

    // Mọi Request còn lại (Bao gồm Navigation tới index.php) LUÔN ĐI THẲNG NETWORK
});
