import { describe, expect, it, vi } from 'vitest'
import { officialRecord, outcomeText } from '../src/live/dice'
import { TableState } from '../src/live/table'
import type { OfficialRoll, RollRequest, SessionEvent, TableSnapshot } from '../src/live/types'
import { arrange } from '../src/dice/arrange'
import { overlayTable } from '../src/dice/sim'

const SID = '00000000-0000-4000-8000-000000000001'

function snapshot(lastSeq = 3): TableSnapshot {
  return {
    contract: 'novtt.table-snapshot/1', role: 'player', me: { user_id: 'u-ana', name: 'Ana' },
    session: { id: SID, campaign_id: 'c', title: 'S', status: 'active', roteiro_id: null, initiative: {}, revealed_npcs: [], last_seq: lastSeq, revision: 1, started_at: '2026-11-22T00:00:00Z' },
    members: [], characters: [{ id: 'lyra', name: 'Lyra', player_user_id: 'u-ana', controlled_by: 'u-ana', status: 'approved', preset_version_id: 'p', live: { resources: [], conditions: [], revision: 1 } }],
    npcs: [], requests: [], rolls: [], whispers: [], clues: [], suggestions: [], notes: [],
  }
}
const ev = (seq: number, type: string, payload: object): SessionEvent => ({ contract: 'novtt.session-event/1', session_id: SID, seq, type, occurred_at: '2026-11-22T00:00:00Z', payload: payload as Record<string, unknown> })
const request = (over: Partial<RollRequest> = {}): RollRequest => ({
  id: 'r1', session_id: SID, subject: { kind: 'character', id: 'lyra', name: 'Lyra' }, roller_user_id: 'u-ana', label: 'Agilidade + Furtividade',
  components: [], modifier: 0, dice: { faces: 10, count_mode: 'pool', reading: 'successes', threshold: 6, successes_per_die: 1, target: 2, bonus: 0 },
  min_dice: 7, max_dice: 7, allows_choice: false, attempts_allowed: 1, attempts_consumed: 0, secret: false, status: 'OPEN', revision: 0, expires_at: null, ...over,
})
const roll = (over: Partial<OfficialRoll> = {}): OfficialRoll => ({
  id: 'x1', request_id: 'r1', session_id: SID, subject: { kind: 'character', id: 'lyra', name: 'Lyra' }, roller_user_id: 'u-ana', label: 'L', components: [], modifier: 0,
  dice: { faces: 10, count_mode: 'pool', reading: 'successes', threshold: 6, successes_per_die: 1, target: 2, bonus: 0 },
  results: [7, 9, 8, 1, 7, 5, 10], total: 5, successes: 5, succeeded: true, attempt: 1, attempts_allowed: 1, animation_seed: 4123456789, secret: false, revealed: false, created_at: '2026-11-22T00:00:00Z', ...over,
})

