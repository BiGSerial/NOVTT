/* NOVTT: cache only the public application shell and fingerprinted build assets.
 * Never cache /api, auth endpoints, credentialed requests or sensitive responses.
 */
const CACHE_NAME = 'novtt-player-shell-v1'
const PUBLIC_SHELL = ['/', '/manifest.webmanifest', '/icons/novtt-192.svg', '/icons/novtt-512.svg']

self.addEventListener('install', event => {
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE_NAME)
    for (const url of PUBLIC_SHELL) {
      const response = await fetch(new Request(url, { credentials: 'omit', cache: 'reload' }))
      if (response.ok && response.type === 'basic') {
        if (url === '/') {
          const shell = await response.clone().text()
          const assetPaths = [...shell.matchAll(/(?:src|href)="(\/assets\/[a-zA-Z0-9._-]+\.(?:js|css))"/g)].map(match => match[1])
          for (const assetPath of assetPaths) {
            const asset = await fetch(new Request(assetPath, { credentials: 'omit', cache: 'reload' }))
            if (asset.ok && asset.type === 'basic') await cache.put(assetPath, asset)
          }
        }
        await cache.put(url, response)
      }
    }
    await self.skipWaiting()
  })())
})

self.addEventListener('activate', event => {
  event.waitUntil((async () => {
    const names = await caches.keys()
    await Promise.all(names.filter(name => name.startsWith('novtt-player-shell-') && name !== CACHE_NAME).map(name => caches.delete(name)))
    await self.clients.claim()
  })())
})

self.addEventListener('fetch', event => {
  const request = event.request
  if (request.method !== 'GET' || request.credentials === 'include') return
  const url = new URL(request.url)
  if (url.origin !== self.location.origin) return
  if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/auth/') ||
      url.pathname.startsWith('/sanctum/') || url.pathname.startsWith('/broadcasting/') ||
      url.pathname.startsWith('/storage/')) return

  if (request.mode === 'navigate') {
    // The root shell is a public static file, fetched without credentials.
    event.respondWith((async () => {
      try {
        return await fetch(new Request('/', { credentials: 'omit', cache: 'no-store' }))
      } catch {
        const cached = await caches.match('/')
        return cached || Response.error()
      }
    })())
    return
  }

  if (!/^\/assets\/[a-zA-Z0-9._-]+\.(js|css)$/.test(url.pathname) &&
      !PUBLIC_SHELL.includes(url.pathname)) return

  event.respondWith((async () => {
    const cache = await caches.open(CACHE_NAME)
    const cached = await cache.match(request)
    if (cached) return cached
    const response = await fetch(request)
    if (response.ok && response.type === 'basic' && !response.headers.has('set-cookie') &&
        !/private|no-store/i.test(response.headers.get('cache-control') || '')) {
      await cache.put(request, response.clone())
    }
    return response
  })())
})
