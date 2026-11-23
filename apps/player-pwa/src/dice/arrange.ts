import { dieDef, type DieType } from './geometry'
import { fromUnitVectors, qmul, rotate, UP, v, yawBetween, type Quat, type Vec3 } from './math'
import { TABLE } from './sim'

export interface Pose { p: Vec3; q: Quat }

/** Screen-up on the fixed top-down camera is −z: numbers are turned to read upright. */
const SCREEN_UP = v(0, 0, -1)

/**
 * Final presentation: dice are pulled to a row at the player's edge (bottom of the screen),
 * in left-to-right order of where they stopped (paths do not cross), resting flat on the
 * result face with the number upright. Returns slot order (indices into `dice`).
 */
export function arrange(dice: { type: DieType; result: number }[], finals: Pose[]): { targets: Pose[]; order: number[] } {
  const order = finals.map((_, i) => i).sort((a, b) => finals[a].p.x - finals[b].p.x || a - b)
  const spacing = 2.4, x0 = -((dice.length - 1) * spacing) / 2
  const targets: Pose[] = new Array(dice.length)
  order.forEach((i, slot) => {
    const def = dieDef(dice[i].type)
    const face = def.faces.find(f => f.number === dice[i].result)!
    const q = finals[i].q
    // 1) Settle exactly flat on the result face; 2) yaw the label upright for the viewer.
    const flat = qmul(fromUnitVectors(rotate(q, face.normal), UP), q)
    const upright = qmul(yawBetween(rotate(flat, face.up), SCREEN_UP), flat)
    targets[i] = { p: v(x0 + slot * spacing, face.dist, TABLE.halfZ - 1.35), q: upright }
  })
  return { targets, order }
}
