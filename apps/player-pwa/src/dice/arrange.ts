import { dieDef, type DieType } from './geometry'
import { fromUnitVectors, qmul, rotate, UP, v, yawBetween, type Quat, type Vec3 } from './math'
import { TABLE, type Table } from './sim'

export interface Pose { p: Vec3; q: Quat }

/** Screen-up on the fixed top-down camera is −z: numbers are turned to read upright. */
const SCREEN_UP = v(0, 0, -1)
const SPACING = 2.4
const PAIR_SPACING = 2.15
/** Distance from the player's edge to the row's centre line. */
export const ROW_INSET = 1.35
/** Distance between rows when a pool wraps. */
const ROW_GAP = 2.3
/** Below this squeeze a row would look crowded: wrap instead. */
const MIN_SQUEEZE = 0.92

/**
 * Final presentation: dice are pulled to a row at the player's edge (bottom of the screen) in
 * the order of the results (the same sequence as the result card; owner request), resting flat on the result
 * with the number upright. Dice of the same group (a d100 pair) stay side by side, tens first.
 * Returns the target pose per physical die and the left-to-right order of the groups.
 */
export function arrange(dice: { shape: DieType; face: number; group: number }[], finals: Pose[], table: Table = TABLE, rowInset = ROW_INSET): { targets: Pose[]; order: number[] } {
  const groups = [...new Set(dice.map(d => d.group))]
  const order = groups.sort((a, b) => a - b)
  const reach = Math.max(...dice.map(d => dieDef(d.shape).radius))
  const room = table.halfX - 0.25 - reach
  // How many dice fit side by side without squeezing below MIN_SQUEEZE; large official pools
  // (up to 12 dice) wrap into more rows instead of overlapping (first row on top, reading order).
  const fit = Math.max(1, Math.floor((2 * room) / (SPACING * MIN_SQUEEZE)) + 1)
  const groupSize = (g: number) => dice.filter(d => d.group === g).length
  const rows: number[][] = [[]]
  let used = 0
  for (const g of order) {
    const n = groupSize(g)
    if (used + n > fit && rows[rows.length - 1].length) { rows.push([]); used = 0 }
    rows[rows.length - 1].push(g)
    used += n
  }
  const targets: Pose[] = new Array(dice.length)
  rows.forEach((rowGroups, r) => {
    const slots: number[] = []
    const xs: number[] = []
    let x = 0
    let previous: number | null = null
    for (const g of rowGroups) {
      dice.forEach((d, i) => {
        if (d.group !== g) return
        if (slots.length) x += previous === g ? PAIR_SPACING : SPACING
        slots.push(i)
        xs.push(x)
        previous = g
      })
    }
    // Shrink the gaps if the row would still overflow the table (6 large dice on a narrow table).
    const squeeze = x / 2 > room ? room / (x / 2) : 1
    const offset = (x * squeeze) / 2
    const z = table.halfZ - rowInset - (rows.length - 1 - r) * ROW_GAP
    slots.forEach((i, slot) => {
      const def = dieDef(dice[i].shape)
      const feature = def.faces.find(f => f.number === dice[i].face)!
      const q = finals[i].q
      // 1) Settle exactly on the result; 2) yaw the label upright for the viewer.
      const flat = qmul(fromUnitVectors(rotate(q, feature.normal), UP), q)
      const upright = qmul(yawBetween(rotate(flat, feature.up), SCREEN_UP), flat)
      targets[i] = { p: v(xs[slot] * squeeze - offset, feature.dist, z), q: upright }
    })
  })
  return { targets, order }
}
