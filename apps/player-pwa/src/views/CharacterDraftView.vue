<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import { api, ApiError } from '../api/client'
import ChoiceGroup, { type ChoiceCard } from '../components/builder/ChoiceGroup.vue'
import PortraitPicker from '../components/builder/PortraitPicker.vue'
import SheetSummary, { type SummaryGroup } from '../components/builder/SheetSummary.vue'
import StatRow from '../components/builder/StatRow.vue'
import SheetEditor from '../components/SheetEditor.vue'
import { t } from '../i18n/messages'
import { matchesTarget, overlaps, type BuilderRules, type CreationProgress, type SheetView, type TraceEntry } from '../sheets/builder'
import { DraftModel } from '../sheets/draft'
import { describeRequirement, referencedPaths } from '../sheets/requirements'
import type { ChangeSet, FieldElement, Scalar, Sheet, SheetUi, ValidationIssue, ValidationResult } from '../sheets/types'
import { applyTheme, loadTheme } from '../live/theme'
import '../styles/builder.css'

const props = defineProps<{ characterId: string; fetcher?: typeof fetch }>()

interface Character extends Partial<SheetView> { id: string; campaign_id?: string; portrait_image_id?: string | null; name: string; status: string; sheet_revision: number; preset_version_id: string; sheet: Sheet }
interface CompendiumEntry { canonical_id: string; kind: string; labels: Record<string, string>; summary: string | null }
interface PresetVersion {
  display_name?: string
  sheet_ui: SheetUi
  sheet_schema: Record<string, unknown>
  rules?: BuilderRules
  compendium?: CompendiumEntry[]
}
interface Draft { draft_version: number; base_sheet_revision: number; change_set: ChangeSet; validation: ValidationResult; balances?: Record<string, number>; preview?: SheetView | null }
interface ChangeRequestSummary { id: string; status: string }
type SchemaNode = { type?: string; minimum?: number; maximum?: number; default?: unknown; enum?: unknown[]; properties?: Record<string, SchemaNode> }

const character = ref<Character | null>(null)
const preset = shallowRef<PresetVersion | null>(null)
const draft = shallowRef<DraftModel | null>(null)
const draftVersion = ref(0)
const issues = ref<ValidationIssue[]>([])
/** Remaining points per budget as the server computed them for the current draft. */
const balances = ref<Record<string, number>>({})
/** The draft applied to the official sheet, as the server projects it for this player. */
const preview = shallowRef<SheetView | null>(null)
const pending = ref<ChangeRequestSummary | null>(null)
const status = ref('')
const failure = ref('')
const busy = ref(false)
const size = ref(0)
const stepIndex = ref(0)
const tick = ref(0)
const AUTOSAVE_MS = 600
let autosave: ReturnType<typeof setTimeout> | undefined
let saving: Promise<boolean> | null = null
let again = false

const rules = computed<BuilderRules>(() => preset.value?.rules ?? {})
const ui = computed(() => preset.value?.sheet_ui ?? null)
const budgetLabels = computed(() => Object.fromEntries((rules.value.budgets ?? []).map(b => [b.id, b.label])))
const compendium = computed(() => new Map((preset.value?.compendium ?? []).map(e => [e.canonical_id, e])))
const view = computed<SheetView | null>(() => preview.value ?? (character.value ? { sheet: character.value.sheet, decision_trace: character.value.decision_trace ?? [], creation: character.value.creation, choices: character.value.choices } : null))

// ---- Reading the preset -------------------------------------------------------------------------

