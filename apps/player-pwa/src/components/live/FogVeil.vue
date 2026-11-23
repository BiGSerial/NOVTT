<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useSky } from '../../live/useAtmosphere'

/**
 * Fog crossing the whole screen, over any weather. Three depths of soft banks drift at
 * different speeds, denser and lower near the ground, slowly thickening and thinning; a ground
 * haze sits under them. Drawn on one full-screen canvas from a pre-rendered puff at ~30 fps,
 * paused in a hidden tab; never intercepts clicks; opacity capped so text stays readable.
 * Reduced motion: one still frame.
 */
const sky = useSky()
const canvas = ref<HTMLCanvasElement | null>(null)
interface Bank { x: number; y: number; r: number; v: number; z: number; phase: number }
const banks: Bank[] = []
let ctx: CanvasRenderingContext2D | null = null
let puff: HTMLCanvasElement | null = null
let puffColour = ''
let frame = 0
let last = 0
let w = 0
let h = 0
let time = 0
const still = typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches
const rnd = (a: number, b: number) => a + Math.random() * (b - a)

function fit(): void {
  const c = canvas.value
  if (!c) return
  // Fog is soft: a quarter of the pixels is plenty and keeps it cheap on phones.
  const scale = 0.5
  w = window.innerWidth
  h = window.innerHeight
  c.width = Math.round(w * scale)
  c.height = Math.round(h * scale)
  ctx = c.getContext('2d')
  ctx?.setTransform(scale, 0, 0, scale, 0, 0)
  seed()
}

function makePuff(colour: string): void {
  if (puff && colour === puffColour) return
  puffColour = colour
  puff = document.createElement('canvas')
  puff.width = puff.height = 256
  const g = puff.getContext('2d')
  if (!g) return
  // Lumpy, not a perfect disc: a few offset gradients.
  for (let i = 0; i < 6; i++) {
    const cx = 128 + rnd(-40, 40)
    const cy = 128 + rnd(-25, 25)
    const r = rnd(70, 125)
    const grad = g.createRadialGradient(cx, cy, 0, cx, cy, r)
    grad.addColorStop(0, `${colour}40`)
    grad.addColorStop(0.6, `${colour}18`)
    grad.addColorStop(1, `${colour}00`)
    g.fillStyle = grad
    g.fillRect(0, 0, 256, 256)
  }
}

function seed(): void {
  banks.length = 0
  const n = Math.round(10 + (w * h) / 90000)
  for (let i = 0; i < n; i++) {
    const z = rnd(0.2, 1)
    banks.push({
      z,
      r: Math.max(w, h) * (0.18 + 0.28 * z),
      x: rnd(-0.3, 1.3) * w,
      // Near banks hang lower: fog pools near the ground.
      y: h * (0.25 + 0.75 * z * rnd(0.55, 1)),
      v: 6 + 22 * z,
      phase: rnd(0, Math.PI * 2),
    })
  }
  banks.sort((a, b) => a.z - b.z)
}

function draw(dt: number): void {
  const s = sky.value
  if (!ctx || !s || !puff) return
  time += dt
  ctx.clearRect(0, 0, w, h)
  const amount = s.fog
  if (amount < 0.02) return
  // Ground haze.
  const haze = ctx.createLinearGradient(0, h * 0.35, 0, h)
  haze.addColorStop(0, `${s.fogColor}00`)
  haze.addColorStop(1, `${s.fogColor}${Math.round(Math.min(0.32, amount * 0.3) * 255).toString(16).padStart(2, '0')}`)
  ctx.fillStyle = haze
  ctx.fillRect(0, 0, w, h)
  for (const b of banks) {
    b.x += b.v * dt
    if (b.x - b.r > w) b.x = -b.r * 1.2
    const breathe = 0.65 + 0.35 * Math.sin(time * 0.12 + b.phase)
    ctx.globalAlpha = Math.min(0.85, amount * (0.35 + 0.45 * b.z) * breathe)
    ctx.drawImage(puff, b.x - b.r, b.y - b.r * 0.55, b.r * 2, b.r * 1.1)
  }
  ctx.globalAlpha = 1
}

function loop(now: number): void {
  const elapsed = now - last
  if (elapsed >= 33) { // ~30 fps is enough for fog
    last = now
    if (!document.hidden) draw(Math.min(0.1, elapsed / 1000))
  }
  frame = requestAnimationFrame(loop)
}

const onResize = (): void => { fit(); if (still) draw(0) }
onMounted(() => {
  fit()
  makePuff(sky.value?.fogColor ?? '#a9a6c8')
  if (still) draw(0)
  else { last = performance.now(); frame = requestAnimationFrame(loop) }
  window.addEventListener('resize', onResize)
})
watch(() => sky.value?.fogColor, c => { if (c) { makePuff(c); if (still) draw(0) } })
watch(() => Math.round((sky.value?.fog ?? 0) * 20), () => { if (still) draw(0) })
onBeforeUnmount(() => { cancelAnimationFrame(frame); window.removeEventListener('resize', onResize) })
</script>

<template>
  <canvas v-show="(sky?.fog ?? 0) > 0.02" ref="canvas" class="fog-veil" aria-hidden="true" />
</template>
