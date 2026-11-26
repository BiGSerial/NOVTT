import { CONTRACT, MAX_OFFICIAL_DICE, physicalDice, type RollAnimationRecord, type RollType } from '../dice/record'
import { DEFAULT_SKIN, type DiceSkin } from '../dice/skins'
import type { OfficialRoll, Reading } from './types'

const TYPES: Record<number, RollType> = { 4: 'd4', 6: 'd6', 8: 'd8', 10: 'd10', 12: 'd12', 20: 'd20', 100: 'd100' }

/**
 * Turns a server result into the animation record every client replays (SPEC-MVP-002 D4): the
 * server's faces are the result, the server's seed shapes the throw, so all screens show the
 * same dice settling on the same numbers. Returns null for shapes the 3D stage cannot draw.
 */
export function officialRecord(roll: OfficialRoll, skin: DiceSkin = DEFAULT_SKIN): RollAnimationRecord | null {
  const type = TYPES[roll.dice.faces]
  if (!type || roll.results.length === 0) return null
  const dice = roll.results.map(result => ({ type, result }))
  if (physicalDice(dice).length > MAX_OFFICIAL_DICE) return null
  return {
    contract: CONTRACT,
    id: roll.id,
    origin: 'official',
    seed: roll.animation_seed >>> 0,
    dice,
    skin: { id: skin.id, name: skin.name, body: skin.body, ink: skin.ink, finish: skin.finish },
  }
}

const READING: Record<Reading, string> = { sum: 'soma', highest: 'maior', successes: 'sucessos' }

/** Short human description of the dice the master chose: "6d10 · sucessos 6+ · precisa 2". */
export function describeDice(d: { faces: number; reading: Reading; threshold: number; target: number | null; bonus?: number | null }, count: number | string): string {
  const parts = [`${count}d${d.faces}`]
  if (d.bonus) parts[0] += d.bonus > 0 ? ` + ${d.bonus}` : ` − ${Math.abs(d.bonus)}`
  parts.push(d.reading === 'successes' ? `sucessos ${d.threshold}+` : READING[d.reading])
  if (d.target !== null && d.target !== undefined) parts.push(d.reading === 'successes' ? `precisa ${d.target}` : `alvo ${d.target}`)
  return parts.join(' · ')
}

/** Outcome line for a result card / feed item. */
export function outcomeText(roll: OfficialRoll): string {
  const value = roll.dice.reading === 'successes' ? `${roll.successes} ${roll.successes === 1 ? 'sucesso' : 'sucessos'}` : `total ${roll.total}`
  if (roll.succeeded === true) return `${value} · Sucesso`
  if (roll.succeeded === false) return `${value} · Falha`
  return value
}

/** Which faces count (for highlighting successes on the card). */
export function faceCounts(roll: OfficialRoll, face: number): boolean {
  if (roll.dice.reading === 'successes') return face >= roll.dice.threshold
  if (roll.dice.reading === 'highest') return face === Math.max(...roll.results)
  return true
}
