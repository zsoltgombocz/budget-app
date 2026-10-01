/*
 * Budget service worker.
 * - Hashed build assets and icons: cache first.
 * - Page navigations: network first; the dashboard's last state is kept for offline use.
 * - Everything else (Livewire updates, POSTs): network only.
 * - Web Push: shows notifications and handles their action buttons.
 */
const VERSION = 'v1'
const STATIC_CACHE = `static-${VERSION}`
const PAGE_CACHE = 'pages'
const OFFLINE_URL = '/offline.html'
const OFFLINE_PAGES = ['/ma']
const PRECACHE = [OFFLINE_URL, '/icons/icon-192.png', '/icons/badge-96.png', '/manifest.webmanifest']

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(STATIC_CACHE).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting()))
})

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key.startsWith('static-') && key !== STATIC_CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    )
})

self.addEventListener('message', (event) => {
    if (event.data === 'clear-user-data') {
        event.waitUntil(caches.delete(PAGE_CACHE))
    }
})

self.addEventListener('fetch', (event) => {
    const request = event.request
    const url = new URL(request.url)

    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return
    }

    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/')) {
        event.respondWith(cacheFirst(request))
        return
    }

    if (request.mode === 'navigate') {
        event.respondWith(networkFirstPage(request, url))
    }
})

async function cacheFirst(request) {
    const cached = await caches.match(request)
    if (cached) return cached

    const response = await fetch(request)
    if (response.ok) {
        const cache = await caches.open(STATIC_CACHE)
        cache.put(request, response.clone())
    }
    return response
}

async function networkFirstPage(request, url) {
    try {
        const response = await fetch(request)

        if (OFFLINE_PAGES.includes(url.pathname) && response.ok && ! response.redirected) {
            const cache = await caches.open(PAGE_CACHE)
            cache.put(url.pathname, response.clone())
        }

        return response
    } catch (error) {
        const cached = await caches.match(url.pathname, { cacheName: PAGE_CACHE })
        return cached || caches.match(OFFLINE_URL)
    }
}

self.addEventListener('push', (event) => {
    let payload = {}
    try {
        payload = event.data ? event.data.json() : {}
    } catch (error) {
        payload = { title: 'Budget', body: event.data ? event.data.text() : '' }
    }

    const data = payload.data || {}

    event.waitUntil(self.registration.showNotification(payload.title || 'Budget', {
        body: payload.body || '',
        icon: payload.icon || '/icons/icon-192.png',
        badge: payload.badge || '/icons/badge-96.png',
        tag: payload.tag,
        renotify: Boolean(payload.tag),
        actions: payload.actions || [],
        data,
    }))
})

self.addEventListener('notificationclick', (event) => {
    const data = event.notification.data || {}
    event.notification.close()

    if (event.action === 'no-spend' && data.noSpendUrl) {
        event.waitUntil(fetch(data.noSpendUrl, { method: 'POST', credentials: 'omit' }))
        return
    }

    const target = new URL((event.action && data.actionUrls && data.actionUrls[event.action]) || data.url || '/ma', self.location.origin).href

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
        for (const client of windows) {
            if ('focus' in client) {
                await client.focus()
                if ('navigate' in client) return client.navigate(target)
                return
            }
        }
        return self.clients.openWindow(target)
    })())
})
