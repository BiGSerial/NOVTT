import { reactive } from 'vue'
import type {
  Clue, Compass, GameSession, Initiative, LiveState, MasterNote, OfficialRoll, RevealedNpc, RollRequest,
  SessionEvent, Suggestion, TableSnapshot, Whisper,
} from './types'

/**
 * Client view of one live session (SPEC-MVP-002 LT-03). Starts from a snapshot, then applies
 * session events. Every apply is idempotent (upsert by id), events are deduplicated by seq, and a
 * jump in seq triggers a catch-up fetch, so missing or repeated deliveries never corrupt the view.
 */
export interface TableHooks {
  /** A roll result this participant should see animated (new result or a reveal). */
  onRoll?: (roll: OfficialRoll, reason: 'resolved' | 'revealed') => void
  onWhisper?: (w: Whisper) => void
  onClue?: (c: Clue) => void
  onRequest?: (r: RollRequest) => void
}

export type Fetcher = (after: number) => Promise<{ data: SessionEvent[]; last_seq: number; has_more: boolean }>

export class TableState {
  readonly s: TableSnapshot
  private lastSeq: number
  private readonly seen = new Set<number>()
  private catchingUp: Promise<void> | null = null
  private catchUpAgain = false

  constructor(snapshot: TableSnapshot, private readonly fetchAfter: Fetcher, private readonly hooks: TableHooks = {}) {
    this.s = reactive(structuredClone(snapshot)) as TableSnapshot
    this.lastSeq = snapshot.session.last_seq
    // Events up to the snapshot's seq are already reflected in it.
    this.baseline = snapshot.session.last_seq
  }

  get seq(): number { return this.lastSeq }
  get isMaster(): boolean { return this.s.role === 'master' }

  /** Realtime delivery. Out-of-order or gapped deliveries trigger a catch-up instead. */
  receive(e: SessionEvent): void {
    if (e.session_id !== this.s.session.id || this.seen.has(e.seq) || e.seq <= this.baseline) return
    if (e.seq === this.lastSeq + 1) {
      this.apply(e)
      return
    }
    // Gaps are normal (events for other audiences) but could also be a lost delivery: ask.
    void this.catchUp()
  }

  /** Fetches everything after the last applied seq (reconnects, gaps, focus). */
  catchUp(): Promise<void> {
    if (this.catchingUp) {
      this.catchUpAgain = true
      return this.catchingUp
    }
    this.catchingUp = (async () => {
      try {
        do {
          this.catchUpAgain = false
          let more = true
          while (more) {
            const page = await this.fetchAfter(this.lastSeq)
            for (const e of page.data) if (!this.seen.has(e.seq) && e.seq > this.lastSeq) this.apply(e)
            // Nothing visible to us between lastSeq and last_seq: we are current.
            if (!page.has_more) this.lastSeq = Math.max(this.lastSeq, page.last_seq)
            more = page.has_more && page.data.length > 0
          }
        } while (this.catchUpAgain)
      } finally {
        this.catchingUp = null
      }
    })()
    return this.catchingUp
  }

  private baseline = 0

