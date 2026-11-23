<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import { api, ApiError } from '../api/client'
import SheetEditor from '../components/SheetEditor.vue'
import { t } from '../i18n/messages'
import { DraftModel } from '../sheets/draft'
import { matchesTarget, overlaps, type BuilderRules, type ChoiceAvailability, type CreationProgress, type SheetView, type TraceEntry } from '../sheets/builder'
import type { ChangeSet, Scalar, Sheet, SheetUi, ValidationIssue, ValidationResult } from '../sheets/types'
import '../styles/builder.css'

const props = defineProps<{ characterId: string; fetcher?: typeof fetch }>()

interface Character extends Partial<SheetView> { id: string; campaign_id?: string; name: string; status: string; sheet_revision: number; preset_version_id: string; sheet: Sheet }
interface CompendiumEntry { canonical_id: string; kind: string; labels: Record<string, string>; summary: string | null }
interface PresetVersion {
  display_name?: string
  rules_revision?: string | null
  sheet_ui: SheetUi
  sheet_schema: Record<string, unknown>
  rules?: BuilderRules
  compendium?: CompendiumEntry[]
}
interface Draft { draft_version: number; base_sheet_revision: number; change_set: ChangeSet; validation: ValidationResult; balances?: Record<string, number>; preview?: SheetView | null }
interface ChangeRequestSummary { id: string; status: string }

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
const budgetLabels = computed(() => Object.fromEntries((rules.value.budgets ?? []).map(b => [b.id, b.label])))
const compendium = computed(() => new Map((preset.value?.compendium ?? []).map(e => [e.canonical_id, e])))
const view = computed<SheetView | null>(() => preview.value ?? (character.value ? { sheet: character.value.sheet, decision_trace: character.value.decision_trace ?? [], creation: character.value.creation, choices: character.value.choices } : null))

// ---- Guided creation (rules/2 presets with creation steps) -------------------------------------

const steps = computed(() => rules.value.creation?.steps ?? [])
const progress = computed<CreationProgress | undefined>(() => view.value?.creation)
const guided = computed(() => steps.value.length > 0 && character.value?.status !== 'approved' && character.value?.status !== 'archived')
const step = computed(() => steps.value[stepIndex.value])
const isReview = computed(() => guided.value && stepIndex.value === steps.value.length - 1)
const stepState = (id: string) => progress.value?.steps.find(s => s.id === id)

const choices = computed(() => rules.value.creation?.choices ?? [])
const choicePaths = computed(() => new Set(choices.value.map(c => c.path)))
const optionLabels = computed(() => Object.fromEntries(choices.value.map(c => [c.path, Object.fromEntries(c.options.map(o => [String(o.value), o.label ?? optionEntry(o.compendium_id)?.labels['pt-BR'] ?? String(o.value)]))])))
const availability = computed(() => new Map((view.value?.choices ?? []).map(c => [c.id, c])))

function inStep(path: string): boolean {
  const s = step.value
  return !!s && [...(s.paths ?? []), ...(s.required ?? [])].some(target => matchesTarget(target, path))
}

/** Only the elements of the current step; choices get cards instead of form controls. */
const stepUi = computed<SheetUi | null>(() => {
  const ui = preset.value?.sheet_ui
  if (!ui || !guided.value || isReview.value) return ui ?? null
  const sections = ui.sections
    .map(section => ({ ...section, elements: section.elements.filter(e => inStep(e.path) && !choicePaths.value.has(e.path)) }))
    .filter(section => section.elements.length > 0)
  return sections.length ? { ...ui, sections } : null
})
const stepChoices = computed(() => (guided.value && !isReview.value ? choices.value.filter(c => inStep(c.path)) : []))

/** Budgets spent by the current step during creation (from the preset's cost rules). */
const stepBudgets = computed(() => {
  const all = (rules.value.budgets ?? []).filter(b => b.id in balances.value)
  if (!guided.value || isReview.value) return all.map(b => ({ ...b, left: balances.value[b.id] }))
  const stepPaths = step.value?.paths ?? []
  const used = new Set((rules.value.costs ?? []).filter(c => c.phase === 'creation' && c.targets.some(target => stepPaths.some(p => overlaps(p, target)))).map(c => c.budget))
  return all.filter(b => used.has(b.id)).map(b => ({ ...b, left: balances.value[b.id] }))
})
const allBudgets = computed(() => (rules.value.budgets ?? []).filter(b => b.id in balances.value).map(b => ({ ...b, left: balances.value[b.id] })))

const stepIssues = computed(() => (guided.value && !isReview.value ? issues.value.filter(i => i.paths.length === 0 || i.paths.some(inStep)) : issues.value))
const creationIssues = computed(() => (progress.value?.issues ?? []).filter(i => !issues.value.some(j => j.rule_id === i.rule_id && j.paths.join() === i.paths.join())))

