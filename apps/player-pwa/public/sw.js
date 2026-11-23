/* NOVTT: cache only the public application shell and fingerprinted build assets.
 * Never cache /api, auth endpoints, credentialed requests or sensitive responses.
 */
// __BUILD_ID__ is replaced at build time (vite.config.ts) with a hash of the emitted files,
// so every release gets its own cache and the old one is deleted on activate.
const BUILD_ID = '__BUILD_ID__'
const CACHE_PREFIX = 'novtt-player-shell-'
const CACHE_NAME = CACHE_PREFIX + BUILD_ID
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
    await Promise.all(names.filter(name => name.startsWith(CACHE_PREFIX) && name !== CACHE_NAME).map(name => caches.delete(name)))
    await self.clients.claim()
  })())
})

self.addEventListener('fetch', event => {
  const request = event.request
  // Navigations and plain stylesheet/script loads use credentials mode 'include' by spec, so
  // that flag cannot be used as a filter. Safety comes from the path deny list below, from
  // only caching an explicit allow list, and from fetching the shell with credentials omitted.
  if (request.method !== 'GET') return
  const url = new URL(request.url)
  if (url.origin !== self.location.origin) return
  if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/auth/') ||
      url.pathname.startsWith('/sanctum/') || url.pathname.startsWith('/broadcasting/') ||
      url.pathname.startsWith('/storage/')) return

  if (request.mode === 'navigate') {
    // The root shell is a public static file, fetched without credentials.
    event.respondWith((async () => {
      try {
        const response = await fetch(new Request('/', { credentials: 'omit', cache: 'no-store' }))
        if (response.ok && response.type === 'basic') {
          const cache = await caches.open(CACHE_NAME)
          await cache.put('/', response.clone())
        }
        return response
      } catch {
        const cached = await caches.match('/')
        return cached || Response.error()
      }
    })())
    return
  }

  // Fingerprinted build assets, including the lazily loaded 3D dice chunks and physics WebAssembly.
  if (!/^\/assets\/[a-zA-Z0-9._-]+\.(js|css|wasm)$/.test(url.pathname) &&
      !PUBLIC_SHELL.includes(url.pathname)) return

  event.respondWith((async () => {
    const cache = await caches.open(CACHE_NAME)
    const cached = await cache.match(request)
    if (cached) return cached
    // Fetch without credentials: only public, allow-listed static files reach this point.
    const response = await fetch(new Request(request, { credentials: 'omit' }))
    if (response.ok && response.type === 'basic' && !response.headers.has('set-cookie') &&
        !/private|no-store/i.test(response.headers.get('cache-control') || '')) {
      await cache.put(request, response.clone())
    }
    return response
  })())
})
