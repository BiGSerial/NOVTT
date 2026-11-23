<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { mixHex, type SkyState } from '../../live/atmosphere'
import { boltPath, type Strike } from '../../live/storm'
import { useStorm } from '../../live/useAtmosphere'

/**
 * Weather over a scene's sky: a cloud deck of pre-rendered volumetric clouds (lit on top, shaded
 * below, lit by lightning), rain in two depths with gusts and splashes, snow in depth, and
 * branching lightning with glow and flicker. One canvas, bounded pools scaled by area and
 * intensity, device pixel ratio capped at 2, paused off screen or in a hidden tab. Reduced
 * motion draws one still frame and no lightning.
 */
const props = defineProps<{ sky: SkyState }>()
const canvas = ref<HTMLCanvasElement | null>(null)
const storm = useStorm()

interface Drop { x: number; y: number; v: number; l: number; near: boolean }
interface Flake { x: number; y: number; v: number; r: number; p: number; z: number }
interface Cloud { x: number; y: number; w: number; v: number; sprite: number; z: number }
interface Splash { x: number; y: number; t: number }
interface Flash { strike: Strike; start: number; bolt: ReturnType<typeof boltPath> }

let ctx: CanvasRenderingContext2D | null = null
let frame = 0
let last = 0
let time = 0
let w = 0
let h = 0
let visible = true
const drops: Drop[] = []
const flakes: Flake[] = []
const clouds: Cloud[] = []
const splashes: Splash[] = []
let flash: Flash | null = null
let sprites: HTMLCanvasElement[] = []
let spriteKey = ''
const still = typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches
let resize: ResizeObserver | null = null
let seen: IntersectionObserver | null = null
let unsubscribe: (() => void) | null = null

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

/** Cloud colours follow the light: warm-lit by day, moonlit grey at night, near-black in storms. */
function cloudColours(): { lit: string; shade: string } {
  const s = props.sky
  const day = Math.min(1, s.daylight / 0.3)
  let lit = mixHex(mixHex('#3a3d48', s.lightColor, 0.25), mixHex('#f4f1ea', s.bottom, 0.25), day)
  let shade = mixHex('#14151b', mixHex('#7c8090', s.top, 0.3), day)
  lit = mixHex(lit, '#2a2c33', s.storm * 0.6 + s.rain * 0.25)
  shade = mixHex(shade, '#0d0e12', s.storm * 0.7 + s.rain * 0.3)
  return { lit, shade }
}

/** A few cloud shapes drawn once per colour change (cheap to blit every frame). */
function buildSprites(): void {
  const { lit, shade } = cloudColours()
  const key = `${lit}${shade}`
  if (key === spriteKey && sprites.length) return
  spriteKey = key
  sprites = []
  for (let k = 0; k < 4; k++) {
    const sw = 520
    const sh = 240
    const c = document.createElement('canvas')
    c.width = sw
    c.height = sh
    const g = c.getContext('2d')
    if (!g) continue
    if ('filter' in g) g.filter = 'blur(10px)'
    const puffs = 9 + k * 2
    for (let i = 0; i < puffs; i++) {
      const px = sw * (0.15 + (i / puffs) * 0.7) + rnd(-20, 20)
      const r = rnd(45, 85) * (1 - Math.abs(i / puffs - 0.5) * 0.8)
      const py = sh * 0.58 - r * rnd(0.2, 0.55)
      const grad = g.createRadialGradient(px - r * 0.2, py - r * 0.45, r * 0.1, px, py, r)
      grad.addColorStop(0, lit)
      grad.addColorStop(0.55, mixHex(lit, shade, 0.45))
      grad.addColorStop(1, shade)
      g.fillStyle = grad
      g.beginPath()
      g.arc(px, py, r, 0, Math.PI * 2)
      g.fill()
    }
    // Flat, shaded base.
    const base = g.createLinearGradient(0, sh * 0.45, 0, sh * 0.75)
    base.addColorStop(0, `${shade}00`)
    base.addColorStop(1, shade)
    g.globalCompositeOperation = 'source-atop'
    g.fillStyle = base
    g.fillRect(0, 0, sw, sh)
    sprites.push(c)
  }
}