  private apply(e: SessionEvent): void {
    this.seen.add(e.seq)
    this.lastSeq = Math.max(this.lastSeq, e.seq)
    const p = e.payload as never
    const s = this.s
    switch (e.type) {
      case 'session.status': {
        const { status, title } = p as { status: GameSession['status']; title: string }
        s.session.status = status
        s.session.title = title
        break
      }
      case 'roll.requested':
      case 'roll.request_updated': {
        const r = p as RollRequest
        upsert(s.requests, r)
        if (r.status !== 'OPEN') remove(s.requests, r.id)
        else this.hooks.onRequest?.(r)
        break
      }
      case 'roll.resolved':
      case 'roll.revealed': {
        const roll = p as OfficialRoll
        // A reveal animates only for those who had not seen the secret roll.
        const isNew = !s.rolls.some(r => r.id === roll.id)
        upsert(s.rolls, roll)
        if (s.rolls.length > 80) s.rolls.splice(0, s.rolls.length - 80)
        const req = s.requests.find(r => r.id === roll.request_id)
        if (req && e.type === 'roll.resolved') {
          req.attempts_consumed = Math.max(req.attempts_consumed, roll.attempt)
          req.revision += 1
          if (req.attempts_consumed >= req.attempts_allowed) remove(s.requests, req.id)
        }
        if (isNew) this.hooks.onRoll?.(roll, e.type === 'roll.resolved' ? 'resolved' : 'revealed')
        break
      }
      case 'suggestion.created':
        upsert(s.suggestions, p as Suggestion)
        break
      case 'suggestion.resolved':
        remove(s.suggestions, (p as { id: string }).id)
        break
      case 'live.updated': {
        const { subject, live } = p as { subject: { kind: 'character' | 'npc'; id: string }; live: LiveState }
        const list = subject.kind === 'character' ? s.characters : s.npcs
        const target = list.find(c => c.id === subject.id)
        if (target && (!target.live || target.live.revision <= live.revision)) target.live = live
        break
      }
      case 'compass.updated': {
        const { revision, compass } = p as { revision: number; compass: Compass }
        if (revision >= s.session.revision) {
          s.session.compass = compass
          s.session.revision = revision
        }
        break
      }
      case 'initiative.updated': {
        const { revision, initiative } = p as { revision: number; initiative: Initiative }
        if (revision >= s.session.revision) {
          s.session.initiative = initiative
          s.session.revision = revision
        }
        break
      }
      case 'note.saved': {
        const n = p as MasterNote
        const old = s.notes.find(x => x.id === n.id)
        if (!old || old.revision <= n.revision) upsert(s.notes, n, true)
        break
      }
      case 'note.deleted':
        remove(s.notes, (p as { id: string }).id)
        break
      case 'whisper.received': {
        const w = p as Whisper
        if (!s.whispers.some(x => x.id === w.id)) {
          s.whispers.push(w)
          this.hooks.onWhisper?.(w)
        }
        break
      }
      case 'clue.saved':
      case 'clue.revealed': {
        const c = p as Clue
        const known = s.clues.find(x => x.id === c.id)
        upsert(s.clues, { ...known, ...c })
        if (e.type === 'clue.revealed') this.hooks.onClue?.(c)
        break
      }
      case 'npc.revealed': {
        const n = p as RevealedNpc
        s.session.revealed_npcs = [...s.session.revealed_npcs.filter(x => x.id !== n.id), n]
        break
      }
      default:
        // Unknown types from a newer server are ignored (forward compatible).
        break
    }
  }

  /** Applies a response the client already has (e.g. its own command) without waiting for the echo. */
  local = {
    /** Own roll response: recorded before the event echo, so it animates exactly once. */
    roll: (roll: OfficialRoll): boolean => {
      if (this.s.rolls.some(r => r.id === roll.id)) return false
      upsert(this.s.rolls, roll)
      const req = this.s.requests.find(r => r.id === roll.request_id)
      if (req) {
        req.attempts_consumed = Math.max(req.attempts_consumed, roll.attempt)
        req.revision += 1
        if (req.attempts_consumed >= req.attempts_allowed) remove(this.s.requests, req.id)
      }
      return true
    },
    request: (r: RollRequest) => { if (r.status === 'OPEN') upsert(this.s.requests, r); else remove(this.s.requests, r.id) },
    note: (n: MasterNote) => upsert(this.s.notes, n, true),
    dropNote: (id: string) => remove(this.s.notes, id),
    whisper: (w: Whisper) => { if (!this.s.whispers.some(x => x.id === w.id)) this.s.whispers.push(w) },
    clue: (c: Clue) => upsert(this.s.clues, c),
    session: (g: GameSession) => {
      Object.assign(this.s.session, { ...g, last_seq: this.s.session.last_seq })
    },
    live: (kind: 'character' | 'npc', id: string, live: LiveState) => {
      const t = (kind === 'character' ? this.s.characters : this.s.npcs).find(c => c.id === id)
      if (t && (!t.live || t.live.revision <= live.revision)) t.live = live
    },
  }
}

function upsert<T extends { id: string }>(list: T[], item: T, front = false): void {
  const i = list.findIndex(x => x.id === item.id)
  if (i >= 0) list.splice(i, 1, item)
  else if (front) list.unshift(item)
  else list.push(item)
}

function remove<T extends { id: string }>(list: T[], id: string): void {
  const i = list.findIndex(x => x.id === id)
  if (i >= 0) list.splice(i, 1)
}
