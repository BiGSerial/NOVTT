import type { ArtKey } from './types'

/**
 * Original scene illustrations (no third-party art): layered silhouettes drawn as SVG paths on a
 * 1200×400 canvas. Colours come from theme tokens (--art-*), so every campaign theme recolours
 * the same drawing. Deterministic: the same key always draws the same picture.
 */
export interface ArtLayer { d: string; fill: 'far' | 'mid' | 'near' | 'light' | 'glow' | 'water' | 'fog'; opacity?: number }
export interface ArtPicture { layers: ArtLayer[]; moon: { x: number; y: number; r: number } | null; stars: number }

export const ART_LABELS: Record<ArtKey, string> = {
  bridge: 'Ponte', forest: 'Floresta', tavern: 'Taverna', ruins: 'Ruínas', city: 'Cidade', cave: 'Caverna',
  sea: 'Mar', camp: 'Acampamento', road: 'Estrada', castle: 'Castelo', swamp: 'Pântano', temple: 'Templo',
}

function rng(seed: number): () => number {
  let s = seed >>> 0
  return () => { s = (s * 1664525 + 1013904223) >>> 0; return s / 4294967296 }
}

const r1 = (n: number) => Math.round(n * 10) / 10

/** A ridge line across the canvas, closed at the bottom. */
function ridge(seed: number, base: number, amp: number, step = 60, bottom = 400): string {
  const r = rng(seed)
  let d = `M0 ${bottom} L0 ${r1(base - r() * amp)}`
  for (let x = step; x <= 1200 + step; x += step) d += ` L${Math.min(x, 1200)} ${r1(base - r() * amp)}`
  return `${d} L1200 ${bottom} Z`
}

function hills(seed: number, base: number, amp: number): string {
  const r = rng(seed)
  let d = `M0 400 L0 ${base}`
  let x = 0
  while (x < 1200) {
    const w = 180 + r() * 220
    d += ` Q${r1(x + w / 2)} ${r1(base - amp * (0.4 + r()))} ${r1(Math.min(1200, x + w))} ${r1(base - r() * amp * 0.3)}`
    x += w
  }
  return `${d} L1200 400 Z`
}

function pine(x: number, ground: number, h: number): string {
  const w = h * 0.36
  const tiers = 3
  let d = ''
  for (let i = 0; i < tiers; i++) {
    const top = ground - h + (i * h) / (tiers + 1)
    const bw = w * (0.55 + i * 0.25)
    d += `M${r1(x)} ${r1(top)} L${r1(x + bw)} ${r1(top + h / 2.2)} L${r1(x - bw)} ${r1(top + h / 2.2)} Z `
  }
  return d + `M${r1(x - 3)} ${r1(ground - h * 0.12)} h6 v${r1(h * 0.14)} h-6 Z `
}

function forestBand(seed: number, ground: number, minH: number, maxH: number, from = 0, to = 1200, gap = 26): string {
  const r = rng(seed)
  let d = ''
  for (let x = from; x < to; x += gap * (0.6 + r() * 0.8)) d += pine(x, ground + r() * 10, minH + r() * (maxH - minH))
  return d
}

function deadTree(x: number, ground: number, h: number, seed: number): string {
  const r = rng(seed)
  let d = `M${x - 5} ${ground} L${x - 2} ${ground - h} L${x + 3} ${ground - h} L${x + 6} ${ground} Z `
  for (let i = 0; i < 4; i++) {
    const y = ground - h * (0.45 + i * 0.13)
    const dir = i % 2 ? 1 : -1
    const len = 30 + r() * 40
    d += `M${x} ${r1(y)} L${r1(x + dir * len)} ${r1(y - 20 - r() * 25)} L${r1(x + dir * (len - 4))} ${r1(y - 14 - r() * 10)} L${x} ${r1(y + 6)} Z `
  }
  return d
}

function windows(rects: [number, number, number, number][], seed: number, density = 0.35): string {
  const r = rng(seed)
  let d = ''
  for (const [x, y, w, h] of rects) {
    for (let wy = y + 12; wy < y + h - 14; wy += 18) {
      for (let wx = x + 8; wx < x + w - 10; wx += 14) if (r() < density) d += `M${wx} ${wy} h5 v8 h-5 Z `
    }
  }
  return d
}

function crenels(x: number, y: number, w: number): string {
  let d = ''
  for (let cx = x; cx < x + w - 4; cx += 14) d += `M${cx} ${y} h8 v-10 h-8 Z `
  return d
}

const FOG_LOW = 'M0 330 Q300 300 600 325 T1200 315 V400 H0 Z'

