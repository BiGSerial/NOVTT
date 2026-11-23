<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { SkyState } from '../../live/atmosphere'

/**
 * Weather particles over a scene: drifting clouds, slanted rain, falling snow and lightning.
 * One canvas, bounded particle pools scaled by area and intensity, device pixel ratio capped at
 * 2, paused while the tab is hidden or the scene is off screen. With reduced motion it draws a
 * single still frame (no movement, no flashes).
 */
const props = defineProps<{ sky: SkyState }>()
const canvas = ref<HTMLCanvasElement | null>(null)

interface Drop { x: number; y: number; v: number; l: number }
interface Flake { x: number; y: number; v: number; r: number; p: number }
interface Cloud { x: number; y: number; s: number; v: number }

let ctx: CanvasRenderingContext2D | null = null
let frame = 0
let last = 0
let w = 0
let h = 0
let visible = true
let flash = 0
let nextBolt = 0
let bolt: [number, number][] | null = null
const drops: Drop[] = []
const flakes: Flake[] = []
const clouds: Cloud[] = []
const still = typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches
let resize: ResizeObserver | null = null
let seen: IntersectionObserver | null = null

const rnd = (a: number, b: number) => a + Math.random() * (b - a)

function fit(): void {
  const c = canvas.value
  if (!c) return
  const dpr = Math.min(2, window.devicePixelRatio || 1)
  w = c.clientWidth
  h = c.clientHeight
  c.width = Math.max(1, Math.round(w * dpr))
  c.height = Math.max(1, Math.round(h * dpr))
  ctx = c.getContext('2d')
  ctx?.setTransform(dpr, 0, 0, dpr, 0, 0)
}

function populate(): void {
  const area = Math.max(1, (w * h) / 100000)
  const want = (n: number) => Math.round(Math.min(n * area, n * 8))
  const s = props.sky
  resizePool(drops, want(60 * s.rain + 40 * s.storm), () => ({ x: rnd(-50, w + 50), y: rnd(-h, h), v: rnd(700, 1000), l: rnd(16, 30) }))
  resizePool(flakes, want(70 * s.snow), () => ({ x: rnd(0, w), y: rnd(-h, h), v: rnd(25, 60), r: rnd(1, 2.8), p: rnd(0, Math.PI * 2) }))
  resizePool(clouds, Math.round(Math.min(10, 2 + 8 * s.clouds)), () => ({ x: rnd(-0.3, 1.1) * w, y: rnd(0.02, 0.45) * h, s: rnd(0.6, 1.4), v: rnd(6, 16) }))
}

function resizePool<T>(pool: T[], n: number, make: () => T): void {
  while (pool.length < n) pool.push(make())
  pool.length = n
}

function draw(dt: number): void {
  if (!ctx) return
  const s = props.sky
  ctx.clearRect(0, 0, w, h)
  const wind = 0.18 + s.storm * 0.25

  // Clouds: soft overlapping blobs in a grey that follows the sky.
  if (s.clouds > 0.02) {
    for (const c of clouds) {
      c.x += c.v * (1 + s.storm * 2) * dt
      if (c.x > w * 1.25) { c.x = -w * 0.35; c.y = rnd(0.02, 0.45) * h }
      const r = 90 * c.s * Math.max(0.6, h / 300)
      for (let k = 0; k < 4; k++) {
        const bx = c.x + k * r * 0.55
        const by = c.y + Math.sin(k * 1.7) * r * 0.18
        const g = ctx.createRadialGradient(bx, by, 0, bx, by, r)
        const alpha = 0.22 * s.clouds
        g.addColorStop(0, `rgba(205, 208, 216, ${alpha})`)
        g.addColorStop(1, 'rgba(205, 208, 216, 0)')
        ctx.fillStyle = g
        ctx.beginPath()
        ctx.arc(bx, by, r, 0, Math.PI * 2)
        ctx.fill()
      }
    }
  }

  // Rain: slanted streaks.
  if (drops.length) {
    ctx.strokeStyle = `rgba(205, 218, 235, ${0.4 + 0.35 * s.rain})`
    ctx.lineWidth = 1.4
    ctx.beginPath()
    for (const d of drops) {
      d.y += d.v * dt
      d.x += d.v * wind * dt
      if (d.y > h) { d.y = rnd(-40, -10); d.x = rnd(-60, w) }
      ctx.moveTo(d.x, d.y)
      ctx.lineTo(d.x - d.l * wind, d.y - d.l)
    }
    ctx.stroke()
  }

  // Snow: slow flakes that sway.
  if (flakes.length) {
    ctx.fillStyle = 'rgba(245, 247, 252, .85)'
    for (const f of flakes) {
      f.y += f.v * dt
      f.p += dt
      f.x += Math.sin(f.p) * 12 * dt
      if (f.y > h) { f.y = rnd(-20, -4); f.x = rnd(0, w) }
      ctx.beginPath()
      ctx.arc(f.x, f.y, f.r, 0, Math.PI * 2)
      ctx.fill()
    }
  }

  // Lightning: a jagged bolt and a flash that fades (never with reduced motion).
  if (s.storm > 0.05 && !still) {
    nextBolt -= dt
    if (nextBolt <= 0) {
      nextBolt = rnd(3, 9) / (0.4 + s.storm)
      flash = 1
      let x = rnd(0.15, 0.85) * w
      bolt = [[x, 0]]
      for (let y = 0; y < h * 0.75; y += rnd(14, 30)) { x += rnd(-18, 18); bolt.push([x, y]) }
    }
    if (flash > 0) {
      ctx.fillStyle = `rgba(230, 235, 255, ${0.35 * flash * s.storm})`
      ctx.fillRect(0, 0, w, h)
      if (bolt && flash > 0.55) {
        ctx.strokeStyle = `rgba(245, 248, 255, ${flash})`
        ctx.lineWidth = 2
        ctx.beginPath()
        bolt.forEach(([bx, by], i) => (i ? ctx!.lineTo(bx, by) : ctx!.moveTo(bx, by)))
        ctx.stroke()
      }
      flash = Math.max(0, flash - dt * 2.6)
    }
  }
}

function loop(now: number): void {
  const dt = Math.min(0.05, (now - last) / 1000 || 0)
  last = now
  if (visible && !document.hidden) draw(dt)
  frame = requestAnimationFrame(loop)
}

onMounted(() => {
  fit()
  populate()
  if (still) {
    draw(0)
  } else {
    last = performance.now()
    frame = requestAnimationFrame(loop)
  }
  if (typeof ResizeObserver !== 'undefined' && canvas.value) {
    resize = new ResizeObserver(() => { fit(); populate(); if (still) draw(0) })
    resize.observe(canvas.value)
  }
  if (typeof IntersectionObserver !== 'undefined' && canvas.value) {
    seen = new IntersectionObserver(entries => { visible = entries.some(e => e.isIntersecting) })
    seen.observe(canvas.value)
  }
})
watch(() => [props.sky.rain, props.sky.snow, props.sky.clouds, props.sky.storm].map(v => Math.round(v * 20)).join(), () => { populate(); if (still) draw(0) })
onBeforeUnmount(() => { cancelAnimationFrame(frame); resize?.disconnect(); seen?.disconnect() })
</script>

<template>
  <canvas ref="canvas" class="weather-canvas" aria-hidden="true" />
</template>