function node(path: string): SchemaNode {
  let current = (preset.value?.sheet_schema ?? {}) as SchemaNode
  for (const segment of path.split('/').slice(1)) current = current.properties?.[segment.replace(/~1/g, '/').replace(/~0/g, '~')] ?? {}
  return current
}
const fieldLabels = computed(() => {
  const map = new Map<string, string>()
  for (const s of ui.value?.sections ?? []) for (const e of s.elements) map.set(e.path, e.label)
  return map
})
const choices = computed(() => rules.value.creation?.choices ?? [])
// Fields held by the chosen options (server-computed). Exact holds lock the control; "at least"
// holds raise its minimum. path → { reason "Clã: X (label)", value, mode }.
const holds = computed(() => {
  const out = new Map<string, { reason: string; value: Scalar; mode: 'exact' | 'at_least' }>()
  for (const c of view.value?.choices ?? []) {
    const decl = choices.value.find(x => x.id === c.id)
    const option = decl?.options.find(o => o.value === c.value)
    for (const f of c.fixes ?? []) out.set(f.path, { reason: `${decl?.label ?? c.id}: ${option?.label ?? String(c.value)}${f.label ? ` (${f.label})` : ''}`, value: f.value, mode: f.mode ?? 'exact' })
  }
  return out
})
const lockedBy = (path: string) => (holds.value.get(path)?.mode === 'exact' ? holds.value.get(path)!.reason : null)
const heldMin = (path: string, min: number) => { const h = holds.value.get(path); return h?.mode === 'at_least' && typeof h.value === 'number' ? Math.max(min, h.value) : min }
const choicePaths = computed(() => new Set(choices.value.map(c => c.path)))
const derivedPaths = computed(() => new Set((rules.value.derived ?? []).map(d => d.path)))
const optionEntry = (id?: string) => (id ? compendium.value.get(id) : undefined)
const optionLabels = computed(() => Object.fromEntries(choices.value.map(c => [c.path, Object.fromEntries(c.options.map(o => [String(o.value), o.label ?? optionEntry(o.compendium_id)?.labels['pt-BR'] ?? String(o.value)]))])))
const labelSource = {
  field: (path: string) => fieldLabels.value.get(path),
  option: (path: string, value: unknown) => optionLabels.value[path]?.[String(value)],
}

/** The draft's value, or the preset default (attributes that start at 1 show 1, not a dash). */
function valueAt(path: string): unknown {
  void tick.value
  const v = draft.value?.value(path)
  if (v !== undefined && v !== null && v !== '') return v
  const n = node(path)
  return n.default ?? (n.type === 'integer' || n.type === 'number' ? n.minimum ?? 0 : v)
}
const derivedValue = (path: string): unknown => readPath(view.value?.sheet, path)

function readPath(doc: unknown, pointer: string): unknown {
  let cur = doc
  for (const raw of pointer.split('/').slice(1)) {
    const key = raw.replace(/~1/g, '/').replace(/~0/g, '~')
    if (cur === null || typeof cur !== 'object' || !(key in (cur as object))) return undefined
    cur = (cur as Record<string, unknown>)[key]
  }
  return cur
}

/** Derived values that read exactly one field (a modifier of an attribute) show next to it. */
const linkedDerived = computed(() => {
  const map = new Map<string, { id: string; label: string; path: string }[]>()
  for (const d of rules.value.derived ?? []) {
    const refs = referencedPaths(d.formula)
    if (refs.size !== 1) continue
    const [source] = [...refs]
    map.set(source, [...(map.get(source) ?? []), { id: d.id, label: shortLabel(d.label ?? d.id, fieldLabels.value.get(source)), path: d.path }])
  }
  return map
})
/** "Modificador de Força" next to "Força" reads better as "mod". */
function shortLabel(label: string, source?: string): string {
  return source && label.toLowerCase().endsWith(source.toLowerCase()) ? label.slice(0, -source.length).replace(/\s+(de|do|da)\s*$/i, '').trim().toLowerCase() || label : label
}

/** Budgets that creation spends, and which fields spend them. */
const creationCosts = computed(() => (rules.value.costs ?? []).filter(c => c.phase === 'creation'))
const creationBudgets = computed(() => {
  const used = new Set(creationCosts.value.map(c => c.budget))
  return (rules.value.budgets ?? []).filter(b => used.has(b.id) && b.id in balances.value).map(b => ({ id: b.id, label: b.label, left: balances.value[b.id] }))
})
function canRaise(path: string): boolean {
  const budgets = creationCosts.value.filter(c => c.targets.some(target => matchesTarget(target, path))).map(c => c.budget)
  return budgets.length === 0 || budgets.some(b => (balances.value[b] ?? 0) > 0)
}

