import { DIE_TYPES, isDieType, SIDES, type DieType } from './geometry'
import { DEFAULT_SKIN, parseSkin, type DiceSkin } from './skins'

/**
 * What a client needs to reproduce a roll animation: the dice, their results, the physics seed
 * and the skin. Results come from the authority (crypto RNG for local free rolls, the server for
 * official rolls); the seed only shapes the throw. Same record ⇒ same animation on every client.
 */
export const CONTRACT = 'novtt.dice-roll-animation/1'
export const MAX_DICE = 6

export interface RollAnimationRecord {
  contract: typeof CONTRACT
  id: string
  origin: 'local_unofficial'
  seed: number
  dice: { type: DieType; result: number }[]
  skin: DiceSkin
}

function secureInt(max: number): number {
  // Rejection sampling: unbiased integer in [1, max].
  const range = 0x100000000, ceiling = range - (range % max), buf = new Uint32Array(1)
  do crypto.getRandomValues(buf); while (buf[0] >= ceiling)
  return (buf[0] % max) + 1
}

export function createLocalRoll(type: DieType, count: number, skin: DiceSkin = DEFAULT_SKIN): RollAnimationRecord {
  if (!Number.isSafeInteger(count) || count < 1 || count > MAX_DICE) throw new RangeError(`Use de 1 a ${MAX_DICE} dados.`)
  const seed = new Uint32Array(1)
  crypto.getRandomValues(seed)
  return {
    contract: CONTRACT,
    id: crypto.randomUUID(),
    origin: 'local_unofficial',
    seed: seed[0],
    dice: Array.from({ length: count }, () => ({ type, result: secureInt(SIDES[type]) })),
    // Plain copy: the record must be structured-cloneable (no framework proxies).
    skin: { id: skin.id, name: skin.name, body: skin.body, ink: skin.ink, finish: skin.finish },
  }
}

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
    if (Object.keys(rest).length || !isDieType(type) || !Number.isInteger(result) || (result as number) < 1 || (result as number) > SIDES[type]) return null
    dice.push({ type, result: result as number })
  }
  const skin = parseSkin(r.skin)
  if (!skin) return null
  return { contract: CONTRACT, id: r.id, origin: 'local_unofficial', seed: r.seed as number, dice, skin }
}

export const SUPPORTED_TYPES = DIE_TYPES
