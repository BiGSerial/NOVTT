import { audioContext } from '../dice/sound'

/**
 * Thunder synthesised with Web Audio (no files): a crack for close strikes, then a low rumble
 * that rolls and fades. Plays only once the page's audio was unlocked by a user gesture (same
 * rule as the dice); quiet by design so it never startles.
 */
let rumble: AudioBuffer | null = null

function brownNoise(ctx: AudioContext, seconds: number): AudioBuffer {
  const buffer = ctx.createBuffer(1, Math.round(ctx.sampleRate * seconds), ctx.sampleRate)
  const data = buffer.getChannelData(0)
  let last = 0
  for (let i = 0; i < data.length; i++) {
    last = (last + 0.02 * (Math.random() * 2 - 1)) / 1.02
    data[i] = last * 3.5
  }
  return buffer
}

export function playThunder(distance: number, storm: number): void {
  const ctx = audioContext()
  if (!ctx || ctx.state !== 'running') return
  rumble ??= brownNoise(ctx, 6)
  const now = ctx.currentTime
  const gain = 0.5 * (0.35 + 0.65 * storm) * (1 - distance * 0.6)
  const out = ctx.createGain()
  out.connect(ctx.destination)

  const body = ctx.createBufferSource()
  body.buffer = rumble
  body.playbackRate.value = 0.8 + Math.random() * 0.3
  const low = ctx.createBiquadFilter()
  low.type = 'lowpass'
  low.frequency.setValueAtTime(distance < 0.3 ? 900 : 380, now)
  low.frequency.exponentialRampToValueAtTime(120, now + 3.5)
  body.connect(low).connect(out)
  out.gain.setValueAtTime(0.0001, now)
  out.gain.exponentialRampToValueAtTime(gain, now + (distance < 0.3 ? 0.04 : 0.6))
  // Rolling: a few swells while it fades.
  for (let k = 1; k <= 3; k++) out.gain.exponentialRampToValueAtTime(gain * (0.9 - k * 0.18), now + 0.6 + k * 0.7)
  out.gain.exponentialRampToValueAtTime(0.0001, now + 5.5)
  body.start(now)
  body.stop(now + 5.8)
}
