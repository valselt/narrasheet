// GANTI VERSI INI SETIAP ADA PERUBAHAN FILE (misal: v2, v3, dst)
const CACHE_NAME = 'narrasheet-v3';

const urlsToCache = [
  './',
  './index.php',
  './manifest.json',
  './icon-192.png',
  './icon-512.png'
  // Tambahkan file CSS/JS lain jika ada, misal:
  // './style.css', 
];

// 1. Install & Cache Aset Penting
self.addEventListener('install', event => {
  self.skipWaiting(); // Paksa SW baru untuk segera aktif
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => {
        console.log('Opened cache');
        return cache.addAll(urlsToCache);
      })
  );
});

// 2. Fetch Strategy: Network First, Fallback to Cache
self.addEventListener('fetch', event => {
  event.respondWith(
    fetch(event.request)
      .then(response => {
        // Jika berhasil ambil dari internet, simpan copy-nya ke cache (agar data selalu update)
        // Kita clone karena response stream hanya bisa dibaca sekali
        const resClone = response.clone();
        caches.open(CACHE_NAME).then(cache => {
          cache.put(event.request, resClone);
        });
        return response;
      })
      .catch(() => {
        // Jika offline, ambil dari cache
        return caches.match(event.request);
      })
  );
});

// 3. Hapus Cache Lama (Clean Up)
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.map(cacheName => {
          if (cacheName !== CACHE_NAME) {
            console.log('Menghapus cache lama:', cacheName);
            return caches.delete(cacheName);
          }
        })
      );
    })
  );
  self.clients.claim(); // Ambil alih kontrol halaman segera
});