function populate(): void {
  const s = props.sky
  const area = Math.max(1, (w * h) / 100000)
  const cap = (n: number) => Math.round(Math.min(n * area, n * 8))
  resizePool(drops, cap(70 * s.rain + 50 * s.storm), () => ({ x: rnd(-80, w + 80), y: rnd(-h, h), v: 0, l: 0, near: false }))
  drops.forEach((d, i) => { d.near = i % 3 === 0; d.v = d.near ? rnd(1100, 1400) : rnd(650, 850); d.l = d.near ? rnd(22, 36) : rnd(10, 18) })
  resizePool(flakes, cap(80 * s.snow), () => ({ x: rnd(0, w), y: rnd(-h, h), v: 0, r: 0, p: rnd(0, Math.PI * 2), z: rnd(0.3, 1) }))
  flakes.forEach(f => { f.v = 18 + 45 * f.z; f.r = 0.8 + 2.4 * f.z })
  const cover = s.clouds
  resizePool(clouds, cover > 0.02 ? Math.round(3 + 9 * cover) : 0, () => ({ x: rnd(-0.4, 1.1) * w, y: 0, w: 0, v: 0, sprite: Math.floor(rnd(0, 4)), z: rnd(0.5, 1) }))
  clouds.forEach(c => { c.w = w * (0.35 + 0.35 * c.z) * (0.8 + cover * 0.5); c.y = h * (0.42 - 0.38 * c.z) - c.w * 0.18; c.v = (8 + 18 * c.z) * (1 + s.storm * 2.5) })
  clouds.sort((a, b) => a.z - b.z)
  buildSprites()
}

function resizePool<T>(pool: T[], n: number, make: () => T): void {
  while (pool.length < n) pool.push(make())
  pool.length = n
}

function strikeLight(now: number): number {
  if (!flash) return 0
  const { pulses, distance } = flash.strike
  const elapsed = now - flash.start
  let light = 0
  for (const p of pulses) {
    const dt = elapsed - p.t
    if (dt >= 0 && dt < 340) light = Math.max(light, p.power * (dt < 70 ? 1 : Math.exp(-(dt - 70) / 110)))
  }
  if (elapsed > (pulses.at(-1)?.t ?? 0) + 480) flash = null
  return light * (1 - distance * 0.55)
}

