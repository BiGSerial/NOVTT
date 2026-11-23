<script setup lang="ts">
import { computed, ref } from 'vue'
import { t } from '../i18n/messages'
import type { DraftModel } from '../sheets/draft'
import { segments } from '../sheets/pointer'
import { accessOf, canPropose, canRead, type CollectionElement, type FieldElement, type Scalar, type SheetUi, type ValidationIssue } from '../sheets/types'
import DotRating from './DotRating.vue'

const props = defineProps<{
  ui: SheetUi
  schema: Record<string, unknown>
  draft: DraftModel
  issues: ValidationIssue[]
  revision: number
  readonly?: boolean
  /** Budget id → player-facing label (preset rules). Raw ids are never shown. */
  budgetLabels?: Record<string, string>
  /** Path → option value → label (from the preset's choices), so codes are never shown. */
  optionLabels?: Record<string, Record<string, string>>
  /** Server-computed values (derived fields of the draft preview) shown instead of the draft's. */
  computedValues?: Record<string, unknown>
  /** Inside the guided builder: no approval notes, no error summary, no revision footer. */
  bare?: boolean
}>()
const emit = defineEmits<{ changed: [] }>()

// DraftModel is a plain class; this counter makes reads reactive without remounting the form,
// so keyboard focus stays on the control the player is using.
const version = ref(0)
function valueOf(path: string): unknown {
  void version.value
  if (props.computedValues && path in props.computedValues) return props.computedValues[path]
  return props.draft.value(path)
}
function itemsOf(path: string) {
  void version.value
  return props.draft.items(path)
}
function changed(): void {
  version.value++
  emit('changed')
}

function message(issue: ValidationIssue): string {
  if (issue.rule_id === 'budget.insufficient') {
    return t(issue.rule_id, { ...issue.params, budget_label: props.budgetLabels?.[String(issue.params.budget)] ?? null })
  }
  return t(issue.rule_id, issue.params)
}

type SchemaNode = { type?: string; minimum?: number; maximum?: number; maxLength?: number; enum?: Scalar[]; properties?: Record<string, SchemaNode>; items?: SchemaNode }

function node(path: string): SchemaNode {
  let current = props.schema as SchemaNode
  for (const segment of segments(path)) current = current.properties?.[segment] ?? {}
  return current
}

function fieldId(path: string, extra = ''): string {
  return `f${path.replace(/[^A-Za-z0-9]/g, '-')}${extra}`
}

const issuesByPath = computed(() => {
  const map = new Map<string, ValidationIssue[]>()
  for (const issue of props.issues) for (const path of issue.paths) map.set(path, [...(map.get(path) ?? []), issue])
  return map
})
const general = computed(() => props.issues.filter(i => i.paths.length === 0))

function errorsFor(path: string): ValidationIssue[] {
  return issuesByPath.value.get(path) ?? []
}

const visibleSections = computed(() => props.ui.sections
  .map(section => ({ section, elements: section.elements.filter(e => canRead(accessOf(props.ui, section, e))) }))
  .filter(s => s.elements.length > 0))

function editable(section: (typeof props.ui.sections)[number], element: FieldElement | CollectionElement): boolean {
  return !props.readonly && canPropose(accessOf(props.ui, section, element))
}

/** True when all editable elements of the section need the master's approval: say it once. */
function sectionNeedsApproval(section: (typeof props.ui.sections)[number], elements: (FieldElement | CollectionElement)[]): boolean {
  const editableOnes = elements.filter(e => editable(section, e))
  return editableOnes.length > 0 && editableOnes.every(e => accessOf(props.ui, section, e) === 'approval_required')
}

function setField(path: string, raw: string | number | boolean): void {
  const n = node(path)
  let value: Scalar = raw as Scalar
  if (n.type === 'integer' || n.type === 'number') value = raw === '' ? null : Number(raw)
  props.draft.setField(path, value)
  changed()
}

function describedBy(path: string, hasHelp: boolean): string | undefined {
  const ids = [errorsFor(path).length ? fieldId(path, '-error') : '', hasHelp ? fieldId(path, '-help') : ''].filter(Boolean)
  return ids.length ? ids.join(' ') : undefined
}

const newItem = new Map<string, Record<string, Scalar>>()
function newItemFor(path: string): Record<string, Scalar> {
  if (!newItem.has(path)) newItem.set(path, {})
  return newItem.get(path)!
}
function addItem(element: CollectionElement): void {
  const value = { ...newItemFor(element.path) }
  for (const f of element.item_fields) {
    const itemNode = node(element.path).items?.properties?.[f.field] ?? {}
    if (itemNode.type === 'integer' && value[f.field] !== undefined) value[f.field] = Number(value[f.field])
  }
  props.draft.addItem(element.path, value)
  newItem.set(element.path, {})
  changed()
}
function removeItem(path: string, id: string): void {
  props.draft.removeItem(path, id)
  changed()
}
function display(value: unknown): string {
  return value === undefined || value === null || value === '' ? '—' : String(value)
}
</script>

