import { api, ensureCsrfCookie } from '../api/client'
import type { SheetUi } from '../sheets/types'

/**
 * Campaign set-up and master review calls (SPEC-SPRINT-001, SPEC-PRESET-ENGINE-001). The server
 * is authoritative for every decision: these types only drive presentation.
 */

export interface PresetSummary {
  id: string
  family_id: string
  variant_id: string
  version: string
  display_name: string
  status: string
  canonical: boolean
  rules_revision: string | null
  source_version: string | null
  locale: string | null
  license: { identifier: string | null; attribution: string | null; source_url: string | null }
  rights_status: string | null
  source_authenticity: string | null
  coverage: string | null
  limitations: string[]
  taxonomy: { kind: string; labels: Record<string, string>; parent_kind: string | null }[]
}

export interface Budget { id: string; kind: string; label: string }
export interface PresetVersionFull extends PresetSummary {
  sheet_ui: SheetUi
  sheet_schema: Record<string, unknown>
  rules: {
    contract: string
    budgets?: Budget[]
    creation?: {
      steps?: { id: string; label: string }[]
      choices?: { id: string; path: string; label?: string; options: { value: string | number | boolean; label?: string; compendium_id?: string }[] }[]
    }
  }
  compendium?: { canonical_id: string; labels: Record<string, string> }[]
}

export interface CreationStep { id: string; available: boolean; complete: boolean; missing: string[]; condition_met: boolean; issues: number }
export interface CampaignCharacterView {
  id: string
  campaign_id: string
  name: string
  status: 'draft' | 'pending_approval' | 'approved' | 'archived'
  sheet_revision: number
  player_user_id: string
  preset_version_id: string
  portrait_image_id?: string | null
  sheet: Record<string, unknown>
  creation?: { complete: boolean; steps: CreationStep[]; issues: { rule_id: string; paths: string[] }[] }
}

export interface OperationView {
  id: string
  type: string
  path: string
  section_id: string
  value?: unknown
  item_id?: string | null
  field?: string | null
  decision: { decision: 'approved' | 'rejected'; reason: string | null; final: boolean } | null
}
export interface ChangeRequestView {
  id: string
  campaign_character_id: string
  status: string
  review_version: number
  resulting_sheet_revision?: number | null
  sections: { section_id: string; label: string; operations: OperationView[] }[]
  validation?: { valid: boolean; issues: { rule_id: string; operation_ids: string[]; paths: string[]; params?: Record<string, unknown> }[] }
}
export type Decision =
  | { operation_ids: string[]; decision: 'approved'; override?: true }
  | { operation_ids: [string]; decision: 'rejected'; reason: string }

export interface Invitation { id: string; token?: string; expires_at: string; max_uses: number; uses: number }
export interface Grant { id: string; budget_id: string; amount: number; reason: string | null; created_at: string }

export const ACTIVE_REQUEST = ['submitted', 'in_review', 'awaiting_resolution']

async function mutate<T>(method: 'POST' | 'PUT', path: string, body?: unknown, idempotencyKey?: string): Promise<T> {
  await ensureCsrfCookie()
  return api<T>(method, path, body, idempotencyKey ? { idempotencyKey } : {})
}

/** Immutable per version: cached for the page lifetime. */
const versionCache = new Map<string, Promise<PresetVersionFull>>()

export const presets = {
  list: (scope: 'all' | 'canonical' | 'personal' = 'all') => api<{ data: PresetSummary[] }>('GET', `/presets?scope=${scope}&limit=100`),
  version: (id: string): Promise<PresetVersionFull> => {
    if (!versionCache.has(id)) {
      const p = api<PresetVersionFull>('GET', `/preset-versions/${id}`)
      p.catch(() => versionCache.delete(id))
      versionCache.set(id, p)
    }
    return versionCache.get(id)!
  },
}

export const setup = {
  create: (name: string, description: string | null, presetVersionId: string, key: string) =>
    mutate<{ id: string }>('POST', '/campaigns', { name, description, preset_version_id: presetVersionId }, key),
  invite: (campaignId: string) => mutate<Invitation>('POST', `/campaigns/${campaignId}/invitations`, { expires_in_hours: 72, max_uses: 10 }),
  accept: (token: string) => mutate<{ id: string; role: string }>('POST', '/invitations/accept', { token }),
  characters: (campaignId: string) => api<{ data: CampaignCharacterView[] }>('GET', `/campaigns/${campaignId}/characters`),
  createCharacter: (campaignId: string, name: string, key: string, fromCharacterId?: string) =>
    mutate<CampaignCharacterView>('POST', `/campaigns/${campaignId}/characters`, { name, ...(fromCharacterId ? { from_campaign_character_id: fromCharacterId } : {}) }, key),
  presetUpgrades: (campaignId: string) => api<{ current: { id: string; version: string }; data: { id: string; version: string }[] }>('GET', `/campaigns/${campaignId}/preset-upgrades`),
  upgradePreset: (campaignId: string, versionId: string, expectedRevision: number) =>
    mutate<{ id: string; preset_version_id: string; revision: number }>('POST', `/campaigns/${campaignId}/preset-upgrade`, { preset_version_id: versionId, expected_revision: expectedRevision }),
  adaptation: (characterId: string) => api<AdaptationReport>('GET', `/campaign-characters/${characterId}/adaptation`),
  adapt: (characterId: string, expectedRevision: number) =>
    mutate<{ report: AdaptationReport; character: CampaignCharacterView }>('POST', `/campaign-characters/${characterId}/adaptation`, { expected_sheet_revision: expectedRevision }),
}

