/** Minimal vector/quaternion math, free of trigonometry where results feed the physics
 *  (Math.sin/cos are not bit-identical across JS engines; sqrt and + − × ÷ are). */
export interface Vec3 { x: number; y: number; z: number }
export interface Quat { x: number; y: number; z: number; w: number }

export const v = (x: number, y: number, z: number): Vec3 => ({ x, y, z })
export const add = (a: Vec3, b: Vec3): Vec3 => v(a.x + b.x, a.y + b.y, a.z + b.z)
export const sub = (a: Vec3, b: Vec3): Vec3 => v(a.x - b.x, a.y - b.y, a.z - b.z)
export const scale = (a: Vec3, s: number): Vec3 => v(a.x * s, a.y * s, a.z * s)
export const dot = (a: Vec3, b: Vec3): number => a.x * b.x + a.y * b.y + a.z * b.z
export const cross = (a: Vec3, b: Vec3): Vec3 => v(a.y * b.z - a.z * b.y, a.z * b.x - a.x * b.z, a.x * b.y - a.y * b.x)
export const length = (a: Vec3): number => Math.sqrt(dot(a, a))
export const normalize = (a: Vec3): Vec3 => scale(a, 1 / length(a))
export const UP: Vec3 = v(0, 1, 0)
export const IDENTITY: Quat = { x: 0, y: 0, z: 0, w: 1 }

export function qmul(a: Quat, b: Quat): Quat {
  return {
    x: a.w * b.x + a.x * b.w + a.y * b.z - a.z * b.y,
    y: a.w * b.y - a.x * b.z + a.y * b.w + a.z * b.x,
    z: a.w * b.z + a.x * b.y - a.y * b.x + a.z * b.w,
    w: a.w * b.w - a.x * b.x - a.y * b.y - a.z * b.z,
  }
}

export const conjugate = (q: Quat): Quat => ({ x: -q.x, y: -q.y, z: -q.z, w: q.w })

export function qnormalize(q: Quat): Quat {
  const n = Math.sqrt(q.x * q.x + q.y * q.y + q.z * q.z + q.w * q.w)
  return { x: q.x / n, y: q.y / n, z: q.z / n, w: q.w / n }
}

export function rotate(q: Quat, p: Vec3): Vec3 {
  // p' = p + 2w(u×p) + 2u×(u×p)
  const u = v(q.x, q.y, q.z)
  const t = scale(cross(u, p), 2)
  return add(add(p, scale(t, q.w)), cross(u, t))
}

/** Shortest rotation taking unit vector a onto unit vector b. */
export function fromUnitVectors(a: Vec3, b: Vec3): Quat {
  const r = dot(a, b) + 1
  if (r < 1e-9) {
    const axis = Math.abs(a.x) > Math.abs(a.z) ? v(-a.y, a.x, 0) : v(0, -a.z, a.y)
    return qnormalize({ ...axis, w: 0 })
  }
  const c = cross(a, b)
  return qnormalize({ x: c.x, y: c.y, z: c.z, w: r })
}

/** Rotation whose columns are the orthonormal basis (x, y, z). */
export function fromBasis(x: Vec3, y: Vec3, z: Vec3): Quat {
  const m00 = x.x, m01 = y.x, m02 = z.x, m10 = x.y, m11 = y.y, m12 = z.y, m20 = x.z, m21 = y.z, m22 = z.z
  const trace = m00 + m11 + m22
  let q: Quat
  if (trace > 0) {
    const s = 0.5 / Math.sqrt(trace + 1)
    q = { w: 0.25 / s, x: (m21 - m12) * s, y: (m02 - m20) * s, z: (m10 - m01) * s }
  } else if (m00 > m11 && m00 > m22) {
    const s = 2 * Math.sqrt(1 + m00 - m11 - m22)
    q = { w: (m21 - m12) / s, x: 0.25 * s, y: (m01 + m10) / s, z: (m02 + m20) / s }
  } else if (m11 > m22) {
    const s = 2 * Math.sqrt(1 + m11 - m00 - m22)
    q = { w: (m02 - m20) / s, x: (m01 + m10) / s, y: 0.25 * s, z: (m12 + m21) / s }
  } else {
    const s = 2 * Math.sqrt(1 + m22 - m00 - m11)
    q = { w: (m10 - m01) / s, x: (m02 + m20) / s, y: (m12 + m21) / s, z: 0.25 * s }
  }
  return qnormalize(q)
}

/** Rotation about +Y taking the horizontal direction `from` onto `to` (both in the XZ plane). */
export function yawBetween(from: Vec3, to: Vec3): Quat {
  const a = normalize(v(from.x, 0, from.z)), b = normalize(v(to.x, 0, to.z))
  return fromUnitVectors(a, b)
}

export function slerp(a: Quat, b: Quat, t: number): Quat {
  let cos = a.x * b.x + a.y * b.y + a.z * b.z + a.w * b.w
  let bb = b
  if (cos < 0) { cos = -cos; bb = { x: -b.x, y: -b.y, z: -b.z, w: -b.w } }
  if (cos > 0.9995) {
    return qnormalize({ x: a.x + (bb.x - a.x) * t, y: a.y + (bb.y - a.y) * t, z: a.z + (bb.z - a.z) * t, w: a.w + (bb.w - a.w) * t })
  }
  const theta = Math.acos(cos), sin = Math.sin(theta)
  const wa = Math.sin((1 - t) * theta) / sin, wb = Math.sin(t * theta) / sin
  return { x: a.x * wa + bb.x * wb, y: a.y * wa + bb.y * wb, z: a.z * wa + bb.z * wb, w: a.w * wa + bb.w * wb }
}

/** mulberry32: small, fast, seedable PRNG for reproducible launches (never for results). */
export function seeded(seed: number): () => number {
  let a = seed >>> 0
  return () => {
    a = (a + 0x6d2b79f5) | 0
    let t = Math.imul(a ^ (a >>> 15), 1 | a)
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296
  }
}

/** Uniform random rotation by rejection sampling in the 4-ball (no trigonometry). */
export function randomRotation(rng: () => number): Quat {
  for (;;) {
    const x = rng() * 2 - 1, y = rng() * 2 - 1, z = rng() * 2 - 1, w = rng() * 2 - 1
    const n = x * x + y * y + z * z + w * w
    if (n > 1e-6 && n <= 1) { const s = 1 / Math.sqrt(n); return { x: x * s, y: y * s, z: z * s, w: w * s } }
  }
}

/** Uniform random unit vector by rejection sampling in the 3-ball. */
export function randomUnit(rng: () => number): Vec3 {
  for (;;) {
    const p = v(rng() * 2 - 1, rng() * 2 - 1, rng() * 2 - 1)
    const n = dot(p, p)
    if (n > 1e-6 && n <= 1) return scale(p, 1 / Math.sqrt(n))
  }
}
