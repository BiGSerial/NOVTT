import { describe, expect, it } from 'vitest'
import { arrange } from '../src/dice/arrange'
import { dieDef } from '../src/dice/geometry'
import { randomRotation, rotate, seeded, v } from '../src/dice/math'
import { createLocalRoll, MAX_DICE, parseRecord } from '../src/dice/record'
import { contrast, DEFAULT_SKIN, parseSkin, PRESET_SKINS } from '../src/dice/skins'

describe('roll record', () => {
  it('creates results in range with crypto randomness and at most six dice', () => {
    const r = createLocalRoll('d12', MAX_DICE)
    expect(r.dice).toHaveLength(6)
    for (const d of r.dice) expect(d.result).toBeGreaterThanOrEqual(1), expect(d.result).toBeLessThanOrEqual(12)
    expect(r.origin).toBe('local_unofficial')
    expect(() => structuredClone(r)).not.toThrow()
    expect(() => createLocalRoll('d6', 7)).toThrow(RangeError)
    expect(() => createLocalRoll('d6', 0)).toThrow(RangeError)
  })

  it('accepts its own records and rejects tampered or oversized ones', () => {
    const r = createLocalRoll('d20', 3)
    expect(parseRecord(JSON.parse(JSON.stringify(r)))).toEqual(r)
    expect(parseRecord({ ...r, dice: [{ type: 'd20', result: 21 }] })).toBeNull()
    expect(parseRecord({ ...r, dice: Array(7).fill({ type: 'd6', result: 1 }) })).toBeNull()
    expect(parseRecord({ ...r, origin: 'official' })).toBeNull()
    expect(parseRecord({ ...r, extra: true })).toBeNull()
    expect(parseRecord({ ...r, seed: -1 })).toBeNull()
    expect(parseRecord({ ...r, skin: { ...r.skin, body: 'red' } })).toBeNull()
  })
})

describe('skins', () => {
  it('presets are legible', () => {
    for (const s of PRESET_SKINS) expect(contrast(s.body, s.ink)).toBeGreaterThanOrEqual(3)
  })

  it('rejects illegible or malformed skins', () => {
    expect(parseSkin({ ...DEFAULT_SKIN, ink: DEFAULT_SKIN.body })).toBeNull()
    expect(parseSkin({ ...DEFAULT_SKIN, body: 'url(javascript:alert(1))' })).toBeNull()
    expect(parseSkin({ ...DEFAULT_SKIN, finish: 'glow' })).toBeNull()
    expect(parseSkin({ ...DEFAULT_SKIN, image: 'x.png' })).toBeNull()
    expect(parseSkin(DEFAULT_SKIN)).toEqual(DEFAULT_SKIN)
  })
})

describe('arrangement', () => {
  it('rows dice at the player edge, flat on the result with the number upright, without crossing', () => {
    const rng = seeded(9)
    const dice = [{ type: 'd20' as const, result: 17 }, { type: 'd6' as const, result: 2 }, { type: 'd10' as const, result: 9 }]
    const finals = [{ p: v(3, 1, 0), q: randomRotation(rng) }, { p: v(-4, 1, 2), q: randomRotation(rng) }, { p: v(0, 1, -3), q: randomRotation(rng) }]
    const { targets, order } = arrange(dice, finals)
    expect(order).toEqual([1, 2, 0])
    const xs = order.map(i => targets[i].p.x)
    expect([...xs].sort((a, b) => a - b)).toEqual(xs)
    dice.forEach((d, i) => {
      const face = dieDef(d.type).faces.find(f => f.number === d.result)!
      expect(rotate(targets[i].q, face.normal).y).toBeGreaterThan(0.9999)
      const up = rotate(targets[i].q, face.up)
      expect(up.z).toBeLessThan(-0.9999) // reads upright on the top-down camera
      expect(targets[i].p.z).toBeGreaterThan(2.5)
    })
  })
})

describe('record with a reactive skin', () => {
  it('is structured-cloneable for the mirror channel', async () => {
    const { reactive } = await import('vue')
    const r = createLocalRoll('d6', 2, reactive({ ...DEFAULT_SKIN }))
    expect(() => structuredClone(r)).not.toThrow()
  })
})