// ---- Guided creation -----------------------------------------------------------------------------

const steps = computed(() => rules.value.creation?.steps ?? [])
const progress = computed<CreationProgress | undefined>(() => view.value?.creation)
const guided = computed(() => steps.value.length > 0 && character.value?.status !== 'approved' && character.value?.status !== 'archived')
const step = computed(() => steps.value[stepIndex.value])
const isReview = computed(() => guided.value && stepIndex.value === steps.value.length - 1)
const stepState = (id: string) => progress.value?.steps.find(s => s.id === id)
/** The last step is the review: it is "done" only when the whole creation is complete. */
const stepDone = (i: number) => (i === steps.value.length - 1 ? !!progress.value?.complete : !!stepState(steps.value[i].id)?.complete)

function inStep(path: string): boolean {
  const s = step.value
  return !!s && [...(s.paths ?? []), ...(s.required ?? [])].some(target => matchesTarget(target, path))
}
const isNumeric = (e: FieldElement) => ['integer', 'number', 'dot_rating'].includes(e.widget) && ['integer', 'number'].includes(node(e.path).type ?? '')

const stepElements = computed(() => (ui.value?.sections ?? []).flatMap(s => s.elements.filter(e => e.kind === 'field' && inStep(e.path) && !choicePaths.value.has(e.path) && !derivedPaths.value.has(e.path)) as FieldElement[]))
const statFields = computed(() => stepElements.value.filter(isNumeric))
/** Text, toggles and lists of the step keep the regular form controls. */
const formUi = computed<SheetUi | null>(() => {
  if (!ui.value || !guided.value || isReview.value) return null
  const sections = ui.value.sections
    .map(section => ({ ...section, elements: section.elements.filter(e => inStep(e.path) && !choicePaths.value.has(e.path) && !derivedPaths.value.has(e.path) && !(e.kind === 'field' && isNumeric(e as FieldElement))) }))
    .filter(section => section.elements.length > 0)
  return sections.length ? { ...ui.value, sections } : null
})

const availability = computed(() => new Map((view.value?.choices ?? []).map(c => [c.id, c])))
const stepChoices = computed(() => (guided.value && !isReview.value ? choices.value.filter(c => inStep(c.path)) : []).map(c => ({
  id: c.id,
  path: c.path,
  label: c.label ?? fieldLabels.value.get(c.path) ?? c.id,
  cards: c.options.map<ChoiceCard>(o => ({
    value: o.value,
    name: o.label ?? optionEntry(o.compendium_id)?.labels['pt-BR'] ?? String(o.value),
    summary: optionEntry(o.compendium_id)?.summary ?? null,
    available: availability.value.get(c.id)?.options.find(x => x.value === o.value)?.available ?? true,
    requirement: o.requires ? describeRequirement(o.requires, labelSource) : null,
  })),
})))

const stepBudgets = computed(() => {
  const stepPaths = step.value?.paths ?? []
  const used = new Set(creationCosts.value.filter(c => c.targets.some(target => stepPaths.some(p => overlaps(p, target)))).map(c => c.budget))
  return creationBudgets.value.filter(b => used.has(b.id))
})
const errorsFor = (path: string) => issues.value.filter(i => i.paths.includes(path)).map(i => message(i))
const stepIssues = computed(() => issues.value.filter(i => i.paths.length === 0 || i.paths.some(inStep)))
const otherStepIssues = computed(() => stepIssues.value.filter(i => !statFields.value.some(f => i.paths.includes(f.path))))
const missingHere = computed(() => (step.value ? stepState(step.value.id)?.missing ?? [] : []).map(p => fieldLabels.value.get(p) ?? p))
function message(issue: ValidationIssue): string {
  return issue.rule_id === 'budget.insufficient'
    ? t(issue.rule_id, { ...issue.params, budget_label: budgetLabels.value[String(issue.params.budget)] ?? null })
    : t(issue.rule_id, issue.params)
}

