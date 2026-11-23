import * as THREE from 'three'
import { arrange, type Pose } from './arrange'
import { dieDef, type DieType } from './geometry'
import { add, cross, qmul, scale, slerp, sub, v, type Quat } from './math'
import type { RollAnimationRecord } from './record'
import { RECORD_HZ, simulateRoll, TABLE, type Rapier } from './sim'
import type { DiceSkin } from './skins'
import { relabel } from './symmetry'

/** Client-side DiceAnimator (ADR 0002): presentation only, never changes results. */
export type AnimationMode = 'animated' | 'reduced' | 'instant'
export interface DiceAnimator {
  play(record: RollAnimationRecord, mode: AnimationMode): Promise<PlayStats>
  skip(): void
  resize(width: number, height: number): void
  dispose(): void
}
export interface PlayStats { simulationMs: number; animationS: number; attempt: number; order: number[] }

const ARRANGE_S = 0.55
const smoother = (s: number) => s * s * s * (s * (s * 6 - 15) + 10)

const FINISH: Record<DiceSkin['finish'], { roughness: number; metalness: number }> = {
  matte: { roughness: 0.85, metalness: 0 },
  satin: { roughness: 0.5, metalness: 0 },
  gloss: { roughness: 0.2, metalness: 0 },
  metal: { roughness: 0.35, metalness: 0.85 },
}

interface Die { group: THREE.Group; relabel: Quat }

export class DiceStage implements DiceAnimator {
  private readonly renderer: THREE.WebGLRenderer
  private readonly scene = new THREE.Scene()
  private readonly camera = new THREE.PerspectiveCamera(28, 16 / 10, 1, 200)
  private readonly geometries = new Map<DieType, { body: THREE.BufferGeometry; labels: THREE.BufferGeometry }>()
  private readonly materials = new Map<string, THREE.Material>()
  private readonly disposables: { dispose(): void }[] = []
  private dice: Die[] = []
  private frame = 0
  private finish: (() => void) | null = null
  private lost = false

  constructor(private readonly canvas: HTMLCanvasElement, private readonly rapier: Rapier) {
    this.renderer = new THREE.WebGLRenderer({ canvas, antialias: true, powerPreference: 'high-performance' })
    this.renderer.setPixelRatio(Math.min(globalThis.devicePixelRatio || 1, 2))
    this.renderer.outputColorSpace = THREE.SRGBColorSpace
    this.renderer.toneMapping = THREE.ACESFilmicToneMapping
    this.renderer.shadowMap.enabled = true
    this.renderer.shadowMap.type = THREE.PCFShadowMap
    canvas.addEventListener('webglcontextlost', this.onLost)
    this.buildTable()
  }

  private readonly onLost = (event: Event): void => {
    event.preventDefault()
    this.lost = true
    this.skip()
  }

  private buildTable(): void {
    const { halfX, halfZ } = TABLE
    this.scene.background = new THREE.Color('#0b1411')
    this.scene.add(new THREE.HemisphereLight(0xdfe8ff, 0x1a1410, 1.1))
    const sun = new THREE.DirectionalLight(0xffffff, 2.2)
    sun.position.set(-4, 16, 7)
    sun.castShadow = true
    sun.shadow.mapSize.set(1024, 1024)
    Object.assign(sun.shadow.camera, { left: -halfX - 1, right: halfX + 1, top: halfZ + 1, bottom: -halfZ - 1, near: 1, far: 40 })
    sun.shadow.bias = -0.0005
    sun.shadow.normalBias = 0.02
    this.scene.add(sun)
    const feltGeo = new THREE.PlaneGeometry(halfX * 2, halfZ * 2)
    const felt = new THREE.MeshStandardMaterial({ color: '#1f4a3c', roughness: 1 })
    const table = new THREE.Mesh(feltGeo, felt)
    table.rotation.x = -Math.PI / 2
    table.receiveShadow = true
    this.scene.add(table)
    const rimGeo = new THREE.BoxGeometry(1, 0.8, 1)
    const wood = new THREE.MeshStandardMaterial({ color: '#3a2a1c', roughness: 0.7 })
    for (const [sx, sz, x, z] of [[halfX * 2 + 2, 1, 0, -halfZ - 0.5], [halfX * 2 + 2, 1, 0, halfZ + 0.5], [1, halfZ * 2, -halfX - 0.5, 0], [1, halfZ * 2, halfX + 0.5, 0]]) {
      const rim = new THREE.Mesh(rimGeo, wood)
      rim.scale.set(sx, 1, sz)
      rim.position.set(x, 0.4, z)
      rim.receiveShadow = true
      this.scene.add(rim)
    }
    this.disposables.push(feltGeo, felt, rimGeo, wood)
  }

