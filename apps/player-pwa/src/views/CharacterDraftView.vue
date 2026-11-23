<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import { api, ApiError } from '../api/client'
import SheetEditor from '../components/SheetEditor.vue'
import { t } from '../i18n/messages'
import { DraftModel } from '../sheets/draft'
import type { ChangeSet, Sheet, SheetUi, ValidationIssue, ValidationResult } from '../sheets/types'

const props = defineProps<{ characterId: string; fetcher?: typeof fetch }>()

interface Character { id: string; name: string; status: string; sheet_revision: number; preset_version_id: string; sheet: Sheet }
interface PresetVersion { sheet_ui: SheetUi; sheet_schema: Record<string, unknown>; rules?: { budgets?: { id: string; label: string }[] } }
interface Draft { draft_version: number; base_sheet_revision: number; change_set: ChangeSet; validation: ValidationResult; balances?: Record<string, number> }

const character = ref<Character | null>(null)
const preset = shallowRef<PresetVersion | null>(null)
const draft = shallowRef<DraftModel | null>(null)
const draftVersion = ref(0)
const issues = ref<ValidationIssue[]>([])
/** Remaining points per budget as the server computed them for the current draft. */
const balances = ref<Record<string, number>>({})
const status = ref('')
const failure = ref('')
const busy = ref(false)
const size = ref(0)
const AUTOSAVE_MS = 600
let autosave: ReturnType<typeof setTimeout> | undefined
let saving: Promise<boolean> | null = null
let again = false

const budgetList = computed(() => (preset.value?.rules?.budgets ?? []).filter(b => b.id in balances.value).map(b => ({ ...b, left: balances.value[b.id] })))
const budgetLabels = computed(() => Object.fromEntries((preset.value?.rules?.budgets ?? []).map(b => [b.id, b.label])))

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
      draft.value = new DraftModel(c.sheet, d.base_sheet_revision, d.change_set)
      size.value = draft.value.size
    } catch (error) {
      if (!(error instanceof ApiError) || error.status !== 404) throw error
      draft.value = new DraftModel(c.sheet, c.sheet_revision)
      balances.value = (await api<{ balances: Record<string, number> }>('GET', `/campaign-characters/${props.characterId}/grants`, undefined, { fetcher: props.fetcher })).balances
    }
  } catch (error) {
    failure.value = t(error instanceof ApiError ? error.code : 'network.error')
  }
}

/**
 * Each edit is saved (and validated by the server) shortly after the player stops, so errors and
 * their fixes show up without pressing a button. Saves are serialized: draft_version is a
 * compare-and-set, and a second edit during a save triggers exactly one more save.
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
    size.value = 0
  } catch (error) {
    status.value = t(error instanceof ApiError ? error.code : 'network.error')
    if (error instanceof ApiError && error.status !== 0) sessionStorage.removeItem(pendingKey)
  } finally {
    busy.value = false
  }
}

onMounted(load)
onBeforeUnmount(() => clearTimeout(autosave))
</script>

<template>
  <main class="page">
    <p v-if="failure" role="alert">{{ failure }}</p>
    <template v-else-if="character && preset && draft">
      <header>
        <p class="eyebrow">FICHA</p>
        <h1>{{ character.name }}</h1>
      </header>
      <aside v-if="budgetList.length" class="budget-bar" aria-label="Pontos disponíveis">
        <dl>
          <div v-for="b in budgetList" :key="b.id" :class="{ empty: b.left <= 0 }">
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
        @changed="onChanged"
      />
      <div class="actions">
        <button type="button" class="secondary" :disabled="busy" @click="save">Salvar rascunho</button>
        <button type="button" :disabled="busy || size === 0" @click="submit">Enviar para o mestre</button>
      </div>
      <p class="status" role="status" aria-live="polite">{{ status }}</p>
    </template>
    <p v-else role="status" aria-live="polite">Carregando…</p>
  </main>
</template>