// ---- Live sheet summary --------------------------------------------------------------------------

const summary = computed(() => {
  void tick.value
  const linkedIds = new Set([...linkedDerived.value.values()].flat().map(l => l.id))
  const groups: SummaryGroup[] = (ui.value?.sections ?? []).map(section => ({
    id: section.id,
    label: section.label,
    tiles: (section.elements.filter(e => e.kind === 'field' && isNumeric(e as FieldElement) && !derivedPaths.value.has(e.path)) as FieldElement[]).map(e => ({
      path: e.path,
      label: e.label,
      value: valueAt(e.path),
      sub: (linkedDerived.value.get(e.path) ?? []).map(l => `${l.label} ${signed(derivedValue(l.path))}`).join(' · ') || null,
    })),
  })).filter(g => g.tiles.length > 0)
  const trace = view.value?.decision_trace ?? []
  return {
    name: String(valueAt('/identity/name') ?? '') || character.value?.name || '',
    tags: choices.value.map(c => ({ label: c.label ?? c.id, value: optionLabels.value[c.path]?.[String(valueAt(c.path))] ?? '' })).filter(x => x.value && x.value !== 'undefined'),
    groups,
    derived: (rules.value.derived ?? []).filter(d => !linkedIds.has(d.id)).map(d => ({ label: d.label ?? d.id, value: derivedValue(d.path), explain: explain(trace.find((e: TraceEntry) => e.derived === d.id)) })),
    points: guided.value ? creationBudgets.value : [],
  }
})
const signed = (v: unknown) => String(v ?? '—')
function explain(entry?: TraceEntry): string | null {
  if (!entry || !entry.modifiers.length) return null
  return `Base ${entry.base}; ` + entry.modifiers.filter(m => m.applied).map(m => `${(rules.value.effects ?? []).find(e => e.id === m.effect)?.label ?? m.effect} ${signed(m.value)}`).join(', ')
}

// ---- Editing -------------------------------------------------------------------------------------

function setValue(path: string, value: Scalar): void {
  if (!draft.value) return
  draft.value.setField(path, value)
  tick.value++
  onChanged()
}
function choose(path: string, value: Scalar): void {
  setValue(path, valueAt(path) === value ? null : value)
}
function goTo(index: number): void {
  stepIndex.value = Math.max(0, Math.min(steps.value.length - 1, index))
  status.value = ''
  requestAnimationFrame(() => document.getElementById('builder-step-title')?.focus())
}

// ---- Loading, autosave and submission ------------------------------------------------------------

const pendingKey = `novtt.submit.${props.characterId}`

async function load(): Promise<void> {
  try {
    const c = await api<Character>('GET', `/campaign-characters/${props.characterId}`, undefined, { fetcher: props.fetcher })
    preset.value = await api<PresetVersion>('GET', `/preset-versions/${c.preset_version_id}`, undefined, { fetcher: props.fetcher })
    character.value = c
    if (c.campaign_id) applyTheme(loadTheme(c.campaign_id)) // the builder wears the campaign's theme
    try {
      const d = await api<Draft>('GET', `/campaign-characters/${props.characterId}/draft`, undefined, { fetcher: props.fetcher })
      draftVersion.value = d.draft_version
      issues.value = d.validation.issues
      balances.value = d.balances ?? {}
      preview.value = d.preview ?? null
      draft.value = new DraftModel(c.sheet, d.base_sheet_revision, d.change_set)
      size.value = draft.value.size
    } catch (error) {
      if (!(error instanceof ApiError) || error.status !== 404) throw error
      draft.value = new DraftModel(c.sheet, c.sheet_revision)
      balances.value = (await api<{ balances: Record<string, number> }>('GET', `/campaign-characters/${props.characterId}/grants`, undefined, { fetcher: props.fetcher })).balances
    }
    await loadPending()
    const firstOpen = steps.value.findIndex((_, i) => !stepDone(i))
    stepIndex.value = firstOpen < 0 ? Math.max(0, steps.value.length - 1) : firstOpen
  } catch (error) {
    failure.value = t(error instanceof ApiError ? error.code : 'network.error')
  }
}

