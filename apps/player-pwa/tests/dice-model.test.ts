import { describe, expect, it } from 'vitest'
import { arrange } from '../src/dice/arrange'
import { dieDef } from '../src/dice/geometry'
import { randomRotation, rotate, seeded, v } from '../src/dice/math'
import { TABLE } from '../src/dice/sim'
import { createLocalRoll, labelText, MAX_DICE, maxCount, parseRecord, physicalDice } from '../src/dice/record'
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
    const dice = [{ shape: 'd20' as const, face: 17, group: 0 }, { shape: 'd6' as const, face: 2, group: 1 }, { shape: 'd4' as const, face: 3, group: 2 }]
    const finals = [{ p: v(3, 1, 0), q: randomRotation(rng) }, { p: v(-4, 1, 2), q: randomRotation(rng) }, { p: v(0, 1, -3), q: randomRotation(rng) }]
    const { targets, order } = arrange(dice, finals)
    expect(order).toEqual([1, 2, 0])
    const xs = order.map(i => targets[i].p.x)
    expect([...xs].sort((a, b) => a - b)).toEqual(xs)
    dice.forEach((d, i) => {
      const face = dieDef(d.shape).faces.find(f => f.number === d.face)!
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

describe('d100', () => {
  it('is a tens and a units d10 kept side by side, 100 shown as 00 + 0', () => {
    expect(physicalDice([{ type: 'd100', result: 100 }]).map(d => [labelText(d.labels, d.face)])).toEqual([['00'], ['0']])
    expect(physicalDice([{ type: 'd100', result: 7 }]).map(d => labelText(d.labels, d.face))).toEqual(['00', '7'])
    expect(physicalDice([{ type: 'd100', result: 58 }]).map(d => labelText(d.labels, d.face))).toEqual(['50', '8'])
    expect(maxCount('d100')).toBe(3)
    expect(() => createLocalRoll('d100', 4)).toThrow(RangeError)
    const r = createLocalRoll('d100', 3)
    expect(physicalDice(r.dice)).toHaveLength(6)
    expect(parseRecord({ ...r, dice: [...r.dice, { type: 'd4', result: 1 }] })).toBeNull()

    const rng = seeded(3)
    const pair = physicalDice([{ type: 'd6', result: 1 }, { type: 'd100', result: 42 }])
    const finals = [{ p: v(0, 1, 0), q: randomRotation(rng) }, { p: v(-5, 1, 0), q: randomRotation(rng) }, { p: v(5, 1, 0), q: randomRotation(rng) }]
    const { targets, order } = arrange(pair, finals)
    expect(order).toEqual([0, 1])
    expect(targets[2].p.x - targets[1].p.x).toBeCloseTo(2.15)
  })

  it('a full row of six large dice stays on the table', () => {
    const rng = seeded(4)
    const six = Array.from({ length: 6 }, (_, i) => ({ shape: 'd20' as const, face: 1, group: i }))
    const { targets } = arrange(six, six.map((_, i) => ({ p: v(i, 1, 0), q: randomRotation(rng) })))
    const r = dieDef('d20').radius
    for (const t of targets) expect(Math.abs(t.p.x) + r).toBeLessThanOrEqual(TABLE.halfX)
  })
})
