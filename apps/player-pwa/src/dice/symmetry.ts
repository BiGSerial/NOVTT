import { dieDef, type DieType } from './geometry'
import { conjugate, cross, dot, fromBasis, qmul, length, normalize, rotate, scale, sub, type Quat, type Vec3 } from './math'

/**
 * Face relabelling by rotational symmetry. Physics only sees the convex hull, which every
 * rotational symmetry of the die maps onto itself. So after a natural, unconstrained throw that
 * lands on physical face L, the rendered mesh can be turned by the symmetry R with R·n(T) = n(L):
 * the target number T is then on top, the visual shape matches the collision shape exactly, and
 * no search, correction or forced torque is ever needed.
 */
const tables = new Map<DieType, Quat[][]>()

function frame(normal: Vec3, center: Vec3, vertex: Vec3): [Vec3, Vec3, Vec3] {
  const r = sub(vertex, center)
  const x = normalize(sub(r, scale(normal, dot(r, normal))))
  return [x, normal, cross(x, normal)]
}

function mapsHullToItself(q: Quat, vertices: Vec3[]): boolean {
  return vertices.every(p => {
    const r = rotate(q, p)
    return vertices.some(o => length(sub(o, r)) < 1e-6)
  })
}

/** table[t][l]: rotation taking face index t onto face index l. */
export function symmetryTable(type: DieType): Quat[][] {
  const cached = tables.get(type)
  if (cached) return cached
  const def = dieDef(type)
  const table = def.faces.map(t => def.faces.map(l => {
    const [tx, ty, tz] = frame(t.normal, t.center, t.verts[0])
    for (const w of l.verts) {
      const [lx, ly, lz] = frame(l.normal, l.center, w)
      // R = B_l · B_tᵀ, composed as quaternions: q_l · q_t⁻¹.
      const qt = fromBasis(tx, ty, tz), ql = fromBasis(lx, ly, lz)
      const q = qmul(ql, conjugate(qt))
      if (mapsHullToItself(q, def.vertices)) return q
    }
    throw new Error(`${type}: no symmetry maps face ${t.index} to ${l.index}`)
  }))
  tables.set(type, table)
  return table
}

/** Rotation (in body space) that puts the face numbered `result` where physical face `landed` is. */
export function relabel(type: DieType, result: number, landedFace: number): Quat {
  const def = dieDef(type)
  const target = def.faces.find(f => f.number === result)
  if (!target) throw new Error(`${type}: no face numbered ${result}`)
  return symmetryTable(type)[target.index][landedFace]
}
