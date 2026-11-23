import { computed, inject, onBeforeUnmount, provide, ref, shallowRef, watch, type InjectionKey, type Ref } from 'vue'
import { nextStrike, type Strike } from './storm'
import { playThunder } from './thunder'
import { currentHour, DEFAULT_ATMOSPHERE, skyState, tweenHour, tweenMix, weatherMix, type Atmosphere, type SkyPalette, type SkyState, type WeatherMix } from './atmosphere'

export const SKY: InjectionKey<Ref<SkyState | null>> = Symbol('novtt.sky')

/** Lightning strikes as they happen; renderers (scene canvas, screen flash) subscribe. */
export interface StormBus { subscribe: (fn: (s: Strike) => void) => () => void }
export const STORM: InjectionKey<StormBus> = Symbol('novtt.storm')

const reducedMotion = (): boolean => typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches

/** Reads the active theme's sky tokens (they change when the campaign theme changes). */
export function readPalette(root: HTMLElement = document.documentElement): SkyPalette {
  const css = getComputedStyle(root)
  const v = (name: string, fallback: string) => css.getPropertyValue(name).trim() || fallback
  return {
    nightTop: v('--art-sky-top', '#0f1020'), nightBottom: v('--art-sky-bottom', '#2b2560'),
    dawnTop: v('--art-dawn-top', '#3b3a6b'), dawnBottom: v('--art-dawn-bottom', '#e0915a'),
    dayTop: v('--art-day-top', '#4f86c6'), dayBottom: v('--art-day-bottom', '#b9d6ee'),
    light: v('--art-light', '#edeaff'), glow: v('--art-glow', '#ffb224'), fog: v('--art-fog', '#a9a6c8'),
  }
}

/**
 * Animated sky for a live table. Follows the session atmosphere: changes tween over the
 * master's transition time (instant with reduced motion), and the hour follows the clock when
 * time flows. Frames run only while tweening; the clock is sampled every few seconds.
 * Provides the state to every SceneArt below (master banner, player header).
 */
export function provideAtmosphere(source: Ref<Atmosphere | null | undefined>): Ref<SkyState | null> {
  const palette = shallowRef<SkyPalette | null>(typeof document === 'undefined' ? null : readPalette())
  const target = computed(() => source.value ?? DEFAULT_ATMOSPHERE)
  const shown = ref({ hour: currentHour(target.value, Date.now()), mix: weatherMix(target.value.weather, target.value.intensity, target.value.fog) })
  let frame = 0
  let clock: ReturnType<typeof setInterval> | undefined

  function tweenTo(a: Atmosphere): void {
    cancelAnimationFrame(frame)
    const from = { hour: shown.value.hour, mix: { ...shown.value.mix } as WeatherMix }
    const toMix = weatherMix(a.weather, a.intensity, a.fog)
    const ms = reducedMotion() ? 0 : Math.max(0, a.transition_seconds) * 1000
    const start = performance.now()
    const step = (now: number) => {
      const t = ms === 0 ? 1 : Math.min(1, (now - start) / ms)
      const e = t < 0.5 ? 2 * t * t : 1 - (-2 * t + 2) ** 2 / 2
      shown.value = { hour: tweenHour(from.hour, currentHour(a, Date.now()), e), mix: tweenMix(from.mix, toMix, e) }
      if (t < 1) frame = requestAnimationFrame(step)
    }
    if (ms === 0) step(start)
    else frame = requestAnimationFrame(step)
  }

  watch(target, a => tweenTo(a))
  clock = setInterval(() => {
    const a = target.value
    if (a.time_scale > 0) shown.value = { ...shown.value, hour: currentHour(a, Date.now()) }
  }, 3000)

  // Campaign theme switches recolour the sky.
  let observer: MutationObserver | null = null
  if (typeof MutationObserver !== 'undefined' && typeof document !== 'undefined') {
    observer = new MutationObserver(() => { palette.value = readPalette() })
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-campaign-theme', 'data-theme', 'class', 'style'] })
  }
  // Lightning: the same strikes on every screen (seeded by the atmosphere), thunder after the
  // flash by distance. Reduced motion keeps the thunder but no visual flash is scheduled.
  const listeners = new Set<(s: Strike) => void>()
  let strikeTimer: ReturnType<typeof setTimeout> | undefined
  function scheduleStrike(): void {
    clearTimeout(strikeTimer)
    const a = target.value
    const storm = a.weather === 'storm' ? a.intensity : 0
    const start = a.set_at ? Date.parse(a.set_at) : Date.now()
    const strike = nextStrike(`${a.set_at ?? 'local'}#${a.revision ?? 0}`, start, storm, Date.now())
    if (!strike) return
    strikeTimer = setTimeout(() => {
      if (!document.hidden) {
        if (!reducedMotion()) listeners.forEach(fn => fn(strike))
        setTimeout(() => playThunder(strike.distance, storm), 250 + strike.distance * 3500)
      }
      scheduleStrike()
    }, Math.max(0, strike.at - Date.now()))
  }
  watch(target, scheduleStrike, { immediate: true })
  provide(STORM, { subscribe: fn => { listeners.add(fn); return () => listeners.delete(fn) } })

  onBeforeUnmount(() => { cancelAnimationFrame(frame); clearInterval(clock); clearTimeout(strikeTimer); observer?.disconnect() })

  const sky = computed<SkyState | null>(() => (palette.value ? skyState(shown.value.hour, shown.value.mix, palette.value) : null))
  provide(SKY, sky)
  return sky
}

export const useSky = (): Ref<SkyState | null> => inject(SKY, ref(null))
export const useStorm = (): StormBus | null => inject(STORM, null)
