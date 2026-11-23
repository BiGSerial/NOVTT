import { computed, inject, onBeforeUnmount, provide, ref, shallowRef, watch, type InjectionKey, type Ref } from 'vue'
import { currentHour, DEFAULT_ATMOSPHERE, skyState, tweenHour, tweenMix, weatherMix, type Atmosphere, type SkyPalette, type SkyState, type WeatherMix } from './atmosphere'

export const SKY: InjectionKey<Ref<SkyState | null>> = Symbol('novtt.sky')

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
  const shown = ref({ hour: currentHour(target.value, Date.now()), mix: weatherMix(target.value.weather, target.value.intensity) })
  let frame = 0
  let clock: ReturnType<typeof setInterval> | undefined

  function tweenTo(a: Atmosphere): void {
    cancelAnimationFrame(frame)
    const from = { hour: shown.value.hour, mix: { ...shown.value.mix } as WeatherMix }
    const toMix = weatherMix(a.weather, a.intensity)
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
  onBeforeUnmount(() => { cancelAnimationFrame(frame); clearInterval(clock); observer?.disconnect() })

  const sky = computed<SkyState | null>(() => (palette.value ? skyState(shown.value.hour, shown.value.mix, palette.value) : null))
  provide(SKY, sky)
  return sky
}

export const useSky = (): Ref<SkyState | null> => inject(SKY, ref(null))
