import { RECORD_HZ, type Recording } from './sim'

export interface Impact { t: number; strength: number }

const MIN_DV = 3 // units/s: sudden velocity change worth hearing
const MIN_GAP = 0.07 // s between impacts of the same die
const MAX_IMPACTS = 24

/**
 * Impacts (die↔table, die↔wall, die↔die) inferred from the recorded trajectory: a sudden change of
 * velocity between consecutive frames. Pure and deterministic, so every client hears the same roll.
 */
export function detectImpacts(rec: Pick<Recording, 'frames' | 'poses'>, dice: number): Impact[] {
  const out: Impact[] = []
  const P = rec.poses
  for (let i = 0; i < dice; i++) {
    let last = -Infinity
    const vel = (f: number) => {
      const a = ((f - 1) * dice + i) * 7, b = (f * dice + i) * 7
      return [(P[b] - P[a]) * RECORD_HZ, (P[b + 1] - P[a + 1]) * RECORD_HZ, (P[b + 2] - P[a + 2]) * RECORD_HZ]
    }
    for (let f = 2; f < rec.frames; f++) {
      const [ax, ay, az] = vel(f - 1), [bx, by, bz] = vel(f)
      const dv = Math.hypot(bx - ax, by - ay - 0, bz - az)
      const t = f / RECORD_HZ
      if (dv >= MIN_DV && t - last >= MIN_GAP) {
        out.push({ t, strength: Math.min(1, dv / 25) })
        last = t
      }
    }
  }
  out.sort((a, b) => a.t - b.t || b.strength - a.strength)
  return out.slice(0, MAX_IMPACTS)
}
