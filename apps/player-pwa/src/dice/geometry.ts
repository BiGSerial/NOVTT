import { add, cross, dot, length, normalize, scale, seeded, sub, v, type Vec3 } from './math'

export const DIE_TYPES = ['d6', 'd8', 'd10', 'd12', 'd20'] as const
export type DieType = (typeof DIE_TYPES)[number]
export const SIDES: Record<DieType, number> = { d6: 6, d8: 8, d10: 10, d12: 12, d20: 20 }

export interface Face {
  index: number
  number: number
  normal: Vec3
  center: Vec3
  /** Unit vector in the face plane pointing to the top of the printed number. */
  up: Vec3
  /** Distance from the die centre to the face plane (resting height on this face). */
  dist: number
  labelSize: number
  verts: Vec3[]
}

export interface DieDef {
  type: DieType
  sides: number
  vertices: Vec3[]
  faces: Face[]
  /** Circumradius, used for spacing. */
  radius: number
}

const PHI = (1 + Math.sqrt(5)) / 2
/** Overall die size relative to the physical table (TABLE in sim.ts). */
const SIZE = 1.2

function scaleTo(points: Vec3[], radius: number): Vec3[] {
  const r = Math.max(...points.map(length))
  return points.map(p => scale(p, radius / r))
}

/** Faces of the convex hull of `vertices` (brute force: tiny point sets, computed once). */
function hullPolygons(vertices: Vec3[]): number[][] {
  const normals: Vec3[] = []
  const n = vertices.length
  for (let i = 0; i < n; i++) for (let j = i + 1; j < n; j++) for (let k = j + 1; k < n; k++) {
    const c = cross(sub(vertices[j], vertices[i]), sub(vertices[k], vertices[i]))
    if (length(c) < 1e-9) continue
    let nn = normalize(c)
    const d = dot(nn, vertices[i])
    if (d < 0) nn = scale(nn, -1)
    const off = Math.abs(d)
    if (!vertices.every(p => dot(p, nn) <= off + 1e-9)) continue
    if (!normals.some(m => dot(m, nn) > 1 - 1e-9)) normals.push(nn)
  }
  return normals.map(nn => {
    const best = Math.max(...vertices.map(p => dot(p, nn)))
    return vertices.map((p, i) => (Math.abs(dot(p, nn) - best) < 1e-6 ? i : -1)).filter(i => i >= 0)
  })
}

function build(type: DieType, vertices: Vec3[], polygons: number[][], labelFactor: number): DieDef {
  const sides = SIDES[type]
  const raw = polygons.map((poly, index) => {
    const pts = poly.map(i => vertices[i])
    const center = scale(pts.reduce(add, v(0, 0, 0)), 1 / pts.length)
    let normal = normalize(cross(sub(pts[1], pts[0]), sub(pts[2], pts[0])))
    if (dot(normal, center) < 0) normal = scale(normal, -1)
    // Order counter-clockwise seen from outside (atan2 only affects rendering, not physics).
    const u0 = normalize(sub(sub(pts[0], center), scale(normal, dot(sub(pts[0], center), normal))))
    const w0 = cross(normal, u0)
    const ordered = [...pts].sort((a, b) => {
      const ra = sub(a, center), rb = sub(b, center)
      return Math.atan2(dot(ra, w0), dot(ra, u0)) - Math.atan2(dot(rb, w0), dot(rb, u0))
    })
    // Number points at the vertex farthest from the centre (apex of kites, any vertex otherwise).
    let far = ordered[0]
    for (const p of ordered) if (length(sub(p, center)) > length(sub(far, center)) + 1e-9) far = p
    const toFar = sub(far, center)
    // Squares and pentagons: towards an edge midpoint; triangles and kites: towards a vertex.
    const up = (ordered.length === 4 && type !== 'd10') || ordered.length === 5
      ? normalize(sub(scale(add(ordered[0], ordered[1]), 0.5), center))
      : normalize(sub(toFar, scale(normal, dot(toFar, normal))))
    let inradius = Infinity
    for (let i = 0; i < ordered.length; i++) {
      const a = ordered[i], b = ordered[(i + 1) % ordered.length], e = sub(b, a)
      inradius = Math.min(inradius, length(cross(e, sub(center, a))) / length(e))
    }
    return { index, normal, center, up, dist: dot(normal, ordered[0]), labelSize: labelFactor * inradius, verts: ordered }
  })
  // Opposite faces sum to sides + 1; pairs are shuffled deterministically per type.
  const pairs: [number, number][] = []
  const used = new Set<number>()
  for (const f of raw) {
    if (used.has(f.index)) continue
    const o = raw.find(g => !used.has(g.index) && g.index !== f.index && dot(g.normal, f.normal) < -0.999)
    if (!o) throw new Error(`${type}: face ${f.index} has no opposite`)
    used.add(f.index); used.add(o.index); pairs.push([f.index, o.index])
  }
  const rng = seeded(sides * 7919)
  for (let i = pairs.length - 1; i > 0; i--) { const j = Math.floor(rng() * (i + 1)); [pairs[i], pairs[j]] = [pairs[j], pairs[i]] }
  const numbers = new Array<number>(raw.length)
  pairs.forEach(([a, b], i) => {
    const n = i + 1
    if (i % 2) { numbers[a] = sides + 1 - n; numbers[b] = n } else { numbers[a] = n; numbers[b] = sides + 1 - n }
  })
  const faces = raw.map(f => ({ ...f, number: numbers[f.index] }))
  return { type, sides, vertices, faces, radius: Math.max(...vertices.map(length)) }
}

