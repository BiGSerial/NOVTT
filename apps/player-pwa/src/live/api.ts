import { api, ensureCsrfCookie } from '../api/client'
import type {
  AtlasEntry, Campaign, Clue, Compass, DiceChoice, GameSession, LiveState, MasterNote, Me, OfficialRoll,
  ResourceTrack, RollRequest, Roteiro, RoteiroContent, SessionEvent, SheetUi, Suggestion, TableSnapshot, Whisper,
} from './types'

/** Typed calls to /api/v1 for the live table (SPEC-MVP-002). Mutations ensure the CSRF cookie. */
const key = (): string => crypto.randomUUID()

async function mutate<T>(method: 'POST' | 'PUT' | 'PATCH' | 'DELETE', path: string, body?: unknown, idempotencyKey?: string): Promise<T> {
  await ensureCsrfCookie()
  return api<T>(method, path, body, idempotencyKey ? { idempotencyKey } : {})
}

export const auth = {
  me: () => api<Me>('GET', '/me'),
  login: (email: string, password: string) => mutate<Me>('POST', '/auth/login', { email, password }),
  register: (name: string, email: string, password: string) => mutate<Me>('POST', '/auth/register', { name, email, password }),
  logout: () => mutate<void>('POST', '/auth/logout'),
}

export const demo = {
  state: () => api<{ seeded: boolean; user: { id: string; name: string; role: string } | null; table_campaign_id: string | null }>('GET', '', undefined, { base: '/api/dev/demo' }),
  login: async (role: 'master' | 'player' | 'player2' | 'player3') => {
    await ensureCsrfCookie()
    return api<{ user: { id: string; name: string } }>('POST', '/session', { role }, { base: '/api/dev/demo' })
  },
}

export const campaigns = {
  list: () => api<{ data: Campaign[] }>('GET', '/campaigns?limit=100'),
  current: (campaignId: string) => api<{ session: GameSession | null }>('GET', `/campaigns/${campaignId}/sessions/current`),
  start: (campaignId: string, title: string, roteiroId: string | null) => mutate<GameSession>('POST', `/campaigns/${campaignId}/sessions`, { title, roteiro_id: roteiroId }, key()),
}

export const session = {
  snapshot: (id: string) => api<TableSnapshot>('GET', `/sessions/${id}/snapshot`),
  events: (id: string, after: number) => api<{ data: SessionEvent[]; last_seq: number; has_more: boolean }>('GET', `/sessions/${id}/events?after=${after}&limit=500`),
  transition: (id: string, action: 'pause' | 'resume' | 'end') => mutate<GameSession>('POST', `/sessions/${id}/${action}`),
  compass: (id: string, revision: number, compass: Compass) => mutate<GameSession>('PUT', `/sessions/${id}/compass`, { revision, compass }),
  initiative: (id: string, revision: number, entries: { kind: 'character' | 'npc'; id: string }[], turn: number, round: number) =>
    mutate<GameSession>('PUT', `/sessions/${id}/initiative`, { revision, entries, turn, round }),
}

export interface RequestInput {
  campaign_character_id: string
  paths: string[]
  modifier: number
  dice: DiceChoice
  label?: string
  attempts?: number
  secret?: boolean
  allows_choice?: boolean
  suggestion_id?: string | null
}

export const rolls = {
  request: (sessionId: string, input: RequestInput) => mutate<RollRequest>('POST', `/sessions/${sessionId}/roll-requests`, input),
  /** One idempotency key per user intent: pass the same key when retrying the same tap. */
  roll: (requestId: string, expectedRevision: number, idempotencyKey: string, diceCount?: number) =>
    mutate<OfficialRoll>('POST', `/roll-requests/${requestId}/rolls`, { expected_revision: expectedRevision, ...(diceCount ? { dice_count: diceCount } : {}) }, idempotencyKey),
  npc: (sessionId: string, input: { campaign_npc_id: string; paths: string[]; modifier: number; dice: DiceChoice; label?: string; secret?: boolean }) =>
    mutate<OfficialRoll>('POST', `/sessions/${sessionId}/npc-rolls`, input, key()),
  reveal: (rollId: string) => mutate<OfficialRoll>('POST', `/official-rolls/${rollId}/reveal`),
  cancel: (requestId: string) => mutate<RollRequest>('POST', `/roll-requests/${requestId}/cancel`),
  grant: (requestId: string) => mutate<RollRequest>('POST', `/roll-requests/${requestId}/grant-attempt`),
  suggest: (sessionId: string, characterId: string, paths: string[], note: string) => mutate<Suggestion>('POST', `/sessions/${sessionId}/suggestions`, { campaign_character_id: characterId, paths, note: note || null }),
  dismiss: (suggestionId: string) => mutate<void>('POST', `/suggestions/${suggestionId}/dismiss`),
}

