/* eslint-env serviceworker */
// Sunwise service worker.
// - Precaches the app shell so the app opens without a connection.
// - Keeps the last copy of today's tasks, upcoming tasks, plants and
//   locations, so the lists still show offline.
// - Caches plant photos, icons and fonts.
// - Shows the daily push reminder.
import { precacheAndRoute, cleanupOutdatedCaches, createHandlerBoundToURL } from 'workbox-precaching'
import { registerRoute, NavigationRoute } from 'workbox-routing'
import { NetworkFirst, CacheFirst, StaleWhileRevalidate } from 'workbox-strategies'
import { ExpirationPlugin } from 'workbox-expiration'
import { CacheableResponsePlugin } from 'workbox-cacheable-response'
import { clientsClaim } from 'workbox-core'

const DAY = 24 * 60 * 60

cleanupOutdatedCaches()
precacheAndRoute(self.__WB_MANIFEST)

// Single-page app: every page loads index.html, except API calls, uploads
// and the server-rendered share pages.
registerRoute(new NavigationRoute(createHandlerBoundToURL('/index.html'), {
  denylist: [/^\/api\//, /^\/uploads\//]
}))

// Lists worth having offline. Try the network first so the data is fresh,
// and fall back to the last copy after a few seconds or when offline.
// This cache is cleared on logout (see stores/auth.js).
const OFFLINE_API_PATHS = new Set([
  '/api/tasks/today',
  '/api/tasks/upcoming',
  '/api/plants',
  '/api/locations'
])

registerRoute(
  ({ url, request }) => request.method === 'GET' &&
    url.origin === self.location.origin &&
    OFFLINE_API_PATHS.has(url.pathname),
  new NetworkFirst({
    cacheName: 'sunwise-api',
    networkTimeoutSeconds: 5,
    plugins: [
      new CacheableResponsePlugin({ statuses: [200] }),
      new ExpirationPlugin({ maxEntries: 20, maxAgeSeconds: 14 * DAY })
    ]
  })
)

// Every other /api call goes straight to the network (no route registered).

// Plant photos rarely change once uploaded.
registerRoute(
  ({ url }) => url.origin === self.location.origin && url.pathname.startsWith('/uploads/'),
  new CacheFirst({
    cacheName: 'sunwise-photos',
    plugins: [
      new CacheableResponsePlugin({ statuses: [200] }),
      new ExpirationPlugin({ maxEntries: 300, maxAgeSeconds: 60 * DAY, purgeOnQuotaError: true })
    ]
  })
)

// Doodle icons from icons8. Opaque responses (status 0) are allowed because
// they come from another site.
registerRoute(
  ({ url }) => url.origin === 'https://img.icons8.com',
  new CacheFirst({
    cacheName: 'icons8',
    plugins: [
      new CacheableResponsePlugin({ statuses: [0, 200] }),
      new ExpirationPlugin({ maxEntries: 200, maxAgeSeconds: 90 * DAY, purgeOnQuotaError: true })
    ]
  })
)

// Google Fonts: the stylesheet can change, the font files don't.
registerRoute(
  ({ url }) => url.origin === 'https://fonts.googleapis.com',
  new StaleWhileRevalidate({ cacheName: 'google-fonts-stylesheets' })
)

registerRoute(
  ({ url }) => url.origin === 'https://fonts.gstatic.com',
  new CacheFirst({
    cacheName: 'google-fonts-webfonts',
    plugins: [
      new CacheableResponsePlugin({ statuses: [0, 200] }),
      new ExpirationPlugin({ maxEntries: 30, maxAgeSeconds: 365 * DAY })
    ]
  })
)

// The app asks the waiting worker to take over when the user taps "Reload".
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting()
  }
})

clientsClaim()

// Daily reminder. The server sends pushes with no payload, so the text lives
// here. A payload is still honored if one is ever sent.
self.addEventListener('push', (event) => {
  let data = {}
  try {
    data = event.data ? event.data.json() : {}
  } catch (e) {
    data = {}
  }

  const title = data.title || 'Your plants need care'
  const options = {
    body: data.body || "Open Sunwise to see today's tasks.",
    icon: '/icons/icon-192.png',
    badge: '/icons/badge-72.png',
    tag: data.tag || 'sunwise-daily',
    renotify: false,
    data: { url: (data.data && data.data.url) || data.url || '/' }
  }

  event.waitUntil(self.registration.showNotification(title, options))
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const target = new URL((event.notification.data && event.notification.data.url) || '/', self.location.origin)

  event.waitUntil((async () => {
    const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
    for (const client of windows) {
      const clientUrl = new URL(client.url)
      if (clientUrl.origin === target.origin) {
        await client.focus()
        if (clientUrl.pathname !== target.pathname && 'navigate' in client) {
          await client.navigate(target.href)
        }
        return
      }
    }
    await self.clients.openWindow(target.href)
  })())
})
