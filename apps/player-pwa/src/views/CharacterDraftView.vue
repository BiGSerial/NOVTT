<script setup lang="ts">
import { onMounted, ref, shallowRef } from 'vue'
import { api, ApiError } from '../api/client'
import SheetEditor from '../components/SheetEditor.vue'
import { t } from '../i18n/messages'
import { DraftModel } from '../sheets/draft'
import type { ChangeSet, Sheet, SheetUi, ValidationIssue, ValidationResult } from '../sheets/types'

const props = defineProps<{ characterId: string; fetcher?: typeof fetch }>()

interface Character { id: string; name: string; status: string; sheet_revision: number; preset_version_id: string; sheet: Sheet }
interface PresetVersion { sheet_ui: SheetUi; sheet_schema: Record<string, unknown> }
interface Draft { draft_version: number; base_sheet_revision: number; change_set: ChangeSet; validation: ValidationResult }

const character = ref<Character | null>(null)
const preset = shallowRef<PresetVersion | null>(null)
const draft = shallowRef<DraftModel | null>(null)
const draftVersion = ref(0)
const issues = ref<ValidationIssue[]>([])
const status = ref('')
const failure = ref('')
const busy = ref(false)
const tick = ref(0)

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
      draft.value = new DraftModel(c.sheet, d.base_sheet_revision, d.change_set)
    } catch (error) {
      if (!(error instanceof ApiError) || error.status !== 404) throw error
      draft.value = new DraftModel(c.sheet, c.sheet_revision)
    }
  } catch (error) {
    failure.value = t(error instanceof ApiError ? error.code : 'network.error')
  }
}

async function save(): Promise<boolean> {
  if (!draft.value) return false
  busy.value = true
  status.value = 'Salvando rascunho…'
  try {
    const saved = await api<Draft>('PUT', `/campaign-characters/${props.characterId}/draft`, { draft_version: draftVersion.value, change_set: draft.value.changeSet }, { fetcher: props.fetcher })
    draftVersion.value = saved.draft_version
    issues.value = saved.validation.issues
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
  } catch (error) {
    status.value = t(error instanceof ApiError ? error.code : 'network.error')
    if (error instanceof ApiError && error.status !== 0) sessionStorage.removeItem(pendingKey)
  } finally {
    busy.value = false
  }
}

onMounted(load)
</script>

<template>
  <main class="page">
    <p v-if="failure" role="alert">{{ failure }}</p>
    <template v-else-if="character && preset && draft">
      <header>
        <p class="eyebrow">FICHA</p>
        <h1>{{ character.name }}</h1>
      </header>
      <SheetEditor
        :key="tick"
        :ui="preset.sheet_ui"
        :schema="preset.sheet_schema"
        :draft="draft"
        :issues="issues"
        :revision="character.sheet_revision"
        @changed="tick++"
      />
      <div class="actions">
        <button type="button" class="secondary" :disabled="busy" @click="save">Salvar rascunho</button>
        <button type="button" :disabled="busy || draft.size === 0" @click="submit">Enviar para o mestre</button>
      </div>
      <p class="status" role="status" aria-live="polite">{{ status }}</p>
    </template>
    <p v-else role="status" aria-live="polite">Carregando…</p>
  </main>
</template>