function draw(dt: number, now: number): void {
  if (!ctx) return
  const s = props.sky
  time += dt
  ctx.clearRect(0, 0, w, h)
  const light = flash ? strikeLight(now) : 0
  const gust = Math.sin(time * 0.35) * 0.08 + Math.sin(time * 1.3) * 0.04 * s.storm
  const wind = 0.15 + s.storm * 0.3 + gust

  // Overcast cover: the whole sky goes grey before individual clouds are drawn.
  if (s.clouds > 0.25) {
    const { shade } = cloudColours()
    const cover = ctx.createLinearGradient(0, 0, 0, h * 0.85)
    const a = Math.min(0.85, (s.clouds - 0.25) * 1.1)
    cover.addColorStop(0, `${shade}${Math.round(a * 255).toString(16).padStart(2, '0')}`)
    cover.addColorStop(1, `${shade}00`)
    ctx.fillStyle = cover
    ctx.fillRect(0, 0, w, h)
  }

  // Clouds, far to near; lightning lights them from inside.
  for (const c of clouds) {
    c.x += c.v * dt
    if (c.x > w + c.w * 0.2) c.x = -c.w * 1.1
    const sprite = sprites[c.sprite]
    if (!sprite) continue
    ctx.globalAlpha = Math.min(1, 0.55 + s.clouds * 0.45) * (0.75 + 0.25 * c.z)
    ctx.drawImage(sprite, c.x, c.y, c.w, c.w * 0.46)
    if (light > 0.05) {
      ctx.globalCompositeOperation = 'lighter'
      ctx.globalAlpha = light * 0.35 * (flash ? 1 - Math.min(1, Math.abs(c.x / w + 0.25 - flash.strike.x) * 1.5) : 0)
      ctx.drawImage(sprite, c.x, c.y, c.w, c.w * 0.46)
      ctx.globalCompositeOperation = 'source-over'
    }
  }
  ctx.globalAlpha = 1

  // Lightning: the sky flashes, then the bolt in front of the clouds it tore through.
  if (flash && light > 0.05) {
    const b = flash.bolt
    const reach = flash.strike.distance < 0.75
    ctx.save()
    ctx.fillStyle = `rgba(215, 224, 255, ${0.55 * light})`
    ctx.fillRect(0, 0, w, h)
    if (reach) {
      ctx.shadowColor = 'rgba(190, 205, 255, .95)'
      ctx.shadowBlur = 26
      ctx.strokeStyle = `rgba(250, 252, 255, ${Math.min(1, light * 1.3)})`
      ctx.lineCap = 'round'
      ctx.lineJoin = 'round'
      const line = (pts: [number, number][], width: number) => {
        ctx!.lineWidth = width
        ctx!.beginPath()
        pts.forEach(([x, y], i) => (i ? ctx!.lineTo(x * w, y * h) : ctx!.moveTo(x * w, y * h)))
        ctx!.stroke()
      }
      line(b.main, 3.2 * (1 - flash.strike.distance * 0.5))
      b.branches.forEach(br => line(br, 1.3))
      // White-hot core over the glow.
      ctx.shadowBlur = 0
      ctx.strokeStyle = `rgba(255, 255, 255, ${Math.min(1, light * 1.5)})`
      line(b.main, 1.2)
    }
    ctx.restore()
  }

  // Rain: far layer dim and short, near layer brighter and long; splashes at the ground line.
  if (drops.length) {
    for (const near of [false, true]) {
      ctx.strokeStyle = near ? `rgba(215, 226, 240, ${0.45 + 0.3 * s.rain})` : `rgba(180, 195, 215, ${0.25 + 0.2 * s.rain})`
      ctx.lineWidth = near ? 1.5 : 0.9
      ctx.beginPath()
      for (const d of drops) {
        if (d.near !== near) continue
        d.y += d.v * dt
        d.x += d.v * wind * dt
        if (d.y > h) {
          if (near && splashes.length < 40) splashes.push({ x: d.x, y: h - rnd(2, 10), t: 0 })
          d.y = rnd(-60, -10)
          d.x = rnd(-120, w)
        }
        ctx.moveTo(d.x, d.y)
        ctx.lineTo(d.x - d.l * wind, d.y - d.l)
      }
      ctx.stroke()
    }
    ctx.strokeStyle = 'rgba(215, 226, 240, .5)'
    ctx.lineWidth = 1
    for (let i = splashes.length - 1; i >= 0; i--) {
      const sp = splashes[i]
      sp.t += dt
      if (sp.t > 0.25) { splashes.splice(i, 1); continue }
      const r = 2 + sp.t * 18
      ctx.globalAlpha = 1 - sp.t / 0.25
      ctx.beginPath()
      ctx.ellipse(sp.x, sp.y, r, r * 0.35, 0, Math.PI, 0)
      ctx.stroke()
    }
    ctx.globalAlpha = 1
  }

  // Snow in depth: near flakes bigger, faster and brighter.
  if (flakes.length) {
    for (const f of flakes) {
      f.y += f.v * dt
      f.p += dt * (0.6 + f.z)
      f.x += (Math.sin(f.p) * 14 + wind * 30) * dt * f.z
      if (f.y > h) { f.y = rnd(-20, -4); f.x = rnd(-20, w) }
      ctx.fillStyle = `rgba(246, 248, 253, ${0.35 + 0.6 * f.z})`
      ctx.beginPath()
      ctx.arc(f.x, f.y, f.r, 0, Math.PI * 2)
      ctx.fill()
    }
  }
}

function loop(now: number): void {
  const dt = Math.min(0.05, (now - last) / 1000 || 0)
  last = now
  if (visible && !document.hidden) draw(dt, now)
  frame = requestAnimationFrame(loop)
}

onMounted(() => {
  fit()
  populate()
  if (still) {
    draw(0, performance.now())
  } else {
    last = performance.now()
    frame = requestAnimationFrame(loop)
    unsubscribe = storm?.subscribe(strike => { flash = { strike, start: performance.now(), bolt: boltPath(strike, 0.9) } }) ?? null
  }
  if (typeof ResizeObserver !== 'undefined' && canvas.value) {
    resize = new ResizeObserver(() => { fit(); populate(); if (still) draw(0, performance.now()) })
    resize.observe(canvas.value)
  }
  if (typeof IntersectionObserver !== 'undefined' && canvas.value) {
    seen = new IntersectionObserver(entries => { visible = entries.some(e => e.isIntersecting) })
    seen.observe(canvas.value)
  }
})
watch(() => [props.sky.rain, props.sky.snow, props.sky.clouds, props.sky.storm, props.sky.daylight].map(v => Math.round(v * 20)).join(), () => { populate(); if (still) draw(0, performance.now()) })
onBeforeUnmount(() => { cancelAnimationFrame(frame); resize?.disconnect(); seen?.disconnect(); unsubscribe?.() })
</script>

<template>
  <canvas ref="canvas" class="weather-canvas" aria-hidden="true" />
</template>