/** Derived values of the preview, by path, for the read-only fields and the "calculados" panel. */
const derived = computed(() => {
  const out: Record<string, unknown> = {}
  for (const d of rules.value.derived ?? []) out[d.path] = readPath(view.value?.sheet, d.path)
  return out
})
const derivedList = computed(() => (rules.value.derived ?? [])
  .map(d => ({ ...d, value: derived.value[d.path], trace: (view.value?.decision_trace ?? []).find((e: TraceEntry) => e.derived === d.id) }))
  .filter(d => d.value !== undefined))

function readPath(doc: unknown, pointer: string): unknown {
  let node = doc
  for (const raw of pointer.split('/').slice(1)) {
    const key = raw.replace(/~1/g, '/').replace(/~0/g, '~')
    if (node === null || typeof node !== 'object' || !(key in (node as object))) return undefined
    node = (node as Record<string, unknown>)[key]
  }
  return node
}

function optionEntry(id?: string): CompendiumEntry | undefined {
  return id ? compendium.value.get(id) : undefined
}
function optionAvailable(choiceId: string, value: Scalar): boolean {
  const c: ChoiceAvailability | undefined = availability.value.get(choiceId)
  return c?.options.find(o => o.value === value)?.available ?? true
}
function chosen(path: string): unknown {
  void tick.value
  return draft.value?.value(path)
}
function choose(path: string, value: Scalar): void {
  if (!draft.value) return
  draft.value.setField(path, chosen(path) === value ? null : value)
  tick.value++
  onChanged()
}

function goTo(index: number): void {
  stepIndex.value = Math.max(0, Math.min(steps.value.length - 1, index))
  status.value = ''
  requestAnimationFrame(() => document.getElementById('builder-step-title')?.focus())
}

function modifierText(m: TraceEntry['modifiers'][number]): string {
  const sign = typeof m.value === 'number' && m.value >= 0 ? '+' : ''
  const from = m.source.kind === 'item' ? `item ${m.source.item_id}` : (rules.value.effects ?? []).find(e => e.id === m.effect)?.label ?? m.effect
  return m.mode === 'set' ? `${from}: vira ${m.value}` : `${from}: ${sign}${m.value}${m.applied ? '' : ' (não acumula)'}`
}

// ---- Loading, autosave and submission ----------------------------------------------------------

const pendingKey = `novtt.submit.${props.characterId}`

