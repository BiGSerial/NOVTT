// Procedural dice textures: drawn by every client from a few parameters (pattern, colours,
// scale), so a textured skin travels inside a roll record as data and the master sees the same
// dice as the player. No images, no user uploads in rolls. Deterministic: same input, same pixels.
export const TEXTURE_PATTERNS = ['none', 'marble', 'granite', 'nebula', 'wood', 'scales', 'speckle', 'veins'] as const
export type TexturePattern = (typeof TEXTURE_PATTERNS)[number]
export interface DiceTexture { pattern: TexturePattern; accent: string; scale?: number }
export const TEXTURE_LABELS: Record<TexturePattern, string> = {
  none: 'Liso', marble: 'Mármore', granite: 'Granito', nebula: 'Nebulosa', wood: 'Madeira', scales: 'Escamas', speckle: 'Pintado', veins: 'Veios',
}

function rng(seed: number): () => number {
  let s = seed >>> 0 || 1
  return () => { s ^= s << 13; s ^= s >>> 17; s ^= s << 5; return ((s >>> 0) % 100000) / 100000 }
}
function hash(text: string): number {
  let h = 2166136261
  for (const ch of text) h = Math.imul(h ^ ch.charCodeAt(0), 16777619)
  return h >>> 0
}
const rgb = (hex: string) => [1, 3, 5].map(i => parseInt(hex.slice(i, i + 2), 16))

/** Smooth value noise in [0,1], tiling over `period`. */
function noiseField(size: number, period: number, seed: number): (x: number, y: number) => number {
  const r = rng(seed)
  const grid = Array.from({ length: period * period }, () => r())
  const at = (i: number, j: number) => grid[((j % period + period) % period) * period + ((i % period + period) % period)]!
  const smooth = (t: number) => t * t * (3 - 2 * t)
  return (x, y) => {
    const fx = (x / size) * period, fy = (y / size) * period
    const i = Math.floor(fx), j = Math.floor(fy), tx = smooth(fx - i), ty = smooth(fy - j)
    const a = at(i, j), b = at(i + 1, j), c = at(i, j + 1), d = at(i + 1, j + 1)
    return a + (b - a) * tx + (c - a) * ty + (a - b - c + d) * tx * ty
  }
}
function fbm(size: number, seed: number, octaves = 4, base = 4): (x: number, y: number) => number {
  const layers = Array.from({ length: octaves }, (_, o) => noiseField(size, base * 2 ** o, seed + o * 101))
  return (x, y) => { let v = 0, amp = 0.5, total = 0; for (const n of layers) { v += n(x, y) * amp; total += amp; amp /= 2 } return v / total }
}

/** Paints the pattern into an RGBA buffer (size×size). Exported for tests. */
export function paintTexture(size: number, body: string, t: DiceTexture): Uint8ClampedArray<ArrayBuffer> {
  const out = new Uint8ClampedArray(new ArrayBuffer(size * size * 4))
  const [br, bg, bb] = rgb(body)
  const [ar, ag, ab] = rgb(t.accent)
  const k = Math.max(0.5, Math.min(3, t.scale ?? 1))
  const seed = hash(`${t.pattern}:${body}:${t.accent}:${k}`)
  const r = rng(seed)
  const n = fbm(size, seed, 4, Math.max(2, Math.round(3 * k)))
  const mix = (m: number, i: number) => { const w = Math.max(0, Math.min(1, m)); out[i] = br + (ar - br) * w; out[i + 1] = bg + (ag - bg) * w; out[i + 2] = bb + (ab - bb) * w; out[i + 3] = 255 }
  const spots = t.pattern === 'speckle' || t.pattern === 'granite'
    ? Array.from({ length: Math.round((t.pattern === 'granite' ? 900 : 160) * k * (size / 256) ** 2) }, () => [r() * size, r() * size, ((t.pattern === 'granite' ? 0.8 + r() * 1.6 : 1.5 + r() * 3) / k) * Math.max(0.5, size / 256)] as const)
    : []
  for (let y = 0; y < size; y++) {
    for (let x = 0; x < size; x++) {
      const i = (y * size + x) * 4
      const u = x / size, v = y / size
      let m = 0
      switch (t.pattern) {
        case 'marble': { const s = Math.sin((u * 6 * k + n(x, y) * 7) * Math.PI); m = Math.pow(1 - Math.abs(s), 6) * 0.9; break }
        case 'veins': { const s = Math.abs(n(x, y) - 0.5); m = s < 0.018 ? 1 : s < 0.04 ? 0.35 : 0; break }
        case 'nebula': { const a = n(x, y), b = n(size - y, x); m = Math.pow(a, 2.2) * 1.4 + (b > 0.72 ? 0.25 : 0); break }
        case 'wood': { const d = Math.hypot(u - 0.5, v - 0.5) * 18 * k + n(x, y) * 4; m = (Math.sin(d * Math.PI) * 0.5 + 0.5) * 0.55; break }
        case 'scales': {
          const cell = 1 / (6 * k), cx = Math.floor(u / cell), cy = Math.floor(v / (cell * 0.75))
          const ox = (cy % 2) * cell / 2, px = (u + ox) % cell / cell - 0.5, py = (v % (cell * 0.75)) / (cell * 0.75)
          const rr = Math.hypot(px, py); m = rr > 0.46 && rr < 0.56 ? 0.9 : rr < 0.46 ? 0.15 * py : 0; void cx; break
        }
        case 'granite': case 'speckle': m = (n(x, y) - 0.5) * 0.25; break
        default: m = 0
      }
      mix(m, i)
    }
  }
  for (const [sx, sy, rad] of spots) {
    for (let y = Math.floor(sy - rad); y <= sy + rad; y++) for (let x = Math.floor(sx - rad); x <= sx + rad; x++) {
      if (Math.hypot(x - sx, y - sy) > rad) continue
      const xx = (x + size) % size, yy = (y + size) % size
      mix(t.pattern === 'granite' ? 0.75 : 1, (yy * size + xx) * 4)
    }
  }
  return out
}

/** A canvas with the texture (browser only). */
export function textureCanvas(body: string, t: DiceTexture, size = 256): HTMLCanvasElement {
  const canvas = document.createElement('canvas')
  canvas.width = canvas.height = size
  const g = canvas.getContext('2d')
  if (g) g.putImageData(new ImageData(paintTexture(size, body, t), size, size), 0, 0)
  return canvas
}
