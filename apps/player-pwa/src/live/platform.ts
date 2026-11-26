// Platform shell (ADR 0016): one Vue code base, three behaviours.
// - web: a site in a browser tab (desktop or phone): top navigation, page scroll, hover, keyboard.
// - pwa: installed app (iOS / desktop standalone): app bar + bottom tabs on phones, safe areas,
//   no overscroll bounce, no hover-only affordances.
// - android: installed on Android: Material conventions (bottom navigation, 48 dp targets, the
//   system Back closes the open sheet/dialog before leaving the screen, edge-to-edge).
// Form factor (phone / tablet / desktop) is orthogonal and follows the viewport.
import { computed, readonly, ref } from 'vue'

export type Platform = 'web' | 'pwa' | 'android'
export type FormFactor = 'phone' | 'tablet' | 'desktop'

const mq = (q: string): boolean => typeof matchMedia === 'function' && matchMedia(q).matches

export function detectPlatform(ua = typeof navigator === 'undefined' ? '' : navigator.userAgent, standalone = mq('(display-mode: standalone)') || mq('(display-mode: fullscreen)') || (typeof navigator !== 'undefined' && (navigator as { standalone?: boolean }).standalone === true)): Platform {
  if (!standalone) return 'web'
  return /Android/i.test(ua) ? 'android' : 'pwa'
}

export function formFactorOf(width: number): FormFactor {
  if (width < 640) return 'phone'
  if (width < 1024) return 'tablet'
  return 'desktop'
}

const platform = ref<Platform>(detectPlatform())
const width = ref(typeof window === 'undefined' ? 1280 : window.innerWidth)
const form = computed<FormFactor>(() => formFactorOf(width.value))
/** Installed apps on phones/tablets use bottom navigation; the web keeps a top bar. */
const bottomNav = computed(() => platform.value !== 'web' && form.value !== 'desktop')

function apply(): void {
  const root = document.documentElement
  root.dataset.platform = platform.value
  root.dataset.form = form.value
}

let installed = false
/** Starts tracking (once): resize, install state; tags <html data-platform data-form>. */
export function installPlatform(): void {
  if (installed || typeof window === 'undefined') return
  installed = true
  apply()
  window.addEventListener('resize', () => { width.value = window.innerWidth; apply() }, { passive: true })
  if (typeof matchMedia === 'function') matchMedia('(display-mode: standalone)').addEventListener?.('change', () => { platform.value = detectPlatform(); apply() })
}

export function usePlatform() {
  return { platform: readonly(platform), form, bottomNav }
}

// ---- Back button for dialogs (all platforms; essential on Android) -------------------------
// Opening a dialog pushes a history entry with the same URL; Back pops it and closes the
// dialog instead of leaving the screen. Closing by UI pops our own entry.
const stack: Array<() => void> = []
let listening = false
let ignore = 0 // pops we caused ourselves (a dialog closed by its own button)
function onPop(): void {
  if (ignore > 0) { ignore--; return }
  const close = stack.pop()
  close?.()
}
export function pushBack(close: () => void): () => void {
  if (typeof window === 'undefined') return () => undefined
  if (!listening) { window.addEventListener('popstate', onPop); listening = true }
  history.pushState({ novttDialog: stack.length + 1 }, '')
  stack.push(close)
  let released = false
  return () => {
    if (released) return
    released = true
    const i = stack.lastIndexOf(close)
    if (i >= 0) { stack.splice(i, 1); ignore++; history.back() }
  }
}