export interface AdaptationReport {
  needed: boolean; from_version: string; to_version: string; kept: number
  dropped: { path: string; reason: 'field_removed' | 'option_removed' | 'out_of_range' | 'type' | 'length' | 'pattern' | 'incomplete' | 'too_many_items' }[]
  draft_operations_dropped: number; needs_approval: boolean; proposals_withdrawn?: number
}
export const DROP_REASON: Record<AdaptationReport['dropped'][number]['reason'], string> = {
  field_removed: 'o campo não existe mais', option_removed: 'a opção não existe mais', out_of_range: 'fora dos novos limites',
  type: 'tipo mudou', length: 'tamanho fora do novo limite', pattern: 'formato mudou', incomplete: 'item incompleto', too_many_items: 'itens demais',
}

export const review = {
  requests: (characterId: string) => api<{ data: ChangeRequestView[] }>('GET', `/campaign-characters/${characterId}/change-requests`),
  request: (id: string) => api<ChangeRequestView>('GET', `/change-requests/${id}`),
  decide: (id: string, reviewVersion: number, decisions: Decision[]) => mutate<ChangeRequestView>('PUT', `/change-requests/${id}/decisions`, { review_version: reviewVersion, decisions }),
  finalize: (id: string, reviewVersion: number, key: string) => mutate<ChangeRequestView>('POST', `/change-requests/${id}/finalize`, { review_version: reviewVersion }, key),
  approve: (characterId: string) => mutate<CampaignCharacterView>('POST', `/campaign-characters/${characterId}/approve`),
  grants: (characterId: string) => api<{ data: Grant[]; balances: Record<string, number> }>('GET', `/campaign-characters/${characterId}/grants`),
  grant: (characterId: string, budgetId: string, amount: number, reason: string, key: string) =>
    mutate<Grant>('POST', `/campaign-characters/${characterId}/grants`, { budget_id: budgetId, amount, reason: reason || null }, key),
}

export const REASON_MIN = 10
export const REASON_MAX = 1000

export function reasonError(reason: string): string | null {
  const length = reason.trim().length
  if (length < REASON_MIN) return `O motivo precisa de pelo menos ${REASON_MIN} caracteres.`
  if (length > REASON_MAX) return `O motivo pode ter no máximo ${REASON_MAX} caracteres.`
  return null
}

export function allDecided(view: ChangeRequestView): boolean {
  return view.sections.every(s => s.operations.every(o => o.decision !== null))
}

export const STATUS_LABEL: Record<CampaignCharacterView['status'], string> = {
  draft: 'Rascunho',
  pending_approval: 'Aguardando o mestre',
  approved: 'Aprovado',
  archived: 'Arquivado',
}

export const COVERAGE_LABEL: Record<string, string> = {
  schema_only: 'Só a ficha',
  character_creation: 'Criação de personagem',
  progression: 'Criação e evolução',
  playable_core: 'Núcleo jogável',
  full_licensed_scope: 'Escopo licenciado completo',
}

/** Field labels of a sheet layout by JSON Pointer (collections and their item fields included). */
export function labelsOf(ui: SheetUi): Map<string, string> {
  const labels = new Map<string, string>()
  for (const section of ui.sections) {
    for (const element of section.elements) {
      labels.set(element.path, element.label)
      if (element.kind === 'collection') for (const f of element.item_fields) labels.set(`${element.path}#${f.field}`, f.label)
    }
  }
  return labels
}

/** Display label per choice value (`path` => value => label), from the rules or the compendium. */
export function optionLabelsOf(preset: Pick<PresetVersionFull, 'rules' | 'compendium' | 'locale'>): Record<string, Record<string, string>> {
  const entries = new Map((preset.compendium ?? []).map(e => [e.canonical_id, e.labels[preset.locale ?? 'pt-BR'] ?? Object.values(e.labels)[0]]))
  const out: Record<string, Record<string, string>> = {}
  for (const choice of preset.rules.creation?.choices ?? []) {
    out[choice.path] = Object.fromEntries(choice.options.map(o => [String(o.value), o.label ?? (o.compendium_id ? entries.get(o.compendium_id) : undefined) ?? String(o.value)]))
  }
  return out
}

/** Short pt-BR label of a taxonomy for one preset ("Linhagem, Dom"). */
export function taxonomyLabel(p: Pick<PresetSummary, 'taxonomy' | 'locale'>): string {
  return p.taxonomy.map(t => t.labels[p.locale ?? 'pt-BR'] ?? Object.values(t.labels)[0] ?? t.kind).join(', ')
}
