import { dieDef, type DieType } from './geometry'
import { add, normalize, randomRotation, randomUnit, rotate, scale, seeded, v, type Quat } from './math'

/** Rapier's deterministic build: bit-identical simulation on every platform (same seed ⇒ same throw). */
export type Rapier = typeof import('@dimforge/rapier3d-deterministic')

/** Fixed physical table shared by every client: same seed ⇒ same throw everywhere. */
export const TABLE = { halfX: 6.6, halfZ: 4.1, wall: 4 } as const
export const PHYSICS_HZ = 120
export const RECORD_HZ = 60
const GRAVITY = 60
const MAX_STEPS = 6 * PHYSICS_HZ
const MIN_STEPS = Math.round(0.5 * PHYSICS_HZ)
const REST_STEPS = Math.round(0.15 * PHYSICS_HZ)
const MAX_ATTEMPTS = 4
const FLAT_COS = 0.99 // top face within ~8° of vertical
/**
 * Spin. A die thrown by hand tumbles forward (around −x, rolling towards −z) with some wobble;
 * a purely random axis with low speed reads as "sliding", not rolling. Low angular damping keeps
 * the spin alive in the air; contact friction then converts it into visible rolling.
 */
const SPIN_MIN = 34, SPIN_RANGE = 12 // ≤ 46 rad/s ≈ 44° per 60 Hz frame: faster reads as spinning backwards (wagon-wheel) on a d6
const SPIN_WOBBLE = 0.65
const ANGULAR_DAMPING = 0.04

export interface Recording {
  frames: number
  /** Per frame, per die: px, py, pz, qx, qy, qz, qw (body pose, before relabelling). */
  poses: Float32Array
  /** Physical face index that ended on top, per die. */
  landed: number[]
  attempt: number
  steps: number
}

/** Integer hash used to derive one independent launch per retry from the roll seed. */
function mix(seed: number, attempt: number): number {
  let h = (seed ^ Math.imul(attempt + 1, 0x9e3779b1)) >>> 0
  h = Math.imul(h ^ (h >>> 16), 0x85ebca6b) >>> 0
  h = Math.imul(h ^ (h >>> 13), 0xc2b2ae35) >>> 0
  return (h ^ (h >>> 16)) >>> 0
}

function topFace(type: DieType, q: Quat): { index: number; cos: number } {
  let index = 0, cos = -2
  for (const f of dieDef(type).faces) {
    const y = rotate(q, f.normal).y
    if (y > cos) { cos = y; index = f.index }
  }
  return { index, cos }
}

function simulateOnce(R: Rapier, dice: readonly DieType[], seed: number): Omit<Recording, 'attempt'> & { flat: boolean } {
  const rng = seeded(seed)
  const world = new R.World({ x: 0, y: -GRAVITY, z: 0 })
  world.timestep = 1 / PHYSICS_HZ
  try {
    const box = (hx: number, hy: number, hz: number, x: number, y: number, z: number) =>
      world.createCollider(R.ColliderDesc.cuboid(hx, hy, hz).setTranslation(x, y, z).setFriction(0.6).setRestitution(0.3))
    const { halfX, halfZ, wall } = TABLE
    box(halfX + 1, 0.5, halfZ + 1, 0, -0.5, 0)
    box(0.5, wall, halfZ + 1, -halfX - 0.5, wall, 0)
    box(0.5, wall, halfZ + 1, halfX + 0.5, wall, 0)
    box(halfX + 1, wall, 0.5, 0, wall, -halfZ - 0.5)
    box(halfX + 1, wall, 0.5, 0, wall, halfZ + 0.5)
    box(halfX + 1, 0.5, halfZ + 1, 0, wall * 2 + 0.5, 0)

    // Thrown from the player's edge (+z, bottom of the screen) towards the far wall.
    const spacing = 2.05, x0 = -((dice.length - 1) * spacing) / 2
    const bodies = dice.map((type, i) => {
      const def = dieDef(type)
      const q = randomRotation(rng)
      const spin = normalize(add(v(-1, 0, 0), scale(randomUnit(rng), SPIN_WOBBLE))), w = SPIN_MIN + rng() * SPIN_RANGE
      const body = world.createRigidBody(R.RigidBodyDesc.dynamic()
        .setTranslation(x0 + i * spacing + (rng() - 0.5) * 0.3, 1.4 + rng() * 0.9, halfZ - 1.2 + (rng() - 0.5) * 0.3)
        .setRotation(q)
        .setLinvel((rng() - 0.5) * 4, 5.5 + rng() * 2.5, -(8 + rng() * 5))
        .setAngvel({ x: spin.x * w, y: spin.y * w, z: spin.z * w })
        .setLinearDamping(0.12).setAngularDamping(ANGULAR_DAMPING).setCcdEnabled(true))
      const hull = R.ColliderDesc.convexHull(new Float32Array(def.vertices.flatMap(p => [p.x, p.y, p.z])))
      if (!hull) throw new Error(`convex hull failed for ${type}`)
      world.createCollider(hull.setRestitution(0.35).setFriction(0.5).setDensity(1), body)
      return body
    })

    const n = dice.length
    const capacity = Math.floor(MAX_STEPS / (PHYSICS_HZ / RECORD_HZ)) + 2
    const poses = new Float32Array(capacity * n * 7)
    let frames = 0
    const record = () => {
      let o = frames * n * 7
      for (const b of bodies) {
        const p = b.translation(), q = b.rotation()
        poses[o++] = p.x; poses[o++] = p.y; poses[o++] = p.z; poses[o++] = q.x; poses[o++] = q.y; poses[o++] = q.z; poses[o++] = q.w
      }
      frames++
    }
    record()
    let steps = 0, rest = 0
    while (steps < MAX_STEPS) {
      world.step()
      steps++
      if (steps % (PHYSICS_HZ / RECORD_HZ) === 0) record()
      const still = bodies.every(b => {
        if (b.isSleeping()) return true
        const l = b.linvel(), a = b.angvel()
        return l.x * l.x + l.y * l.y + l.z * l.z < 0.01 && a.x * a.x + a.y * a.y + a.z * a.z < 0.02
      })
      rest = still ? rest + 1 : 0
      if (steps >= MIN_STEPS && rest >= REST_STEPS) break
    }
    if (steps % (PHYSICS_HZ / RECORD_HZ) !== 0) record()

    let flat = true
    const landed = bodies.map((b, i) => {
      const top = topFace(dice[i], b.rotation())
      const face = dieDef(dice[i]).faces[top.index]
      if (top.cos < FLAT_COS || b.translation().y > face.dist + 0.08) flat = false
      return top.index
    })
    return { frames, poses: poses.slice(0, frames * n * 7), landed, steps, flat }
  } finally {
    world.free()
  }
}

/**
 * Deterministic, unconstrained throw. Retries (with a derived seed) only when a die ends cocked
 * against a wall or another die; the outcome face is irrelevant because results are applied by
 * relabelling (symmetry.ts), so there is never a search for a "matching" throw.
 */
export function simulateRoll(R: Rapier, dice: readonly DieType[], seed: number): Recording {
  let last: (Omit<Recording, 'attempt'> & { flat: boolean }) | null = null
  for (let attempt = 0; attempt < MAX_ATTEMPTS; attempt++) {
    last = simulateOnce(R, dice, mix(seed, attempt))
    if (last.flat) return { frames: last.frames, poses: last.poses, landed: last.landed, steps: last.steps, attempt }
  }
  const r = last!
  return { frames: r.frames, poses: r.poses, landed: r.landed, steps: r.steps, attempt: MAX_ATTEMPTS - 1 }
}