  resize(width: number, height: number): void {
    const w = Math.max(1, Math.round(width)), h = Math.max(1, Math.round(height))
    this.renderer.setSize(w, h, false)
    const aspect = w / h, tan = Math.tan(THREE.MathUtils.degToRad(this.camera.fov / 2))
    // Fixed top-down view that always shows the whole physical table.
    const height3d = Math.max((TABLE.halfZ + 0.45) / tan, (TABLE.halfX + 0.45) / (tan * aspect)) + 1.2
    this.camera.aspect = aspect
    this.camera.position.set(0, height3d, 0.001)
    this.camera.up.set(0, 0, -1)
    this.camera.lookAt(0, 0, 0)
    this.camera.updateProjectionMatrix()
    this.render()
  }

  private render(): void {
    if (!this.lost) this.renderer.render(this.scene, this.camera)
  }

  private geometry(type: DieType): { body: THREE.BufferGeometry; labels: THREE.BufferGeometry } {
    const cached = this.geometries.get(type)
    if (cached) return cached
    const def = dieDef(type)
    const cols = 5, rows = Math.ceil(def.sides / cols)
    const pos: number[] = [], nor: number[] = [], lp: number[] = [], ln: number[] = [], luv: number[] = []
    for (const f of def.faces) {
      for (let k = 1; k < f.verts.length - 1; k++) {
        for (const p of [f.verts[0], f.verts[k], f.verts[k + 1]]) { pos.push(p.x, p.y, p.z); nor.push(f.normal.x, f.normal.y, f.normal.z) }
      }
      const x = cross(f.up, f.normal), h = f.labelSize / 2
      const c = add(f.center, scale(f.normal, 0.003))
      const corner = (sx: number, sy: number) => add(c, add(scale(x, sx * h), scale(f.up, sy * h)))
      const cell = f.number - 1, u0 = (cell % cols) / cols, v1 = 1 - Math.floor(cell / cols) / rows
      const u1 = u0 + 1 / cols, v0 = v1 - 1 / rows
      const quad: [ReturnType<typeof corner>, number, number][] = [
        [corner(-1, -1), u0, v0], [corner(1, -1), u1, v0], [corner(1, 1), u1, v1],
        [corner(-1, -1), u0, v0], [corner(1, 1), u1, v1], [corner(-1, 1), u0, v1],
      ]
      for (const [p, u, w] of quad) { lp.push(p.x, p.y, p.z); ln.push(f.normal.x, f.normal.y, f.normal.z); luv.push(u, w) }
    }
    const body = new THREE.BufferGeometry()
    body.setAttribute('position', new THREE.Float32BufferAttribute(pos, 3))
    body.setAttribute('normal', new THREE.Float32BufferAttribute(nor, 3))
    const labels = new THREE.BufferGeometry()
    labels.setAttribute('position', new THREE.Float32BufferAttribute(lp, 3))
    labels.setAttribute('normal', new THREE.Float32BufferAttribute(ln, 3))
    labels.setAttribute('uv', new THREE.Float32BufferAttribute(luv, 2))
    const entry = { body, labels }
    this.geometries.set(type, entry)
    return entry
  }

  private material(key: string, create: () => THREE.Material): THREE.Material {
    let m = this.materials.get(key)
    if (!m) { m = create(); this.materials.set(key, m) }
    return m
  }

  /** One texture atlas per (sides, ink): a die costs two draw calls whatever its face count. */
  private labelMaterial(sides: number, ink: string): THREE.Material {
    return this.material(`label:${sides}:${ink}`, () => {
      const cols = 5, rows = Math.ceil(sides / cols), cell = 128
      const canvas = document.createElement('canvas')
      canvas.width = cols * cell
      canvas.height = rows * cell
      const g = canvas.getContext('2d')
      if (!g) throw new Error('2D canvas unavailable')
      g.fillStyle = ink
      g.textAlign = 'center'
      g.textBaseline = 'middle'
      for (let n = 1; n <= sides; n++) {
        const text = String(n), size = text.length > 1 ? 74 : 92
        g.font = `800 ${size}px system-ui, "Segoe UI", Arial, sans-serif`
        const cx = ((n - 1) % cols) * cell + cell / 2, cy = Math.floor((n - 1) / cols) * cell + cell / 2
        g.fillText(text, cx, cy)
        if (sides >= 9 && (n === 6 || n === 9)) { const w = g.measureText(text).width; g.fillRect(cx - w / 2, cy + size * 0.42, w, 7) }
      }
      const texture = new THREE.CanvasTexture(canvas)
      texture.colorSpace = THREE.SRGBColorSpace
      texture.anisotropy = Math.min(4, this.renderer.capabilities.getMaxAnisotropy())
      this.disposables.push(texture)
      return new THREE.MeshStandardMaterial({ map: texture, alphaTest: 0.45, roughness: 0.5, polygonOffset: true, polygonOffsetFactor: -2, polygonOffsetUnits: -2 })
    })
  }