<template>
  <form class="sheet" novalidate @submit.prevent>
    <div v-if="issues.length && !bare" class="error-summary" role="alert" aria-labelledby="error-summary-title" tabindex="-1">
      <h2 id="error-summary-title">Há {{ issues.length }} {{ issues.length === 1 ? 'problema' : 'problemas' }} no rascunho</h2>
      <ul>
        <li v-for="(issue, index) in issues" :key="index">
          <a v-if="issue.paths[0]" :href="`#${fieldId(issue.paths[0])}`">{{ message(issue) }}</a>
          <span v-else>{{ message(issue) }}</span>
        </li>
      </ul>
    </div>
    <p v-for="(issue, index) in general" :key="`g${index}`" class="sr-only">{{ message(issue) }}</p>

    <fieldset v-for="{ section, elements } in visibleSections" :key="section.id" class="section">
      <legend>{{ section.label }}</legend>
      <p v-if="!bare && sectionNeedsApproval(section, elements)" class="help approval-note">Mudanças nesta seção passam pela aprovação do mestre.</p>
      <template v-for="element in elements" :key="element.path">
        <div v-if="element.kind === 'field'" class="field" :class="{ invalid: errorsFor(element.path).length }">
          <template v-if="editable(section, element)">
            <DotRating
              v-if="element.widget === 'dot_rating'"
              :id="fieldId(element.path)"
              :label="element.label"
              :model-value="(valueOf(element.path) as number | null) ?? null"
              :min="node(element.path).minimum ?? 0"
              :max="node(element.path).maximum ?? 5"
              :described-by="describedBy(element.path, !!element.description)"
              :invalid="errorsFor(element.path).length > 0"
              @update:model-value="setField(element.path, $event)"
            />
            <template v-else>
              <label :for="fieldId(element.path)" class="field-label">{{ element.label }}</label>
              <textarea
                v-if="element.widget === 'textarea'"
                :id="fieldId(element.path)"
                :value="(valueOf(element.path) as string) ?? ''"
                :maxlength="node(element.path).maxLength"
                :aria-invalid="errorsFor(element.path).length ? 'true' : undefined"
                :aria-describedby="describedBy(element.path, !!element.description)"
                @change="setField(element.path, ($event.target as HTMLTextAreaElement).value)"
              />
              <select
                v-else-if="element.widget === 'select' && node(element.path).enum"
                :id="fieldId(element.path)"
                :value="(valueOf(element.path) as string) ?? ''"
                :aria-invalid="errorsFor(element.path).length ? 'true' : undefined"
                :aria-describedby="describedBy(element.path, !!element.description)"
                @change="setField(element.path, ($event.target as HTMLSelectElement).value)"
              >
                <option value="">Escolha…</option>
                <option v-for="option in node(element.path).enum" :key="String(option)" :value="String(option)">{{ optionLabels?.[element.path]?.[String(option)] ?? option }}</option>
              </select>
              <input
                v-else-if="element.widget === 'boolean'"
                :id="fieldId(element.path)"
                type="checkbox"
                :checked="valueOf(element.path) === true"
                :aria-describedby="describedBy(element.path, !!element.description)"
                @change="setField(element.path, ($event.target as HTMLInputElement).checked)"
              />
              <input
                v-else
                :id="fieldId(element.path)"
                :type="element.widget === 'integer' || element.widget === 'number' ? 'number' : 'text'"
                :inputmode="element.widget === 'integer' ? 'numeric' : undefined"
                :min="node(element.path).minimum"
                :max="node(element.path).maximum"
                :maxlength="node(element.path).maxLength"
                :value="(valueOf(element.path) as string | number) ?? ''"
                :aria-invalid="errorsFor(element.path).length ? 'true' : undefined"
                :aria-describedby="describedBy(element.path, !!element.description)"
                @change="setField(element.path, ($event.target as HTMLInputElement).value)"
              />
            </template>
            <p v-if="element.description" :id="fieldId(element.path, '-help')" class="help">{{ element.description }}</p>
            <p v-if="!bare && accessOf(ui, section, element) === 'approval_required' && !sectionNeedsApproval(section, elements)" class="help">Alterações neste campo passam pela aprovação do mestre.</p>
          </template>
          <dl v-else class="readonly">
            <dt :id="fieldId(element.path)">{{ element.label }}</dt>
            <dd>{{ optionLabels?.[element.path]?.[String(valueOf(element.path))] ?? display(valueOf(element.path)) }}</dd>
          </dl>
          <p v-for="(issue, index) in errorsFor(element.path)" :id="index === 0 ? fieldId(element.path, '-error') : undefined" :key="index" class="error">
            {{ message(issue) }}
          </p>
        </div>

        <div v-else class="collection" :id="fieldId(element.path)">
          <h3 :class="{ 'sr-only': element.label === section.label }">{{ element.label }}</h3>
          <p v-if="itemsOf(element.path).length === 0" class="help">Nenhum item.</p>
          <ul>
            <li v-for="item in itemsOf(element.path)" :key="item.id">
              <span v-for="f in element.item_fields" :key="f.field" class="item-field">
                <span class="sr-only">{{ f.label }}: </span>{{ display(item[f.field]) }}
              </span>
              <button v-if="editable(section, element)" type="button" class="secondary" :aria-label="`Remover ${display(item[element.item_fields[0].field])}`" @click="removeItem(element.path, item.id)">Remover</button>
            </li>
          </ul>
          <fieldset v-if="editable(section, element)" class="add-item">
            <legend>Adicionar em {{ element.label }}</legend>
            <label v-for="f in element.item_fields" :key="f.field" :for="fieldId(element.path, `-new-${f.field}`)">
              {{ f.label }}
              <input :id="fieldId(element.path, `-new-${f.field}`)" :type="f.widget === 'integer' ? 'number' : 'text'" @input="newItemFor(element.path)[f.field] = ($event.target as HTMLInputElement).value" />
            </label>
            <button type="button" @click="addItem(element)">Adicionar</button>
          </fieldset>
          <p v-for="(issue, index) in errorsFor(element.path)" :key="index" class="error">{{ message(issue) }}</p>
        </div>
      </template>
    </fieldset>
    <p v-if="!bare" class="help">Revisão oficial nº {{ revision }}. O rascunho não altera a ficha oficial.</p>
  </form>
</template>