function d6(): DieDef {
  const vs: Vec3[] = []
  for (const x of [-1, 1]) for (const y of [-1, 1]) for (const z of [-1, 1]) vs.push(v(x, y, z))
  const pts = scaleTo(vs, 0.82 * SIZE)
  return build('d6', pts, hullPolygons(pts), 1.15)
}

function d8(): DieDef {
  const pts = scaleTo([v(1, 0, 0), v(-1, 0, 0), v(0, 1, 0), v(0, -1, 0), v(0, 0, 1), v(0, 0, -1)], 0.95 * SIZE)
  return build('d8', pts, hullPolygons(pts), 1.3)
}

function icosaVertices(): Vec3[] {
  const vs: Vec3[] = []
  for (const a of [-1, 1]) for (const b of [-PHI, PHI]) vs.push(v(0, a, b), v(a, b, 0), v(b, 0, a))
  return vs
}

function dodecaVertices(): Vec3[] {
  const vs: Vec3[] = []
  for (const x of [-1, 1]) for (const y of [-1, 1]) for (const z of [-1, 1]) vs.push(v(x, y, z))
  const i = 1 / PHI
  for (const a of [-1, 1]) for (const b of [-1, 1]) vs.push(v(0, a * i, b * PHI), v(a * i, b * PHI, 0), v(b * PHI, 0, a * i))
  return vs
}

function d12(): DieDef {
  const pts = scaleTo(dodecaVertices(), 0.92 * SIZE)
  return build('d12', pts, hullPolygons(pts), 1.25)
}

function d20(): DieDef {
  const pts = scaleTo(icosaVertices(), 0.95 * SIZE)
  return build('d20', pts, hullPolygons(pts), 1.6)
}

/** Pentagonal trapezohedron with planar kites: t = h(1 − cos36°)/(1 + cos36°). */
function d10(): DieDef {
  const r = 0.78 * SIZE, h = 0.86 * SIZE
  const c = (1 + Math.sqrt(5)) / 4 // cos 36°, exact
  const t = (h * (1 - c)) / (1 + c)
  // Ring directions at 72° steps from exact algebraic values (no runtime trigonometry).
  const cos72 = (Math.sqrt(5) - 1) / 4, sin72 = Math.sqrt(10 + 2 * Math.sqrt(5)) / 4
  const cos36 = c, sin36 = Math.sqrt(10 - 2 * Math.sqrt(5)) / 4
  const ring = (k: number, offset: boolean): [number, number] => {
    let cx = offset ? cos36 : 1, sz = offset ? sin36 : 0
    for (let i = 0; i < k; i++) { const nx = cx * cos72 - sz * sin72; sz = cx * sin72 + sz * cos72; cx = nx }
    return [cx, sz]
  }
  const vs: Vec3[] = [v(0, h, 0), v(0, -h, 0)]
  for (let k = 0; k < 5; k++) { const [x, z] = ring(k, false); vs.push(v(r * x, t, r * z)) }
  for (let k = 0; k < 5; k++) { const [x, z] = ring(k, true); vs.push(v(r * x, -t, r * z)) }
  const U = (k: number) => 2 + (k % 5), L = (k: number) => 7 + (k % 5)
  const polys: number[][] = []
  for (let k = 0; k < 5; k++) polys.push([0, U(k), L(k), U(k + 1)])
  for (let j = 0; j < 5; j++) polys.push([1, L(j), U(j + 1), L(j + 1)])
  return build('d10', vs, polys, 1.45)
}

const cache = new Map<DieType, DieDef>()
export function dieDef(type: DieType): DieDef {
  let def = cache.get(type)
  if (!def) {
    def = { d6, d8, d10, d12, d20 }[type]()
    cache.set(type, def)
  }
  return def
}

export function isDieType(value: unknown): value is DieType {
  return typeof value === 'string' && (DIE_TYPES as readonly string[]).includes(value)
}
