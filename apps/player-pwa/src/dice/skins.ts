import { TEXTURE_PATTERNS, type DiceTexture } from './textures'

/**
 * Dice skins: purely cosmetic, data-only (no user images or code), so a skin can travel inside a
 * roll record and the master sees the player's dice exactly as the player does.
 */
export const FINISHES = ['matte', 'satin', 'gloss', 'metal'] as const
export type Finish = (typeof FINISHES)[number]

export interface DiceSkin {
  id: string
  name: string
  body: string
  ink: string
  finish: Finish
  /** Optional procedural texture (pattern + accent colour), drawn identically by every client. */
  texture?: DiceTexture
}

export const MIN_CONTRAST = 3

export const PRESET_SKINS: readonly DiceSkin[] = [
  { id: 'marfim', name: 'Marfim', body: '#ece6d6', ink: '#1c1917', finish: 'satin' },
  { id: 'rubi', name: 'Rubi', body: '#8a1538', ink: '#fbe7b5', finish: 'gloss' },
  { id: 'safira', name: 'Safira', body: '#1e3a8a', ink: '#f8fafc', finish: 'gloss' },
  { id: 'jade', name: 'Jade', body: '#0f5f4a', ink: '#ecfdf5', finish: 'satin' },
  { id: 'obsidiana', name: 'Obsidiana', body: '#141414', ink: '#e8c46b', finish: 'gloss' },
  { id: 'bronze', name: 'Bronze', body: '#7c4a1e', ink: '#fff7ed', finish: 'metal' },
]
export const DEFAULT_SKIN = PRESET_SKINS[0]

const HEX = /^#[0-9a-f]{6}$/

function luminance(hex: string): number {
  const c = [1, 3, 5].map(i => parseInt(hex.slice(i, i + 2), 16) / 255)
    .map(x => (x <= 0.04045 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4))
  return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]
}

/** WCAG contrast ratio between two #rrggbb colours. */
export function contrast(a: string, b: string): number {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x)
  return (hi + 0.05) / (lo + 0.05)
}

/** Accepts only well-formed, legible skins; anything else is rejected (never "repaired"). */
export function parseSkin(raw: unknown): DiceSkin | null {
  if (typeof raw !== 'object' || raw === null) return null
  const s = raw as Record<string, unknown>
  const keys = Object.keys(s).sort().join(',')
  if (keys !== 'body,finish,id,ink,name' && keys !== 'body,finish,id,ink,name,texture') return null
  if (typeof s.id !== 'string' || !/^[a-z0-9-]{1,32}$/.test(s.id)) return null
  if (typeof s.name !== 'string' || s.name.length < 1 || s.name.length > 40) return null
  if (typeof s.body !== 'string' || !HEX.test(s.body) || typeof s.ink !== 'string' || !HEX.test(s.ink)) return null
  if (typeof s.finish !== 'string' || !(FINISHES as readonly string[]).includes(s.finish)) return null
  if (contrast(s.body, s.ink) < MIN_CONTRAST) return null
  let texture: DiceTexture | undefined
  if (s.texture !== undefined) {
    const t = s.texture as Record<string, unknown> | null
    if (!t || typeof t !== 'object' || !(TEXTURE_PATTERNS as readonly string[]).includes(String(t.pattern)) || typeof t.accent !== 'string' || !HEX.test(t.accent)) return null
    const scale = t.scale === undefined ? 1 : Number(t.scale)
    if (!Number.isFinite(scale) || scale < 0.5 || scale > 3) return null
    texture = { pattern: t.pattern as DiceTexture['pattern'], accent: t.accent, scale }
  }
  return { id: s.id, name: s.name, body: s.body, ink: s.ink, finish: s.finish as Finish, ...(texture && texture.pattern !== 'none' ? { texture } : {}) }
}

const STORAGE_KEY = 'novtt.dice.skin'

/** Per-device preference; storage may be unavailable (private mode), so failures are ignored. */
export function loadSkin(): DiceSkin {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    return (raw && parseSkin(JSON.parse(raw))) || DEFAULT_SKIN
  } catch {
    return DEFAULT_SKIN
  }
}

export function saveSkin(skin: DiceSkin): void {
  try { localStorage.setItem(STORAGE_KEY, JSON.stringify(skin)) } catch { /* preference only */ }
}

export const FINISH_LABELS: Record<Finish, string> = { matte: 'Fosco', satin: 'Acetinado', gloss: 'Brilhante', metal: 'Metálico' }

const SOUND_KEY = 'novtt.dice.sound'
/** Account preference mirrored on the device (set by live/me.ts); defaults to on. */
export function soundAllowed(): boolean {
  try { return localStorage.getItem(SOUND_KEY) !== 'off' } catch { return true }
}
export function saveSound(on: boolean): void {
  try { localStorage.setItem(SOUND_KEY, on ? 'on' : 'off') } catch { /* preference only */ }
}
