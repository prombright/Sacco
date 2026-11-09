// The app on phones: opens fast and from the home screen. Members' data is never stored here -
// the API always goes to the server; only the app's own files are kept.
const VERSION = 'sacco-app-v2'
const BASE = new URL(self.registration.scope).pathname

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(VERSION).then((c) => c.addAll([BASE, BASE + 'brand/theme-init.js', BASE + 'brand/splash.js', BASE + 'brand/icon-192.png'])).then(() => self.skipWaiting()),
  )
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k)))).then(() => self.clients.claim()),
  )
})

self.addEventListener('fetch', (event) => {
  const req = event.request
  const url = new URL(req.url)
  if (req.method !== 'GET' || url.origin !== self.location.origin || url.pathname.startsWith(BASE + 'api/')) return

  // Pages: the newest from the server, the kept copy when offline
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req)
        .then((res) => {
          const copy = res.clone()
          caches.open(VERSION).then((c) => c.put(BASE, copy))
          return res
        })
        .catch(() => caches.match(BASE)),
    )
    return
  }

  // The app's files: their names change with every version, so a kept copy is always right
  if (url.pathname.startsWith(BASE + 'assets/')) {
    event.respondWith(
      caches.match(req).then((hit) => hit || fetch(req).then((res) => {
        if (res.ok) {
          const copy = res.clone()
          caches.open(VERSION).then((c) => c.put(req, copy))
        }
        return res
      })),
    )
    return
  }

  // Icons and start-up scripts keep their names, so the kept copy is used while a fresh one is fetched
  if (url.pathname.startsWith(BASE + 'brand/')) {
    event.respondWith(
      caches.open(VERSION).then((c) => c.match(req).then((hit) => {
        const fresh = fetch(req).then((res) => {
          if (res.ok) c.put(req, res.clone())
          return res
        })
        return hit || fresh
      })),
    )
  }
})
