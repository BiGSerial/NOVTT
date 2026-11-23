import { DIE_TYPES, type DieType } from './geometry'
import { DEFAULT_SKIN, parseSkin, type DiceSkin } from './skins'

/**
 * What a client needs to reproduce a roll animation: the dice, their results, the physics seed
 * and the skin. Results come from the authority (crypto RNG for local free rolls, the server for
 * official rolls); the seed only shapes the throw. Same record ⇒ same animation on every client.
 */
export const CONTRACT = 'novtt.dice-roll-animation/1'
/** Physical dice on the table per roll (a d100 uses two). */
export const MAX_DICE = 6

export const ROLL_TYPES = [...DIE_TYPES, 'd100'] as const
export type RollType = (typeof ROLL_TYPES)[number]
export const ROLL_SIDES: Record<RollType, number> = { d4: 4, d6: 6, d8: 8, d10: 10, d12: 12, d20: 20, d100: 100 }

export interface RollAnimationRecord {
  contract: typeof CONTRACT
  id: string
  origin: 'local_unofficial'
  seed: number
  dice: { type: RollType; result: number }[]
  skin: DiceSkin
}

/** How numbers are printed on a physical die. */
export type LabelSet = 'standard' | 'tens' | 'units'

export interface PhysicalDie {
  shape: DieType
  labels: LabelSet
  /** Face number (1..sides) that must end on top. */
  face: number
  /** Index of the logical die (record.dice) it belongs to. */
  group: number
}

export function labelText(set: LabelSet, n: number): string {
  if (set === 'tens') return String((n - 1) * 10).padStart(2, '0')
  if (set === 'units') return String(n - 1)
  return String(n)
}

export const diceOnTable = (type: RollType): number => (type === 'd100' ? 2 : 1)
export const maxCount = (type: RollType): number => Math.floor(MAX_DICE / diceOnTable(type))

/** Expands logical dice into the physical dice simulated and drawn on the table. */
export function physicalDice(dice: RollAnimationRecord['dice']): PhysicalDie[] {
  return dice.flatMap((d, group): PhysicalDie[] => {
    if (d.type !== 'd100') return [{ shape: d.type, labels: 'standard', face: d.result, group }]
    // 100 is shown as 00 + 0; otherwise tens die × 10 + units die.
    const value = d.result % 100
    return [
      { shape: 'd10', labels: 'tens', face: Math.floor(value / 10) + 1, group },
      { shape: 'd10', labels: 'units', face: (value % 10) + 1, group },
    ]
  })
}

function secureInt(max: number): number {
  // Rejection sampling: unbiased integer in [1, max].
  const range = 0x100000000, ceiling = range - (range % max), buf = new Uint32Array(1)
  do crypto.getRandomValues(buf); while (buf[0] >= ceiling)
  return (buf[0] % max) + 1
}

export function createLocalRoll(type: RollType, count: number, skin: DiceSkin = DEFAULT_SKIN): RollAnimationRecord {
  const max = maxCount(type)
  if (!Number.isSafeInteger(count) || count < 1 || count > max) throw new RangeError(`Use de 1 a ${max} ${type}.`)
  const seed = new Uint32Array(1)
  crypto.getRandomValues(seed)
  return {
    contract: CONTRACT,
    id: crypto.randomUUID(),
    origin: 'local_unofficial',
    seed: seed[0],
    dice: Array.from({ length: count }, () => ({ type, result: secureInt(ROLL_SIDES[type]) })),
    // Plain copy: the record must be structured-cloneable (no framework proxies).
    skin: { id: skin.id, name: skin.name, body: skin.body, ink: skin.ink, finish: skin.finish },
  }
}

const isRollType = (value: unknown): value is RollType => typeof value === 'string' && (ROLL_TYPES as readonly string[]).includes(value)

/** Validates an untrusted record (e.g. received from another window). */
export function parseRecord(raw: unknown): RollAnimationRecord | null {
  if (typeof raw !== 'object' || raw === null) return null
  const r = raw as Record<string, unknown>
  if (Object.keys(r).sort().join(',') !== 'contract,dice,id,origin,seed,skin') return null
  if (r.contract !== CONTRACT || r.origin !== 'local_unofficial') return null
  if (typeof r.id !== 'string' || !/^[0-9a-f-]{36}$/i.test(r.id)) return null
  if (!Number.isInteger(r.seed) || (r.seed as number) < 0 || (r.seed as number) > 0xffffffff) return null
  if (!Array.isArray(r.dice) || r.dice.length < 1 || r.dice.length > MAX_DICE) return null
  const dice: RollAnimationRecord['dice'] = []
  for (const d of r.dice as unknown[]) {
    if (typeof d !== 'object' || d === null) return null
    const { type, result, ...rest } = d as Record<string, unknown>
    if (Object.keys(rest).length || !isRollType(type) || !Number.isInteger(result) || (result as number) < 1 || (result as number) > ROLL_SIDES[type]) return null
    dice.push({ type, result: result as number })
  }
  if (physicalDice(dice).length > MAX_DICE) return null
  const skin = parseSkin(r.skin)
  if (!skin) return null
  return { contract: CONTRACT, id: r.id, origin: 'local_unofficial', seed: r.seed as number, dice, skin }
}
