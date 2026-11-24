import { describe, expect, it } from 'vitest'
import { parseSkin } from '../src/dice/skins'
import { paintTexture, TEXTURE_PATTERNS } from '../src/dice/textures'

describe('dice textures', () => {
  it('every pattern paints deterministically and differs from a plain body', () => {
    for (const pattern of TEXTURE_PATTERNS.filter(p => p !== 'none')) {
      const a = paintTexture(32, '#7a1f12', { pattern, accent: '#f59e0b', scale: 1 })
      const b = paintTexture(32, '#7a1f12', { pattern, accent: '#f59e0b', scale: 1 })
      expect(Array.from(a)).toEqual(Array.from(b))
      const plain = new Set<number>()
      for (let i = 0; i < a.length; i += 4) plain.add(a[i]! * 65536 + a[i + 1]! * 256 + a[i + 2]!)
      expect(plain.size, pattern).toBeGreaterThan(1)
    }
  })

  it('a skin with a texture is accepted only with a known pattern, an accent colour and a sane scale', () => {
    const base = { id: 'brasa', name: 'Brasa', body: '#7a1f12', ink: '#fde68a', finish: 'gloss' }
    expect(parseSkin({ ...base, texture: { pattern: 'marble', accent: '#f59e0b', scale: 1.5 } })?.texture?.pattern).toBe('marble')
    expect(parseSkin({ ...base, texture: { pattern: 'photo', accent: '#f59e0b' } })).toBeNull()
    expect(parseSkin({ ...base, texture: { pattern: 'marble', accent: 'red' } })).toBeNull()
    expect(parseSkin({ ...base, texture: { pattern: 'marble', accent: '#f59e0b', scale: 9 } })).toBeNull()
    expect(parseSkin({ ...base, texture: { pattern: 'none', accent: '#f59e0b' } })?.texture).toBeUndefined()
  })
})