const PICTURES: Record<ArtKey, () => ArtPicture> = {
  bridge: () => ({
    moon: { x: 930, y: 90, r: 34 }, stars: 40,
    layers: [
      { d: ridge(3, 230, 70), fill: 'far' },
      { d: forestBand(5, 300, 40, 80, 0, 260) + forestBand(6, 300, 40, 80, 940, 1200), fill: 'mid' },
      { d: 'M0 330 Q600 300 1200 330 V400 H0 Z', fill: 'water' },
      { d: 'M200 252 H1000 V262 H200 Z M200 262 H1000 V352 H200 Z M290 352 A80 76 0 0 1 450 352 Z M520 352 A80 76 0 0 1 680 352 Z M750 352 A80 76 0 0 1 910 352 Z', fill: 'near' },
      { d: Array.from({ length: 21 }, (_, i) => `M${210 + i * 39} 252 v-18 h4 v18 Z`).join(' ') + ' M200 232 H1000 v4 H200 Z', fill: 'near' },
      { d: 'M596 196 h8 v40 h-8 Z M588 188 h24 v10 h-24 Z', fill: 'near' },
      { d: 'M600 205 m-9 0 a9 9 0 1 0 18 0 a9 9 0 1 0 -18 0', fill: 'light' },
      { d: 'M600 205 m-46 0 a46 46 0 1 0 92 0 a46 46 0 1 0 -92 0', fill: 'glow', opacity: 0.35 },
      { d: FOG_LOW, fill: 'fog', opacity: 0.55 },
    ],
  }),
  forest: () => ({
    moon: { x: 260, y: 80, r: 28 }, stars: 30,
    layers: [
      { d: ridge(11, 220, 60), fill: 'far' },
      { d: forestBand(12, 290, 70, 120, -20, 1220, 22), fill: 'mid', opacity: 0.85 },
      { d: 'M0 300 Q600 280 1200 300 V400 H0 Z', fill: 'fog', opacity: 0.35 },
      { d: forestBand(13, 380, 150, 230, -40, 1240, 70), fill: 'near' },
      { d: 'M560 400 Q600 330 640 300 Q660 330 700 400 Z', fill: 'glow', opacity: 0.25 },
      { d: FOG_LOW, fill: 'fog', opacity: 0.4 },
    ],
  }),
  tavern: () => ({
    moon: { x: 1010, y: 70, r: 26 }, stars: 34,
    layers: [
      { d: hills(21, 260, 70), fill: 'far' },
      { d: forestBand(22, 300, 50, 90, 0, 380) + forestBand(23, 300, 50, 90, 860, 1200), fill: 'mid' },
      { d: 'M420 330 V220 L560 130 L700 220 V330 Z M620 170 h26 v-50 h-26 Z M700 330 V250 L800 190 L860 230 V330 Z', fill: 'near' },
      { d: 'M470 250 h34 v40 h-34 Z M612 250 h34 v40 h-34 Z M540 272 h40 v58 h-40 Z M770 258 h26 v30 h-26 Z M540 180 h40 v30 h-40 Z', fill: 'light' },
      { d: 'M560 260 m-120 0 a120 90 0 1 0 240 0 a120 90 0 1 0 -240 0', fill: 'glow', opacity: 0.22 },
      { d: 'M372 230 h6 v100 h-6 Z M340 232 h70 v4 h-70 Z M352 240 h44 v26 h-44 Z', fill: 'near' },
      { d: 'M0 330 H1200 V400 H0 Z', fill: 'near' },
      { d: 'M633 110 q-20 -30 6 -50 q26 -20 4 -48', fill: 'fog', opacity: 0.5 },
    ],
  }),
  ruins: () => ({
    moon: { x: 300, y: 100, r: 40 }, stars: 50,
    layers: [
      { d: ridge(31, 250, 80), fill: 'far' },
      { d: hills(32, 320, 40), fill: 'mid' },
      { d: 'M330 340 V180 l14 -12 l10 16 l12 -8 V340 Z M430 340 V150 l20 -10 l10 14 V340 Z M690 340 V200 l10 -6 l16 10 V340 Z M800 340 V140 l14 -10 l12 12 V340 Z M410 150 H830 v-20 l-40 -26 h-160 l-30 18 h-70 l-30 10 Z', fill: 'near' },
      { d: 'M520 340 V230 A70 70 0 0 1 660 230 V340 H630 V236 A40 40 0 0 0 550 236 V340 Z', fill: 'near' },
      { d: 'M560 340 h60 v-6 h-60 Z M250 340 l40 -18 l30 18 Z M880 340 l30 -24 l40 24 Z', fill: 'mid' },
      { d: 'M0 340 H1200 V400 H0 Z', fill: 'near' },
      { d: FOG_LOW, fill: 'fog', opacity: 0.45 },
    ],
  }),
  city: () => {
    const far: [number, number, number, number][] = [[40, 210, 70, 190], [120, 180, 50, 220], [190, 230, 90, 170], [300, 160, 60, 240], [380, 200, 80, 200], [480, 140, 50, 260], [560, 190, 100, 210], [690, 170, 60, 230], [770, 215, 90, 185], [880, 150, 55, 250], [960, 200, 80, 200], [1060, 180, 70, 220], [1140, 230, 60, 170]]
    const d = far.map(([x, y, w, h]) => `M${x} ${y} h${w} v${h} h-${w} Z`).join(' ') + ' M495 140 l10 -50 l10 50 Z M895 150 l12 -60 l12 60 Z M300 160 q30 -50 60 0 Z'
    return {
      moon: { x: 980, y: 70, r: 30 }, stars: 20,
      layers: [
        { d: ridge(41, 260, 40), fill: 'far' },
        { d, fill: 'mid' },
        { d: windows(far, 42), fill: 'light' },
        { d: 'M0 330 L80 300 L200 320 L240 270 h40 v60 L400 300 L520 330 L700 300 L760 260 h30 v70 L1000 310 L1200 330 V400 H0 Z', fill: 'near' },
        { d: FOG_LOW, fill: 'fog', opacity: 0.3 },
      ],
    }
  },
  cave: () => ({
    moon: null, stars: 0,
    layers: [
      { d: 'M0 0 H1200 V400 H0 Z', fill: 'far' },
      { d: 'M420 400 Q430 200 600 170 Q770 200 780 400 Z', fill: 'glow', opacity: 0.5 },
      { d: 'M0 0 H1200 V400 H820 Q800 190 600 150 Q400 190 380 400 H0 Z', fill: 'mid' },
      { d: Array.from({ length: 14 }, (_, i) => { const x = 420 + i * 27; const h = 20 + ((i * 37) % 50); return `M${x} ${165 + Math.abs(7 - i) * 6} l9 ${h} l9 -${h} Z` }).join(' '), fill: 'mid' },
      { d: 'M0 0 H1200 V60 Q900 120 820 400 H1200 V0 Z M0 0 V400 H360 Q320 140 0 80 Z', fill: 'near' },
      { d: 'M460 400 l30 -40 l40 20 l30 -30 l50 50 l40 -24 l60 24 Z', fill: 'near' },
      { d: 'M600 330 m-8 0 a8 8 0 1 0 16 0 a8 8 0 1 0 -16 0', fill: 'light' },
    ],
  }),
  sea: () => ({
    moon: { x: 820, y: 110, r: 38 }, stars: 45,
    layers: [
      { d: 'M0 250 H1200 V400 H0 Z', fill: 'water' },
      { d: 'M800 250 L780 400 H860 L840 250 Z', fill: 'glow', opacity: 0.35 },
      { d: Array.from({ length: 7 }, (_, i) => `M0 ${270 + i * 20} Q150 ${262 + i * 20} 300 ${270 + i * 20} T600 ${270 + i * 20} T900 ${270 + i * 20} T1200 ${270 + i * 20} v3 H0 Z`).join(' '), fill: 'fog', opacity: 0.25 },
      { d: 'M380 250 L400 236 H520 L540 250 Z M455 236 V150 h4 V236 Z M459 152 Q500 190 459 230 Z M455 160 Q420 195 455 228 Z', fill: 'near' },
      { d: 'M0 400 V230 L90 220 L150 250 L200 260 V400 Z M100 220 V120 h22 V220 Z M96 120 h30 l-15 -18 Z', fill: 'near' },
      { d: 'M111 128 m-6 0 a6 6 0 1 0 12 0 a6 6 0 1 0 -12 0', fill: 'light' },
      { d: 'M111 128 L400 110 L400 150 Z', fill: 'glow', opacity: 0.2 },
    ],
  }),
  camp: () => ({
    moon: { x: 1000, y: 80, r: 24 }, stars: 60,
    layers: [
      { d: ridge(61, 240, 90), fill: 'far' },
      { d: forestBand(62, 320, 80, 140, -20, 360, 34) + forestBand(63, 320, 80, 140, 860, 1220, 34), fill: 'mid' },
      { d: 'M0 330 Q600 310 1200 330 V400 H0 Z', fill: 'near' },
      { d: 'M640 340 L740 220 L840 340 Z M736 230 l4 -14 l4 14 Z', fill: 'near' },
      { d: 'M720 340 L740 268 L760 340 Z', fill: 'glow', opacity: 0.5 },
      { d: 'M520 340 m-110 0 a110 70 0 1 0 220 0 a110 70 0 1 0 -220 0', fill: 'glow', opacity: 0.3 },
      { d: 'M500 342 L520 300 L540 342 Z M490 342 h60 v6 h-60 Z', fill: 'light' },
      { d: 'M470 352 L570 340 v6 L470 358 Z M470 340 L570 352 v6 L470 346 Z', fill: 'near' },
    ],
  }),
  road: () => ({
    moon: { x: 600, y: 120, r: 30 }, stars: 30,
    layers: [
      { d: ridge(71, 240, 60), fill: 'far' },
      { d: hills(72, 290, 70), fill: 'mid' },
      { d: 'M0 400 V320 Q300 290 600 300 T1200 310 V400 Z', fill: 'near' },
      { d: 'M520 400 Q560 360 600 340 Q640 320 610 304 Q590 296 604 290 L612 290 Q600 300 626 306 Q676 324 640 356 Q610 380 690 400 Z', fill: 'water', opacity: 0.8 },
      { d: 'M300 330 h6 v-80 h-6 Z M270 262 h60 l10 8 l-10 8 h-60 Z M300 288 h50 l8 6 l-8 6 h-50 Z', fill: 'mid' },
      { d: forestBand(73, 330, 60, 100, 900, 1200, 40), fill: 'near' },
    ],
  }),
  castle: () => ({
    moon: { x: 220, y: 90, r: 36 }, stars: 40,
    layers: [
      { d: ridge(81, 260, 50), fill: 'far' },
      { d: 'M0 400 V320 Q300 300 480 230 Q600 190 720 230 Q900 300 1200 320 V400 Z', fill: 'mid' },
      { d: 'M470 230 V150 h40 v80 Z M690 230 V150 h40 v80 Z M560 220 V100 h80 v120 Z M510 230 V180 h180 v50 Z' + crenels(470, 150, 40) + crenels(690, 150, 40) + crenels(560, 100, 80) + crenels(510, 180, 180) + ' M598 100 V50 h4 v50 Z M602 52 l30 8 l-30 8 Z', fill: 'near' },
      { d: 'M590 150 h20 v30 h-20 Z M484 170 h10 v16 h-10 Z M706 170 h10 v16 h-10 Z M588 200 h24 v30 h-24 Z', fill: 'light' },
      { d: FOG_LOW, fill: 'fog', opacity: 0.5 },
    ],
  }),
  swamp: () => ({
    moon: { x: 900, y: 100, r: 32 }, stars: 20,
    layers: [
      { d: hills(91, 270, 40), fill: 'far' },
      { d: 'M0 300 H1200 V400 H0 Z', fill: 'water' },
      { d: deadTree(200, 320, 200, 92) + deadTree(980, 330, 230, 93) + deadTree(660, 310, 120, 94), fill: 'near' },
      { d: Array.from({ length: 40 }, (_, i) => { const x = (i * 97) % 1200; const h = 20 + ((i * 53) % 40); return `M${x} 400 L${x + 2} ${380 - h} L${x + 4} 400 Z` }).join(' '), fill: 'near' },
      { d: 'M0 300 Q300 280 600 300 T1200 296 V340 Q600 320 0 340 Z', fill: 'fog', opacity: 0.55 },
      { d: 'M420 320 m-5 0 a5 5 0 1 0 10 0 a5 5 0 1 0 -10 0 M760 300 m-4 0 a4 4 0 1 0 8 0 a4 4 0 1 0 -8 0', fill: 'light' },
    ],
  }),
  temple: () => ({
    moon: { x: 600, y: 70, r: 30 }, stars: 50,
    layers: [
      { d: ridge(101, 260, 70), fill: 'far' },
      { d: 'M300 340 H900 V325 H320 Z M340 325 H860 V310 H340 Z M380 310 H820 V296 H380 Z', fill: 'mid' },
      { d: 'M400 296 V180 H800 V296 Z M380 180 L600 110 L820 180 Z', fill: 'near' },
      { d: 'M560 296 V210 A40 40 0 0 1 640 210 V296 Z', fill: 'light' },
      { d: 'M600 260 m-150 0 a150 120 0 1 0 300 0 a150 120 0 1 0 -300 0', fill: 'glow', opacity: 0.25 },
      { d: 'M430 296 V190 h16 V296 Z M490 296 V190 h16 V296 Z M694 296 V190 h16 V296 Z M754 296 V190 h16 V296 Z', fill: 'mid' },
      { d: 'M0 340 H1200 V400 H0 Z', fill: 'near' },
      { d: FOG_LOW, fill: 'fog', opacity: 0.35 },
    ],
  }),
}

const cache = new Map<ArtKey, ArtPicture>()
export function picture(key: ArtKey | null | undefined): ArtPicture {
  const k: ArtKey = key && key in PICTURES ? key : 'road'
  if (!cache.has(k)) cache.set(k, PICTURES[k]())
  return cache.get(k)!
}

export function starfield(count: number, seed = 7): { x: number; y: number; r: number }[] {
  const r = rng(seed)
  return Array.from({ length: count }, () => ({ x: r1(r() * 1200), y: r1(r() * 200), r: r1(0.6 + r() * 1.4) }))
}
