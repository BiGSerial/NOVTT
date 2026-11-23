import * as RAPIER from '@dimforge/rapier3d-deterministic'
import { describe, expect, it } from 'vitest'
import { DIE_TYPES, dieDef, SIDES, type DieType } from '../src/dice/geometry'
import { dot, length, qmul, rotate, sub } from '../src/dice/math'
import { simulateRoll } from '../src/dice/sim'
import { relabel, symmetryTable } from '../src/dice/symmetry'

describe('dice geometry', () => {
  it.each(DIE_TYPES)('%s has numbered planar faces and opposite faces summing to n + 1', type => {
    const def = dieDef(type)
    const n = SIDES[type]
    expect(def.faces.map(f => f.number).sort((a, b) => a - b)).toEqual(Array.from({ length: n }, (_, i) => i + 1))
    for (const f of def.faces) {
      for (const p of f.verts) expect(Math.abs(dot(p, f.normal) - f.dist)).toBeLessThan(1e-9)
      const opposite = def.faces.find(g => dot(g.normal, f.normal) < -0.999)!
      expect(opposite.number + f.number).toBe(n + 1)
      expect(Math.abs(dot(f.up, f.normal))).toBeLessThan(1e-9)
    }
  })

  it.each(DIE_TYPES)('%s: every face can be relabelled onto every other by a hull symmetry', type => {
    const def = dieDef(type)
    const table = symmetryTable(type)
    for (const t of def.faces) for (const l of def.faces) {
      const q = table[t.index][l.index]
      expect(length(sub(rotate(q, t.normal), l.normal))).toBeLessThan(1e-9)
      for (const p of def.vertices) expect(def.vertices.some(o => length(sub(o, rotate(q, p))) < 1e-6)).toBe(true)
    }
  })
})

describe('dice simulation', () => {
  const dice: DieType[] = ['d20', 'd20', 'd6', 'd10', 'd12', 'd8']

  it('is deterministic for a seed and shows the requested results after relabelling', () => {
    const a = simulateRoll(RAPIER, dice, 123456)
    const b = simulateRoll(RAPIER, dice, 123456)
    expect(Array.from(b.poses)).toEqual(Array.from(a.poses))
    expect(b.landed).toEqual(a.landed)

    const results = [20, 1, 6, 10, 7, 3]
    const n = dice.length, last = (a.frames - 1) * n * 7
    dice.forEach((type, i) => {
      const o = last + i * 7
      const body = { x: a.poses[o + 3], y: a.poses[o + 4], z: a.poses[o + 5], w: a.poses[o + 6] }
      const visual = qmul(body, relabel(type, results[i], a.landed[i]))
      const face = dieDef(type).faces.find(f => f.number === results[i])!
      expect(rotate(visual, face.normal).y).toBeGreaterThan(0.95)
    })
  })

  it('different seeds give different throws', () => {
    const a = simulateRoll(RAPIER, ['d6'], 1), b = simulateRoll(RAPIER, ['d6'], 2)
    expect(Array.from(a.poses.slice(-7))).not.toEqual(Array.from(b.poses.slice(-7)))
  })

  it('six dice are computed well within the interaction budget', () => {
    simulateRoll(RAPIER, dice, 7) // warm-up (JIT)
    const times: number[] = []
    const lengths: number[] = []
    for (let s = 0; s < 20; s++) {
      const t0 = performance.now()
      const r = simulateRoll(RAPIER, dice, 1000 + s)
      times.push(performance.now() - t0)
      lengths.push(r.frames / 60)
    }
    times.sort((x, y) => x - y)
    const p50 = times[10], p95 = times[18]
    console.info(`6 dice: p50 ${p50.toFixed(1)} ms, p95 ${p95.toFixed(1)} ms, animation ${Math.min(...lengths).toFixed(2)}–${Math.max(...lengths).toFixed(2)} s`)
    expect(p95).toBeLessThan(250)
    expect(Math.max(...lengths)).toBeLessThanOrEqual(6.1)
  })
})
