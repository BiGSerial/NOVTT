import type { Impact } from './impacts'

/**
 * Dice sounds synthesised with Web Audio (no audio files to download or cache): a short filtered
 * noise burst per impact, scheduled against the animation clock. Optional haptics on Android.
 */
let ctx: AudioContext | null = null
let noise: AudioBuffer | null = null
let bus: GainNode | null = null

function context(): AudioContext | null {
  const Ctor = globalThis.AudioContext ?? (globalThis as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext
  if (!Ctor) return null
  if (!ctx) {
    ctx = new Ctor()
    noise = ctx.createBuffer(1, Math.round(ctx.sampleRate * 0.08), ctx.sampleRate)
    const data = noise.getChannelData(0)
    for (let i = 0; i < data.length; i++) data[i] = (Math.random() * 2 - 1) * (1 - i / data.length) ** 3
  }
  return ctx
}

/** The shared Web Audio context (null without Web Audio); also used by the weather (thunder). */
export function audioContext(): AudioContext | null {
  return context()
}

/** Must be called from the user gesture (click) so browsers allow audio. */
export function unlockAudio(): void {
  const c = context()
  if (c && c.state === 'suspended') void c.resume()
}

export function playImpacts(impacts: Impact[], options: { sound: boolean; haptics: boolean }): () => void {
  const timers: ReturnType<typeof setTimeout>[] = []
  if (options.sound) {
    const c = context()
    if (c && noise) {
      bus?.disconnect()
      bus = c.createGain()
      bus.gain.value = 0.55
      bus.connect(c.destination)
      const start = c.currentTime + 0.02
      for (const hit of impacts) {
        const src = c.createBufferSource()
        src.buffer = noise
        src.playbackRate.value = 0.8 + hit.strength * 0.5
        const band = c.createBiquadFilter()
        band.type = 'bandpass'
        band.frequency.value = 1800 + hit.strength * 1600
        band.Q.value = 1.4
        const gain = c.createGain()
        gain.gain.value = 0.15 + hit.strength * 0.85
        src.connect(band).connect(gain).connect(bus)
        src.start(start + hit.t)
      }
    }
  }
  if (options.haptics && typeof navigator !== 'undefined' && typeof navigator.vibrate === 'function') {
    for (const hit of impacts.filter(h => h.strength > 0.35).slice(0, 6)) {
      timers.push(setTimeout(() => navigator.vibrate(Math.round(8 + hit.strength * 14)), hit.t * 1000))
    }
  }
  const mine = bus
  return () => {
    for (const t of timers) clearTimeout(t)
    if (mine && mine === bus) { mine.disconnect(); bus = null }
  }
}