async function loadPending(): Promise<void> {
  try {
    const list = await api<{ data: ChangeRequestSummary[] }>('GET', `/campaign-characters/${props.characterId}/change-requests`, undefined, { fetcher: props.fetcher })
    pending.value = list.data.find(r => ['submitted', 'in_review', 'awaiting_resolution'].includes(r.status)) ?? null
  } catch {
    pending.value = null
  }
}

/**
 * Each edit is saved (and validated by the server) shortly after the player stops, so errors,
 * points left and computed values update without pressing a button. Saves are serialized:
 * draft_version is a compare-and-set, and a second edit during a save triggers one more save.
 */
function onChanged(): void {
  size.value = draft.value?.size ?? 0
  clearTimeout(autosave)
  autosave = setTimeout(() => void save(), AUTOSAVE_MS)
}

function save(): Promise<boolean> {
  clearTimeout(autosave)
  if (saving) {
    again = true
    return saving
  }
  saving = persist().finally(() => {
    saving = null
    if (again) {
      again = false
      void save()
    }
  })
  return saving
}

async function persist(): Promise<boolean> {
  if (!draft.value) return false
  status.value = ''
  try {
    const saved = await api<Draft>('PUT', `/campaign-characters/${props.characterId}/draft`, { draft_version: draftVersion.value, change_set: draft.value.changeSet }, { fetcher: props.fetcher })
    draftVersion.value = saved.draft_version
    issues.value = saved.validation.issues
    if (saved.balances) balances.value = saved.balances
    if (saved.preview !== undefined) preview.value = saved.preview
    status.value = saved.validation.valid ? 'Rascunho salvo.' : 'Rascunho salvo com pendências.'
    return saved.validation.valid
  } catch (error) {
    issues.value = error instanceof ApiError ? error.validation?.issues ?? [] : []
    status.value = t(error instanceof ApiError ? error.code : 'network.error')
    return false
  } finally {
    busy.value = false
  }
}

async function submit(): Promise<void> {
  if (saving) await saving
  if (!(await save())) return
  // One idempotency key per intent, kept until the server confirms (ADR 0003).
  let key = sessionStorage.getItem(pendingKey)
  if (!key) {
    key = crypto.randomUUID()
    sessionStorage.setItem(pendingKey, key)
  }
  busy.value = true
  try {
    await api('POST', `/campaign-characters/${props.characterId}/change-requests`, { draft_version: draftVersion.value }, { idempotencyKey: key, fetcher: props.fetcher })
    sessionStorage.removeItem(pendingKey)
    status.value = 'Proposta enviada ao mestre.'
    draftVersion.value = 0
    draft.value = new DraftModel(character.value!.sheet, character.value!.sheet_revision)
    preview.value = null
    size.value = 0
    await loadPending()
  } catch (error) {
    status.value = t(error instanceof ApiError ? error.code : 'network.error')
    if (error instanceof ApiError && error.status !== 0) sessionStorage.removeItem(pendingKey)
  } finally {
    busy.value = false
  }
}

const statusLabel = computed(() => ({ draft: 'Em criação', pending_approval: 'Aguardando o mestre', approved: 'Aprovado', archived: 'Arquivado' } as Record<string, string>)[character.value?.status ?? ''] ?? character.value?.status ?? '')
const freeBudgets = computed(() => (rules.value.budgets ?? []).filter(b => b.id in balances.value).map(b => ({ ...b, left: balances.value[b.id] })))

