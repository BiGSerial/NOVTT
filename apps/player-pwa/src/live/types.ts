/** Client mirrors of contracts/http/openapi.v1.yaml 1.1 and contracts/events/session-event.v1. */

export type Role = 'master' | 'player'
export type Reading = 'sum' | 'highest' | 'successes'
export type CountMode = 'pool' | 'fixed'

export interface Me { id: string; name: string; paired_device_id: string | null }

export interface Campaign { id: string; name: string; role?: Role; preset_version_id: string; status: string; description?: string | null }

export interface ResourceTrack { id: string; label: string; current: number; max: number }
export interface LiveState { resources: ResourceTrack[]; conditions: string[]; revision: number }

export interface Clock { id: string; label: string; segments: number; filled: number }
export interface Compass { scene: string; objective: string; next_clue: string; clocks: Clock[]; spotlight: Record<string, number> }
export interface InitiativeEntry { kind: 'character' | 'npc'; id: string; name: string }
export interface Initiative { entries?: InitiativeEntry[]; turn?: number; round?: number }
export interface RevealedNpc { id: string; name: string; summary: string }

export interface GameSession {
  id: string
  campaign_id: string
  title: string
  status: 'active' | 'paused' | 'ended'
  roteiro_id: string | null
  initiative: Initiative
  revealed_npcs: RevealedNpc[]
  compass?: Compass
  last_seq: number
  revision: number
  started_at: string
}

export interface Member { user_id: string; name: string; role: Role }
export interface TableCharacter { id: string; name: string; player_user_id: string; controlled_by: string; status: string; preset_version_id: string; live: LiveState | null }
export interface TableNpc { id: string; name: string; preset_version_id: string; live: LiveState | null }

export interface RollComponent { path: string; label: string; value?: number }
export interface DiceSpec { faces: number; count_mode: CountMode; reading: Reading; threshold: number; successes_per_die: number; target: number | null; bonus: number | null }
export interface RollSubject { kind: 'character' | 'npc'; id: string; name: string }

export interface RollRequest {
  id: string
  session_id: string
  subject: RollSubject
  roller_user_id: string
  label: string
  components: RollComponent[]
  modifier: number
  dice: DiceSpec
  min_dice: number
  max_dice: number
  allows_choice: boolean
  attempts_allowed: number
  attempts_consumed: number
  secret: boolean
  status: 'OPEN' | 'EXHAUSTED' | 'CANCELLED' | 'EXPIRED' | 'CLOSED'
  revision: number
  expires_at: string | null
}

export interface OfficialRoll {
  id: string
  request_id: string
  session_id: string
  subject: RollSubject
  roller_user_id: string
  label: string
  components: RollComponent[]
  modifier: number | null
  dice: DiceSpec
  results: number[]
  total: number
  successes: number
  succeeded: boolean | null
  attempt: number
  attempts_allowed: number
  animation_seed: number
  secret: boolean
  revealed: boolean
  created_at: string
}

export interface Whisper { id: string; sender_user_id: string; recipient_user_id: string; kind: 'message' | 'clue'; body: string; created_at: string }
export interface Clue { id: string; scene: string | null; title: string; body: string; revealed_to: 'table' | 'user' | null; revealed_user_id: string | null; revealed_at: string | null }
export interface Suggestion { id: string; campaign_character_id: string; character_name: string; player_user_id: string; paths: string[]; note: string | null; status: 'pending' | 'accepted' | 'dismissed' }
export interface MasterNote { id: string; session_id: string | null; body: string; tags: string[]; pinned: boolean; revision: number; updated_at: string | null }

export interface TableSnapshot {
  contract: 'novtt.table-snapshot/1'
  role: Role
  me: { user_id: string; name: string }
  session: GameSession
  members: Member[]
  characters: TableCharacter[]
  npcs: TableNpc[]
  requests: RollRequest[]
  rolls: OfficialRoll[]
  whispers: Whisper[]
  clues: Clue[]
  suggestions: Suggestion[]
  notes: MasterNote[]
}

export interface SessionEvent {
  contract: 'novtt.session-event/1'
  session_id: string
  seq: number
  type: string
  occurred_at: string
  payload: Record<string, unknown>
}

/** Sheet fields as the preset lays them out (sheet.ui.json). */
export interface SheetUiField { kind: 'field'; path: string; label: string; widget: string; access?: string }
export interface SheetUiSection { id: string; label: string; access?: string; elements: Array<SheetUiField | { kind: string; path: string; label: string }> }
export interface SheetUi { sections: SheetUiSection[] }

export interface RoteiroContent {
  strong_start: string
  scenes: { title: string; goal: string; clues: { title: string; body: string }[] }[]
  npcs: { name: string; voice: string; wants: string; secret: string }[]
  clocks: { label: string; segments: number }[]
  wants: { character: string; text: string }[]
}
export interface Roteiro { id: string; campaign_id: string; title: string; content: Partial<RoteiroContent>; revision: number; updated_at: string | null }

export type AtlasKind = 'place' | 'person' | 'faction' | 'secret' | 'thread'
export interface AtlasEntry {
  id: string
  kind: AtlasKind
  title: string
  summary: string
  thread_status: 'open' | 'advancing' | 'resolved' | null
  details?: string
  known_to_players?: boolean
  links?: string[]
  revision?: number
  timeline?: { id: string; session_id: string | null; note: string; created_at: string }[]
}

export interface DiceChoice { faces: number; count_mode: CountMode; count?: number; reading: Reading; threshold?: number | null; target?: number | null }