  private bodyMaterial(skin: DiceSkin): THREE.Material {
    return this.material(`body:${skin.body}:${skin.finish}`, () => new THREE.MeshStandardMaterial({ color: skin.body, ...FINISH[skin.finish], flatShading: true }))
  }

  private clearDice(): void {
    for (const d of this.dice) this.scene.remove(d.group)
    this.dice = []
  }

  private place(die: Die, pose: Pose): void {
    die.group.position.set(pose.p.x, pose.p.y, pose.p.z)
    die.group.quaternion.set(pose.q.x, pose.q.y, pose.q.z, pose.q.w)
  }

  skip(): void {
    const done = this.finish
    this.finish = null
    done?.()
  }

  async play(record: RollAnimationRecord, mode: AnimationMode): Promise<PlayStats> {
    this.skip()
    cancelAnimationFrame(this.frame)
    this.clearDice()
    const types = record.dice.map(d => d.type)
    const t0 = performance.now()
    const rec = simulateRoll(this.rapier, types, record.seed)
    const simulationMs = performance.now() - t0
    const n = types.length

    this.dice = record.dice.map((d, i) => {
      const geo = this.geometry(d.type)
      const body = new THREE.Mesh(geo.body, this.bodyMaterial(record.skin))
      body.castShadow = true
      const labels = new THREE.Mesh(geo.labels, this.labelMaterial(dieDef(d.type).sides, record.skin.ink))
      const group = new THREE.Group()
      group.add(body, labels)
      this.scene.add(group)
      return { group, relabel: relabel(d.type, d.result, rec.landed[i]) }
    })

    const pose = (frame: number, i: number): Pose => {
      const o = (frame * n + i) * 7, P = rec.poses
      return { p: v(P[o], P[o + 1], P[o + 2]), q: qmul({ x: P[o + 3], y: P[o + 4], z: P[o + 5], w: P[o + 6] }, this.dice[i].relabel) }
    }
    const finals = record.dice.map((_, i) => pose(rec.frames - 1, i))
    const { targets, order } = arrange(record.dice, finals)
    const physicsS = (rec.frames - 1) / RECORD_HZ
    const stats: PlayStats = { simulationMs, animationS: mode === 'animated' ? physicsS + ARRANGE_S : 0, attempt: rec.attempt, order }
    const showFinal = () => { this.dice.forEach((d, i) => this.place(d, targets[i])); this.render() }

    if (mode !== 'animated' || this.lost) { showFinal(); return stats }

    await new Promise<void>(resolve => {
      this.finish = () => { cancelAnimationFrame(this.frame); showFinal(); resolve() }
      const start = performance.now()
      const tick = (now: number) => {
        const t = (now - start) / 1000
        if (t < physicsS) {
          const f = t * RECORD_HZ, a = Math.floor(f), b = Math.min(a + 1, rec.frames - 1), k = f - a
          this.dice.forEach((d, i) => {
            const pa = pose(a, i), pb = pose(b, i)
            this.place(d, { p: add(pa.p, scale(sub(pb.p, pa.p), k)), q: slerp(pa.q, pb.q, k) })
          })
        } else {
          const s = Math.min(1, (t - physicsS) / ARRANGE_S), e = smoother(s), lift = Math.sin(Math.PI * e) * 0.9
          this.dice.forEach((d, i) => {
            const from = finals[i], to = targets[i]
            const p = add(from.p, scale(sub(to.p, from.p), e))
            this.place(d, { p: v(p.x, p.y + lift, p.z), q: slerp(from.q, to.q, e) })
          })
          if (s >= 1) { this.skip(); return }
        }
        this.render()
        this.frame = requestAnimationFrame(tick)
      }
      this.frame = requestAnimationFrame(tick)
    })
    return stats
  }

  dispose(): void {
    this.skip()
    cancelAnimationFrame(this.frame)
    this.canvas.removeEventListener('webglcontextlost', this.onLost)
    this.clearDice()
    for (const g of this.geometries.values()) { g.body.dispose(); g.labels.dispose() }
    for (const m of this.materials.values()) m.dispose()
    for (const d of this.disposables) d.dispose()
    this.renderer.dispose()
  }
}
