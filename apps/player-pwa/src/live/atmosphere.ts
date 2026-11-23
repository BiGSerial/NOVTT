// Atmosphere engine: the master sets the hour, the weather and how fast time passes; every screen
// renders the same sky from that state. Pure functions here (no DOM, no timers) so the look is
// deterministic and testable; useAtmosphere.ts animates between states and follows the clock.

export const WEATHERS = ['clear', 'clouds', 'rain', 'storm', 'fog', 'snow'] as const
export type Weather = (typeof WEATHERS)[number]

/** Session atmosphere as stored by the server (PUT /sessions/{id}/atmosphere). */
export interface Atmosphere {
  hour: number
  weather: Weather
  intensity: number
  /** Game minutes that pass per real minute; 0 freezes the clock. */
  time_scale: number
  transition_seconds: number
  set_at?: string
  revision?: number
}

/** Tonight, clear: the look every scene had before the master touched the weather. */
export const DEFAULT_ATMOSPHERE: Atmosphere = { hour: 21, weather: 'clear', intensity: 0.5, time_scale: 0, transition_seconds: 4 }

export const WEATHER_LABELS: Record<Weather, string> = {
  clear: 'Limpo', clouds: 'Nublado', rain: 'Chuva', storm: 'Tempestade', fog: 'Neblina', snow: 'Neve',
}

export const TIME_PRESETS = [
  { hour: 3, label: 'Madrugada' },
  { hour: 6, label: 'Amanhecer' },
  { hour: 12, label: 'Meio-dia' },
  { hour: 18, label: 'Entardecer' },
  { hour: 22, label: 'Noite' },
] as const

export function phaseOf(hour: number): string {
  const h = ((hour % 24) + 24) % 24
  if (h < 4.5) return 'Madrugada'
  if (h < 7.5) return 'Amanhecer'
  if (h < 11.5) return 'Manhã'
  if (h < 14) return 'Meio-dia'
  if (h < 17) return 'Tarde'
  if (h < 19.5) return 'Entardecer'
  return 'Noite'
}