export const table = {
  live: (campaignId: string, kind: 'character' | 'npc', id: string, revision: number, resources: ResourceTrack[], conditions: string[]) =>
    mutate<{ live: LiveState }>('PUT', `/campaigns/${campaignId}/live-states/${kind}/${id}`, { revision, resources, conditions }),
  createNote: (campaignId: string, body: string, tags: string[], pinned = false) => mutate<MasterNote>('POST', `/campaigns/${campaignId}/notes`, { body, tags, pinned }),
  updateNote: (id: string, revision: number, patch: { body?: string; tags?: string[]; pinned?: boolean }) => mutate<MasterNote>('PATCH', `/notes/${id}`, { revision, ...patch }),
  deleteNote: (id: string) => mutate<void>('DELETE', `/notes/${id}`),
  whisper: (campaignId: string, recipient: string, body: string) => mutate<Whisper>('POST', `/campaigns/${campaignId}/whispers`, { recipient_user_id: recipient, body }),
  createClue: (sessionId: string, title: string, body: string, scene: string | null) => mutate<Clue>('POST', `/sessions/${sessionId}/clues`, { title, body, scene }),
  revealClue: (clueId: string, to: 'table' | 'user', userId?: string) => mutate<Clue>('POST', `/clues/${clueId}/reveal`, { to, user_id: userId ?? null }),
  revealNpc: (sessionId: string, npcId: string, summary: string) => mutate<{ revealed_npcs: unknown[] }>('POST', `/sessions/${sessionId}/npc-reveals`, { campaign_npc_id: npcId, summary }),
}

export const sheets = {
  character: (id: string) => api<{ id: string; name: string; sheet: Record<string, unknown>; preset_version_id: string }>('GET', `/campaign-characters/${id}`),
  npcs: (campaignId: string) => api<{ data: { id: string; name: string; sheet: Record<string, unknown> }[] }>('GET', `/campaigns/${campaignId}/npcs?limit=100`),
  /** Preset layouts are immutable per version: cache them for the page lifetime. */
  layout: (() => {
    const cache = new Map<string, Promise<SheetUi>>()
    return (presetVersionId: string): Promise<SheetUi> => {
      if (!cache.has(presetVersionId)) {
        const p = api<{ sheet_ui: SheetUi }>('GET', `/preset-versions/${presetVersionId}`).then(v => v.sheet_ui)
        p.catch(() => cache.delete(presetVersionId))
        cache.set(presetVersionId, p)
      }
      return cache.get(presetVersionId)!
    }
  })(),
}

export const world = {
  roteiros: (campaignId: string) => api<{ data: Roteiro[] }>('GET', `/campaigns/${campaignId}/roteiros`),
  createRoteiro: (campaignId: string, title: string, content: Partial<RoteiroContent>) => mutate<Roteiro>('POST', `/campaigns/${campaignId}/roteiros`, { title, content }),
  saveRoteiro: (id: string, revision: number, title: string, content: Partial<RoteiroContent>) => mutate<Roteiro>('PUT', `/roteiros/${id}`, { revision, title, content }),
  atlas: (campaignId: string) => api<{ data: AtlasEntry[] }>('GET', `/campaigns/${campaignId}/atlas`),
  createEntry: (campaignId: string, entry: Partial<AtlasEntry>) => mutate<AtlasEntry>('POST', `/campaigns/${campaignId}/atlas`, entry),
  updateEntry: (id: string, revision: number, patch: Partial<AtlasEntry>) => mutate<AtlasEntry>('PATCH', `/atlas/${id}`, { revision, ...patch }),
  addTimeline: (id: string, note: string) => mutate<{ id: string; note: string; session_id: string | null; created_at: string }>('POST', `/atlas/${id}/timeline`, { note }),
}

export const devices = {
  start: (label: string) => mutate<{ pairing_id: string; code: string; claim_secret: string; expires_at: string }>('POST', '/pairing', { device_label: label }),
  claim: (pairingId: string, secret: string) => mutate<{ status: 'pending' | 'claimed'; me?: Me }>('POST', `/pairing/${pairingId}/claim`, { claim_secret: secret }),
  confirm: (code: string) => mutate<{ device_label: string }>('POST', '/pairing/confirm', { code }),
  list: () => api<{ data: { id: string; label: string; last_seen_at: string | null }[] }>('GET', '/devices'),
  revoke: (id: string) => mutate<void>('DELETE', `/devices/${id}`),
}
