// Lightning schedule shared by every screen: strikes are derived from a seed (the atmosphere's
// set_at and revision), so all players see and hear the same strike at the same moment without
// any extra network traffic. Pure functions; the provider in useAtmosphere drives the timers.

export interface Strike {
  /** Wall-clock time of the first flash (ms since epoch). */
  at: number
  /** Horizontal position across the sky, 0..1. */
  x: number
  /** 0 = overhead, 1 = far away: dims the flash and delays the thunder. */
  distance: number
  /** Flicker pulses (ms after `at`) and their strength; spaced ≥ 350 ms (≤ 3 flashes per second). */
  pulses: { t: number; power: number }[]
  /** Seed for the bolt's shape. */
  seed: number
}

export function hashString(text: string): number {
  let h = 2166136261
  for (let i = 0; i < text.length; i++) {
    h ^= text.charCodeAt(i)
    h = Math.imul(h, 16777619)
  }
  return h >>> 0
}

export function prng(seed: number): () => number {
  let a = seed >>> 0
  return () => {
    a = (a + 0x6d2b79f5) >>> 0
    let t = a
    t = Math.imul(t ^ (t >>> 15), t | 1)
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61)
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296
  }
}

/** Mean seconds between strikes: a light storm rumbles now and then, a heavy one keeps striking. */
export function meanGap(storm: number): number {
  return 14 - 10 * Math.max(0, Math.min(1, storm))
}

/** The first strike after `fromMs` in the sequence that started at `startMs`. */
export function nextStrike(seedText: string, startMs: number, storm: number, fromMs: number): Strike | null {
  if (storm <= 0.02) return null
  const rnd = prng(hashString(seedText))
  const gap = meanGap(storm)
  let at = startMs
  for (let i = 0; i < 100000; i++) {
    at += (gap * (0.35 + rnd() * 1.3)) * 1000
    const x = 0.08 + rnd() * 0.84
    const distance = rnd() ** (0.6 + storm)
    const count = rnd() < 0.55 ? 2 : rnd() < 0.5 ? 1 : 3
    const pulses = [{ t: 0, power: 1 }]
    for (let k = 1; k < count; k++) pulses.push({ t: pulses[k - 1].t + 350 + rnd() * 250, power: 0.45 + rnd() * 0.5 })
    const seed = Math.floor(rnd() * 2 ** 31)
    if (at > fromMs) return { at, x, distance, pulses, seed }
  }
  return null
}

/** A jagged bolt with branches (midpoint displacement), in 0..1 coordinates of the sky. */
export function boltPath(strike: Strike, reach = 0.8): { main: [number, number][]; branches: [number, number][][] } {
  const rnd = prng(strike.seed)
  const split = (a: [number, number], b: [number, number], spread: number, depth: number): [number, number][] => {
    if (depth === 0) return [a, b]
    const mid: [number, number] = [(a[0] + b[0]) / 2 + (rnd() - 0.5) * spread, (a[1] + b[1]) / 2 + (rnd() - 0.5) * spread * 0.25]
    return [...split(a, mid, spread / 2, depth - 1).slice(0, -1), ...split(mid, b, spread / 2, depth - 1)]
  }
  const end: [number, number] = [strike.x + (rnd() - 0.5) * 0.18, reach * (0.75 + rnd() * 0.25)]
  const main = split([strike.x, -0.02], end, 0.22, 6)
  const branches: [number, number][][] = []
  for (let i = 0; i < 3 + Math.floor(rnd() * 3); i++) {
    const from = main[4 + Math.floor(rnd() * (main.length - 8))]
    const to: [number, number] = [from[0] + (rnd() - 0.5) * 0.25, from[1] + 0.08 + rnd() * 0.2]
    branches.push(split(from, to, 0.08, 4))
  }
  return { main, branches }
}