async function load(): Promise<void> {
  try {
    const c = await api<Character>('GET', `/campaign-characters/${props.characterId}`, undefined, { fetcher: props.fetcher })
    preset.value = await api<PresetVersion>('GET', `/preset-versions/${c.preset_version_id}`, undefined, { fetcher: props.fetcher })
    character.value = c
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
    const firstOpen = steps.value.findIndex(s => !stepState(s.id)?.complete)
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

onMounted(load)
onBeforeUnmount(() => clearTimeout(autosave))
</script>

<template>
  <main class="page builder" :class="{ guided }">
    <p v-if="failure" role="alert">{{ failure }}</p>
    <template v-else-if="character && preset && draft">
      <header class="builder-head">
        <a v-if="character.campaign_id" class="back" href="#/campanhas">← Campanhas</a>
        <p class="eyebrow">{{ guided ? 'Criação de personagem' : 'Ficha' }}<template v-if="preset.display_name"> · {{ preset.display_name }}</template></p>
        <h1>{{ (chosen('/identity/name') as string) || character.name }}</h1>
        <p class="builder-status"><span class="chip">{{ statusLabel }}</span><span v-if="pending" class="chip waiting">Proposta com o mestre</span></p>
      </header>

      <nav v-if="guided" class="stepper" aria-label="Etapas da criação">
        <ol>
          <li v-for="(s, i) in steps" :key="s.id" :class="{ current: i === stepIndex, done: stepState(s.id)?.complete }">
            <button type="button" :aria-current="i === stepIndex ? 'step' : undefined" @click="goTo(i)">
              <span class="step-mark" aria-hidden="true">{{ stepState(s.id)?.complete ? '✓' : i + 1 }}</span>
              <span class="step-label">{{ s.label }}</span>
              <span class="sr-only">{{ stepState(s.id)?.complete ? '(completa)' : '(pendente)' }}</span>
            </button>
          </li>
        </ol>
      </nav>

      <div class="builder-body">
        <section class="builder-main" :aria-labelledby="guided ? 'builder-step-title' : undefined">
          <h2 v-if="guided" id="builder-step-title" tabindex="-1">{{ step?.label }}</h2>
          <p v-if="guided && stepState(step?.id ?? '')?.missing.length" class="help">Falta preencher {{ stepState(step!.id)!.missing.length === 1 ? 'um campo' : `${stepState(step!.id)!.missing.length} campos` }} nesta etapa.</p>

          <div v-if="stepBudgets.length" class="budget-bar" aria-label="Pontos disponíveis">
            <dl>
              <div v-for="b in stepBudgets" :key="b.id" :class="{ empty: b.left === 0, over: b.left < 0 }">
                <dt>{{ b.label }}</dt>
                <dd>{{ b.left }}</dd>
              </div>
            </dl>
          </div>

          <fieldset v-for="c in stepChoices" :key="c.id" class="choice-group">
            <legend>{{ c.label ?? c.id }}</legend>
            <div class="choice-cards" role="radiogroup" :aria-label="c.label ?? c.id">
              <button
                v-for="o in c.options"
                :key="String(o.value)"
                type="button"
                role="radio"
                class="choice-card"
                :class="{ selected: chosen(c.path) === o.value, locked: !optionAvailable(c.id, o.value) }"
                :aria-checked="chosen(c.path) === o.value ? 'true' : 'false'"
                :aria-disabled="!optionAvailable(c.id, o.value) && chosen(c.path) !== o.value ? 'true' : undefined"
                @click="optionAvailable(c.id, o.value) || chosen(c.path) === o.value ? choose(c.path, o.value) : undefined"
              >
                <span class="choice-name">{{ o.label ?? optionEntry(o.compendium_id)?.labels['pt-BR'] ?? o.value }}</span>
                <span v-if="optionEntry(o.compendium_id)?.summary" class="choice-summary">{{ optionEntry(o.compendium_id)!.summary }}</span>
                <span v-if="!optionAvailable(c.id, o.value)" class="choice-lock">Pré-requisito não atendido</span>
              </button>
            </div>
          </fieldset>

          <SheetEditor
            v-if="stepUi"
            :key="`${stepIndex}-${isReview}`"
            :ui="stepUi"
            :schema="preset.sheet_schema"
            :draft="draft"
            :issues="stepIssues"
            :revision="character.sheet_revision"
            :budget-labels="budgetLabels"
            :option-labels="optionLabels"
            :computed-values="derived"
            :readonly="isReview || !!pending"
            @changed="onChanged"
          />

          <div v-if="isReview" class="review-box">
            <h3>Antes de enviar</h3>
            <ul class="review-steps">
              <li v-for="s in steps.slice(0, -1)" :key="s.id" :class="{ ok: stepState(s.id)?.complete }">
                {{ stepState(s.id)?.complete ? '✓' : '•' }} {{ s.label }}
                <button v-if="!stepState(s.id)?.complete" type="button" class="link" @click="goTo(steps.indexOf(s))">completar</button>
              </li>
            </ul>
            <ul v-if="creationIssues.length" class="error-list">
              <li v-for="(issue, i) in creationIssues" :key="i">{{ t(issue.rule_id, issue.params) }}</li>
            </ul>
            <p class="help">O mestre revisa cada escolha. Depois de aprovado, o personagem entra na mesa.</p>
          </div>

          <div class="actions">
            <button v-if="guided && stepIndex > 0" type="button" class="secondary" @click="goTo(stepIndex - 1)">Voltar</button>
            <button v-if="guided && !isReview" type="button" @click="goTo(stepIndex + 1)">Próxima etapa</button>
            <template v-if="!guided || isReview">
              <button type="button" class="secondary" :disabled="busy || !!pending" @click="save">Salvar rascunho</button>
              <button type="button" :disabled="busy || size === 0 || !!pending" @click="submit">Enviar para o mestre</button>
            </template>
          </div>
          <p v-if="pending" class="help">Há uma proposta em análise. Você poderá editar de novo quando o mestre decidir.</p>
          <p class="status" role="status" aria-live="polite">{{ status }}</p>
        </section>

        <aside v-if="derivedList.length || (guided && allBudgets.length)" class="builder-side" aria-label="Valores calculados">
          <h2>Calculados</h2>
          <p class="help">A ficha calcula estes valores; mude os campos de origem para alterá-los.</p>
          <dl class="derived-list">
            <div v-for="d in derivedList" :key="d.id">
              <dt>{{ d.label ?? d.id }}</dt>
              <dd>{{ d.value }}</dd>
              <dd v-if="d.trace && d.trace.modifiers.length" class="trace">
                <details>
                  <summary>Como foi calculado</summary>
                  <p>Base {{ d.trace.base }}</p>
                  <ul><li v-for="(m, i) in d.trace.modifiers" :key="i" :class="{ muted: !m.applied }">{{ modifierText(m) }}</li></ul>
                </details>
              </dd>
            </div>
          </dl>
          <template v-if="guided && allBudgets.length">
            <h2>Pontos</h2>
            <dl class="derived-list">
              <div v-for="b in allBudgets" :key="b.id"><dt>{{ b.label }}</dt><dd>{{ b.left }}</dd></div>
            </dl>
          </template>
        </aside>
      </div>
    </template>
    <p v-else role="status" aria-live="polite">Carregando…</p>
  </main>
</template>
