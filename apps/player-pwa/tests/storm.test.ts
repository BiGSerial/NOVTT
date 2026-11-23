import { describe, expect, it } from 'vitest'
import { boltPath, meanGap, nextStrike } from '../src/live/storm'
import { weatherMix } from '../src/live/atmosphere'

describe('storm schedule', () => {
  const start = Date.parse('2026-11-23T22:00:00Z')

  it('gives every screen the same strike for the same atmosphere', () => {
    const a = nextStrike('2026-11-23T22:00:00Z#3', start, 0.8, start + 60_000)
    const b = nextStrike('2026-11-23T22:00:00Z#3', start, 0.8, start + 60_000)
    expect(a).toEqual(b)
    expect(a!.at).toBeGreaterThan(start + 60_000)
    expect(nextStrike('2026-11-23T22:00:00Z#4', start, 0.8, start + 60_000)).not.toEqual(a)
  })

  it('never flashes more than three times per second and strikes more often in heavier storms', () => {
    let from = start
    for (let i = 0; i < 200; i++) {
      const s = nextStrike('seed', start, 1, from)!
      for (let k = 1; k < s.pulses.length; k++) expect(s.pulses[k].t - s.pulses[k - 1].t).toBeGreaterThanOrEqual(350)
      expect(s.pulses.length).toBeLessThanOrEqual(3)
      from = s.at
    }
    expect(meanGap(1)).toBeLessThan(meanGap(0.2))
    expect(nextStrike('seed', start, 0, start)).toBeNull()
  })

  it('draws a branching bolt from the top of the sky', () => {
    const bolt = boltPath(nextStrike('seed', start, 1, start)!)
    expect(bolt.main[0][1]).toBeLessThan(0)
    expect(bolt.main.length).toBeGreaterThan(30)
    expect(bolt.branches.length).toBeGreaterThanOrEqual(3)
  })

  it('keeps fog as a layer over any weather', () => {
    expect(weatherMix('storm', 1, 0.6)).toMatchObject({ storm: 1, fog: 0.6 })
    expect(weatherMix('fog', 0.7)).toMatchObject({ fog: 0.7 })
  })
})