export function formatHour(hour: number): string {
  const h = ((hour % 24) + 24) % 24
  const minutes = Math.round(h * 60) % (24 * 60)
  return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`
}

/** The hour now, following the clock when time flows (all clients agree up to their clock skew). */
export function currentHour(a: Atmosphere, nowMs: number): number {
  if (!a.time_scale || !a.set_at) return a.hour
  const minutes = Math.max(0, (nowMs - Date.parse(a.set_at)) / 60000)
  return (((a.hour + (a.time_scale * minutes) / 60) % 24) + 24) % 24
}

/** How much of each weather is present (0..1); transitions cross-fade these amounts. */
export type WeatherMix = Record<Weather, number>
export function weatherMix(weather: Weather, intensity: number): WeatherMix {
  const mix = { clear: 0, clouds: 0, rain: 0, storm: 0, fog: 0, snow: 0 } as WeatherMix
  mix[weather] = weather === 'clear' ? 1 : Math.max(0.05, Math.min(1, intensity))
  return mix
}

/** Theme colours the sky blends between (read from CSS tokens --art-*). */
export interface SkyPalette {
  nightTop: string; nightBottom: string
  dawnTop: string; dawnBottom: string
  dayTop: string; dayBottom: string
  light: string; glow: string; fog: string
}

export interface Celestial { x: number; y: number; opacity: number }

/** Everything a renderer needs, in the 1200×400 scene coordinates of SceneArt. */
export interface SkyState {
  hour: number
  top: string
  bottom: string
  /** 0 at night, up to ~0.3 at noon: how much daylight washes over the night-drawn landscape. */
  daylight: number
  stars: number
  sun: Celestial
  moon: Celestial
  clouds: number
  rain: number
  storm: number
  fog: number
  snow: number
  fogColor: string
  lightColor: string
}

// Hour keyframes: which palette band dominates (0 = night, 1 = dawn/dusk, 2 = day).
const KEYS: [number, number][] = [[0, 0], [4.5, 0], [6, 1], [8, 2], [16.5, 2], [18.5, 1], [20, 0], [24, 0]]

function band(hour: number): { night: number; dawn: number; day: number } {
  const h = ((hour % 24) + 24) % 24
  for (let i = 0; i < KEYS.length - 1; i++) {
    const [h0, b0] = KEYS[i]
    const [h1, b1] = KEYS[i + 1]
    if (h >= h0 && h <= h1) {
      const t = h1 === h0 ? 0 : smooth((h - h0) / (h1 - h0))
      const weights = { night: 0, dawn: 0, day: 0 }
      const name = (b: number) => (b === 0 ? 'night' : b === 1 ? 'dawn' : 'day') as keyof typeof weights
      weights[name(b0)] += 1 - t
      weights[name(b1)] += t
      return weights
    }
  }
  return { night: 1, dawn: 0, day: 0 }
}

const smooth = (t: number) => t * t * (3 - 2 * t)

/** A body on an arc across the sky: rises at `rise`, sets at `rise + 12`. */
function arc(hour: number, rise: number): Celestial {
  const t = ((((hour - rise) % 24) + 24) % 24) / 12
  if (t > 1) return { x: 0, y: 0, opacity: 0 }
  const x = 80 + 1040 * t
  const y = 330 - 270 * Math.sin(Math.PI * t)
  const edge = Math.min(1, Math.min(t, 1 - t) * 8) // fade near the horizon
  return { x, y, opacity: edge }
}

export function skyState(hour: number, mix: WeatherMix, p: SkyPalette): SkyState {
  const w = band(hour)
  let top = blend3(p.nightTop, p.dawnTop, p.dayTop, w)
  let bottom = blend3(p.nightBottom, p.dawnBottom, p.dayBottom, w)
  // Overcast skies lose colour toward grey; storms also darken.
  const overcast = Math.min(1, mix.clouds * 0.55 + mix.rain * 0.65 + mix.storm * 0.85 + mix.fog * 0.5 + mix.snow * 0.5)
  const grey = mixHex('#7d8088', '#24262b', w.night)
  top = mixHex(top, grey, overcast * 0.6)
  bottom = mixHex(bottom, grey, overcast * 0.5)
  if (mix.storm) {
    top = mixHex(top, '#0b0c10', mix.storm * 0.45)
    bottom = mixHex(bottom, '#15161c', mix.storm * 0.35)
  }
  const veil = 1 - overcast * 0.85
  const sun = arc(hour, 6)
  const moon = arc(hour, 18)
  return {
    hour,
    top,
    bottom,
    daylight: (w.day * 0.3 + w.dawn * 0.12) * (1 - mix.storm * 0.6),
    stars: w.night * veil,
    sun: { ...sun, opacity: sun.opacity * veil },
    moon: { ...moon, opacity: moon.opacity * Math.max(0.15, veil) * (0.35 + 0.65 * (w.night + w.dawn * 0.5)) },
    clouds: Math.min(1, mix.clouds + mix.rain * 0.8 + mix.storm + mix.snow * 0.6 + mix.fog * 0.2),
    rain: Math.min(1, mix.rain + mix.storm),
    storm: mix.storm,
    fog: mix.fog,
    snow: mix.snow,
    fogColor: p.fog,
    lightColor: p.light,
  }
}

function blend3(a: string, b: string, c: string, w: { night: number; dawn: number; day: number }): string {
  const [ra, ga, ba] = rgb(a)
  const [rb, gb, bb] = rgb(b)
  const [rc, gc, bc] = rgb(c)
  return hex(ra * w.night + rb * w.dawn + rc * w.day, ga * w.night + gb * w.dawn + gc * w.day, ba * w.night + bb * w.dawn + bc * w.day)
}

export function mixHex(a: string, b: string, t: number): string {
  const [r1, g1, b1] = rgb(a)
  const [r2, g2, b2] = rgb(b)
  const k = Math.max(0, Math.min(1, t))
  return hex(r1 + (r2 - r1) * k, g1 + (g2 - g1) * k, b1 + (b2 - b1) * k)
}

function rgb(color: string): [number, number, number] {
  const c = color.trim().replace('#', '')
  const full = c.length === 3 ? [...c].map(ch => ch + ch).join('') : c.padEnd(6, '0').slice(0, 6)
  const n = Number.parseInt(full, 16)
  return Number.isNaN(n) ? [0, 0, 0] : [(n >> 16) & 255, (n >> 8) & 255, n & 255]
}

function hex(r: number, g: number, b: number): string {
  const c = (v: number) => Math.round(Math.max(0, Math.min(255, v))).toString(16).padStart(2, '0')
  return `#${c(r)}${c(g)}${c(b)}`
}

/** Interpolates two states for transitions; the hour only moves forward (time does not rewind). */
export function tweenHour(from: number, to: number, t: number): number {
  const forward = (((to - from) % 24) + 24) % 24
  return (from + forward * t) % 24
}

export function tweenMix(from: WeatherMix, to: WeatherMix, t: number): WeatherMix {
  const out = {} as WeatherMix
  for (const w of WEATHERS) out[w] = from[w] + (to[w] - from[w]) * t
  return out
}

/** Short narrative line for players when the master changes the weather or the period. */
export function describeChange(before: Atmosphere | null, after: Atmosphere): string | null {
  if (!before || before.weather !== after.weather) {
    return {
      clear: 'O céu se abre.', clouds: 'Nuvens cobrem o céu.', rain: 'Começa a chover.',
      storm: 'Uma tempestade desaba.', fog: 'A névoa avança.', snow: 'Começa a nevar.',
    }[after.weather]
  }
  if (phaseOf(before.hour) !== phaseOf(after.hour)) return `${phaseOf(after.hour)}.`
  return null
}