onMounted(load)
onBeforeUnmount(() => clearTimeout(autosave))
</script>

<template>
  <main class="page builder" :class="{ guided }">
    <p v-if="failure" role="alert">{{ failure }}</p>
    <template v-else-if="character && preset && draft">
      <header class="builder-head">
        <a href="#/campanhas" class="back">← Campanhas</a>
        <div class="builder-title">
          <h1>{{ summary.name || character.name }}</h1>
          <p class="builder-sub">
            <span>{{ preset.display_name }}</span>
            <span class="chip">{{ statusLabel }}</span>
            <span v-if="pending" class="chip waiting">Proposta com o mestre</span>
          </p>
        </div>
      </header>

      <!-- Guided creation -->
      <template v-if="guided">
        <nav class="stepper" aria-label="Etapas da criação">
          <ol>
            <li v-for="(s, i) in steps" :key="s.id" :class="{ current: i === stepIndex, done: stepDone(i) }">
              <button type="button" :aria-current="i === stepIndex ? 'step' : undefined" @click="goTo(i)">
                <span class="step-mark" aria-hidden="true">{{ stepDone(i) ? '✓' : i + 1 }}</span>
                <span class="step-label">{{ s.label }}</span>
                <span class="sr-only">{{ stepDone(i) ? '(completa)' : '(pendente)' }}</span>
              </button>
            </li>
          </ol>
        </nav>

        <div class="builder-body" :class="{ review: isReview }">
          <section class="builder-main" aria-labelledby="builder-step-title">
            <div class="step-head">
              <h2 id="builder-step-title" tabindex="-1">{{ isReview ? 'Sua ficha' : step?.label }}</h2>
              <ul v-if="stepBudgets.length && !isReview" class="step-points" aria-label="Pontos desta etapa">
                <li v-for="b in stepBudgets" :key="b.id" :class="{ done: b.left === 0, over: b.left < 0 }"><b>{{ b.left }}</b> {{ b.label }}</li>
              </ul>
            </div>
            <p v-if="!isReview && missingHere.length" class="step-hint">Falta: {{ missingHere.join(', ') }}.</p>

            <template v-if="!isReview">
              <SheetEditor
                v-if="formUi"
                :key="`form-${stepIndex}`"
                class="bare"
                :ui="formUi"
                :schema="preset.sheet_schema"
                :draft="draft"
                :issues="stepIssues"
                :revision="character.sheet_revision"
                :budget-labels="budgetLabels"
                :option-labels="optionLabels"
                bare
                :readonly="!!pending"
                @changed="tick++; onChanged()"
              />

              <ChoiceGroup v-for="c in stepChoices" :id="`choice-${c.id}`" :key="c.id" :label="c.label" :hide-label="stepChoices.length === 1 && c.label === step?.label" :cards="c.cards" :chosen="valueAt(c.path)" @choose="v => choose(c.path, v)" />

              <div v-if="statFields.length" class="stat-grid">
                <StatRow
                  v-for="f in statFields"
                  :id="`stat${f.path.replace(/[^A-Za-z0-9]/g, '-')}`"
                  :key="f.path"
                  :label="f.label"
                  :value="Number(valueAt(f.path) ?? 0)"
                  :min="heldMin(f.path, node(f.path).minimum ?? 0)"
                  :max="node(f.path).maximum ?? 10"
                  :dots="f.widget === 'dot_rating'"
                  :linked="(linkedDerived.get(f.path) ?? []).map(l => ({ label: l.label, value: derivedValue(l.path) }))"
                  :can-raise="!pending && canRaise(f.path)"
                  :errors="errorsFor(f.path)"
                  :locked="lockedBy(f.path)"
                  :hint="holds.get(f.path)?.mode === 'at_least' ? `Mínimo ${holds.get(f.path)!.value} por ${holds.get(f.path)!.reason}` : null"
                  @set="v => setValue(f.path, v)"
                />
              </div>
              <ul v-if="otherStepIssues.length && !formUi" class="error-list" role="alert">
                <li v-for="(issue, i) in otherStepIssues" :key="i">{{ message(issue) }}</li>
              </ul>
            </template>

            <template v-else>
              <div class="review-sheet">
                <PortraitPicker
                v-if="character?.campaign_id" :campaign-id="character.campaign_id" :character-id="character.id" :name="summary.name || character.name"
                :image-id="character.portrait_image_id" :size="132" :editable="character.status !== 'archived'" @change="id => { if (character) character.portrait_image_id = id }"
              />
                <SheetSummary v-bind="summary" large />
              </div>
              <div class="review-box">
                <h3>{{ progress?.complete ? 'Tudo pronto para o mestre' : 'Antes de enviar' }}</h3>
                <ul class="review-steps">
                  <li v-for="(s, i) in steps.slice(0, -1)" :key="s.id" :class="{ ok: stepDone(i) }">
                    <span aria-hidden="true">{{ stepDone(i) ? '✓' : '○' }}</span> {{ s.label }}
                    <button v-if="!stepDone(i)" type="button" class="link" @click="goTo(i)">completar</button>
                  </li>
                </ul>
                <ul v-if="progress?.issues?.length" class="error-list">
                  <li v-for="(issue, i) in progress.issues" :key="i">{{ message(issue) }}</li>
                </ul>
                <p class="help">O mestre revisa cada escolha antes de o personagem entrar na mesa.</p>
              </div>
            </template>

            <div class="builder-actions">
              <button v-if="stepIndex > 0" type="button" class="secondary" @click="goTo(stepIndex - 1)">Voltar</button>
              <button v-if="!isReview" type="button" class="primary-cta" @click="goTo(stepIndex + 1)">Continuar: {{ steps[stepIndex + 1]?.label }} →</button>
              <button v-else type="button" class="primary-cta" :disabled="busy || size === 0 || !!pending" @click="submit">Enviar para o mestre</button>
            </div>
            <p v-if="pending" class="help">Há uma proposta em análise. Você poderá editar de novo quando o mestre decidir.</p>
            <p class="status" role="status" aria-live="polite">{{ status }}</p>
          </section>

          <aside v-if="!isReview" class="builder-side" aria-label="Sua ficha até agora">
            <p class="side-title">Sua ficha</p>
            <PortraitPicker
                v-if="character?.campaign_id" :campaign-id="character.campaign_id" :character-id="character.id" :name="summary.name || character.name"
                :image-id="character.portrait_image_id" :size="96" :editable="character.status !== 'archived'" @change="id => { if (character) character.portrait_image_id = id }"
              />
            <SheetSummary v-bind="summary" />
          </aside>
        </div>
      </template>

      <!-- Free editing (approved characters, presets without creation steps) -->
      <template v-else>
        <aside v-if="freeBudgets.length" class="budget-bar" aria-label="Pontos disponíveis">
          <dl>
            <div v-for="b in freeBudgets" :key="b.id" :class="{ empty: b.left <= 0 }">
              <dt>{{ b.label }}</dt>
              <dd>{{ b.left }}</dd>
            </div>
          </dl>
        </aside>
        <SheetEditor
          :ui="preset.sheet_ui"
          :schema="preset.sheet_schema"
          :draft="draft"
          :issues="issues"
          :revision="character.sheet_revision"
          :budget-labels="budgetLabels"
          :option-labels="optionLabels"
          :computed-values="Object.fromEntries([...derivedPaths].map(p => [p, derivedValue(p)]))"
          @changed="onChanged"
        />
        <div class="actions">
          <button type="button" class="secondary" :disabled="busy" @click="save">Salvar rascunho</button>
          <button type="button" :disabled="busy || size === 0" @click="submit">Enviar para o mestre</button>
        </div>
        <p class="status" role="status" aria-live="polite">{{ status }}</p>
      </template>
    </template>
    <p v-else role="status" aria-live="polite">Carregando…</p>
  </main>
</template>