describe('TableState (SPEC-MVP-002 LT-03)', () => {
  it('applies consecutive events once and ignores duplicates and pre-snapshot events', () => {
    const onRoll = vi.fn()
    const t = new TableState(snapshot(), vi.fn(), { onRoll })
    t.receive(ev(3, 'roll.requested', request())) // already in the snapshot
    expect(t.s.requests).toHaveLength(0)
    t.receive(ev(4, 'roll.requested', request()))
    t.receive(ev(4, 'roll.requested', request()))
    expect(t.s.requests).toHaveLength(1)
    t.receive(ev(5, 'roll.resolved', roll()))
    t.receive(ev(5, 'roll.resolved', roll()))
    expect(onRoll).toHaveBeenCalledTimes(1)
    expect(t.s.requests).toHaveLength(0) // last attempt used
    expect(t.seq).toBe(5)
  })

  it('a gap triggers a catch-up that applies missed events in order', async () => {
    const fetcher = vi.fn().mockResolvedValue({ data: [ev(4, 'roll.requested', request()), ev(6, 'whisper.received', { id: 'w1', sender_user_id: 'm', recipient_user_id: 'u-ana', kind: 'message', body: 'psiu', created_at: '' })], last_seq: 7, has_more: false })
    const t = new TableState(snapshot(), fetcher)
    t.receive(ev(6, 'whisper.received', {}))
    await t.catchUp()
    expect(fetcher).toHaveBeenCalledWith(3)
    expect(t.s.requests).toHaveLength(1)
    expect(t.s.whispers.map(w => w.body)).toEqual(['psiu'])
    expect(t.seq).toBe(7) // seq 5 and 7 belong to other audiences
  })

  it('own roll response animates once even when the event echo arrives', () => {
    const onRoll = vi.fn()
    const t = new TableState(snapshot(), vi.fn(), { onRoll })
    t.receive(ev(4, 'roll.requested', request()))
    expect(t.local.roll(roll())).toBe(true)
    t.receive(ev(5, 'roll.resolved', roll()))
    expect(onRoll).not.toHaveBeenCalled()
    expect(t.s.rolls).toHaveLength(1)
  })

  it('a reveal animates only for those who had not seen the secret roll', () => {
    const onRoll = vi.fn()
    const t = new TableState(snapshot(), vi.fn(), { onRoll })
    t.receive(ev(4, 'roll.revealed', roll({ id: 'npc1', revealed: true, secret: true })))
    expect(onRoll).toHaveBeenCalledWith(expect.objectContaining({ id: 'npc1' }), 'revealed')
  })

  it('ignores stale live state and unknown event types', () => {
    const t = new TableState(snapshot(), vi.fn())
    t.receive(ev(4, 'live.updated', { subject: { kind: 'character', id: 'lyra' }, live: { resources: [{ id: 'vida', label: 'Vida', current: 3, max: 7 }], conditions: ['Ferida'], revision: 3 } }))
    t.receive(ev(5, 'live.updated', { subject: { kind: 'character', id: 'lyra' }, live: { resources: [], conditions: [], revision: 2 } }))
    t.receive(ev(6, 'future.thing', {}))
    expect(t.s.characters[0].live?.conditions).toEqual(['Ferida'])
    expect(t.seq).toBe(6)
  })
})

describe('official dice (SPEC-MVP-002 D4)', () => {
  it('replays the server faces with the server seed', () => {
    const rec = officialRecord(roll())!
    expect(rec.origin).toBe('official')
    expect(rec.seed).toBe(4123456789)
    expect(rec.dice.map(d => d.result)).toEqual([7, 9, 8, 1, 7, 5, 10])
    expect(rec.dice.every(d => d.type === 'd10')).toBe(true)
    expect(officialRecord(roll({ dice: { ...roll().dice, faces: 3 } }))).toBeNull()
    expect(outcomeText(roll())).toBe('5 sucessos · Sucesso')
  })

  it('a 12-die pool wraps into rows that fit a phone without overlapping', () => {
    const table = overlayTable(390, 844)
    const dice = Array.from({ length: 12 }, (_, i) => ({ shape: 'd10' as const, face: 1 + (i % 10), group: i }))
    const finals = dice.map((_, i) => ({ p: { x: (i - 6) * 0.5, y: 1, z: 0 }, q: { x: 0, y: 0, z: 0, w: 1 } }))
    const { targets } = arrange(dice, finals, table, 2.1)
    const rows = new Set(targets.map(t => t.p.z.toFixed(2)))
    expect(rows.size).toBeGreaterThan(1)
    for (const t of targets) expect(Math.abs(t.p.x)).toBeLessThanOrEqual(table.halfX)
    for (let i = 0; i < targets.length; i++) for (let j = i + 1; j < targets.length; j++) {
      const a = targets[i].p, b = targets[j].p
      expect(Math.hypot(a.x - b.x, a.z - b.z)).toBeGreaterThan(1.6)
    }
  })
})
