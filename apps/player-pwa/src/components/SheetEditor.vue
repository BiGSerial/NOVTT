<script setup lang="ts">
import { computed } from 'vue'
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
}>()
const emit = defineEmits<{ changed: [] }>()

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

function setField(path: string, raw: string | number | boolean): void {
  const n = node(path)
  let value: Scalar = raw as Scalar
  if (n.type === 'integer' || n.type === 'number') value = raw === '' ? null : Number(raw)
  props.draft.setField(path, value)
  emit('changed')
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
  emit('changed')
}
function removeItem(path: string, id: string): void {
  props.draft.removeItem(path, id)
  emit('changed')
}
function display(value: unknown): string {
  return value === undefined || value === null || value === '' ? '—' : String(value)
}
</script>

<template>
  <form class="sheet" novalidate @submit.prevent>
    <div v-if="issues.length" class="error-summary" role="alert" aria-labelledby="error-summary-title" tabindex="-1">
      <h2 id="error-summary-title">Há {{ issues.length }} {{ issues.length === 1 ? 'problema' : 'problemas' }} no rascunho</h2>
      <ul>
        <li v-for="(issue, index) in issues" :key="index">
          <a v-if="issue.paths[0]" :href="`#${fieldId(issue.paths[0])}`">{{ t(issue.rule_id, issue.params) }}</a>
          <span v-else>{{ t(issue.rule_id, issue.params) }}</span>
        </li>
      </ul>
    </div>
    <p v-for="(issue, index) in general" :key="`g${index}`" class="sr-only">{{ t(issue.rule_id, issue.params) }}</p>

    <fieldset v-for="{ section, elements } in visibleSections" :key="section.id" class="section">
      <legend>{{ section.label }}</legend>
      <template v-for="element in elements" :key="element.path">
        <div v-if="element.kind === 'field'" class="field" :class="{ invalid: errorsFor(element.path).length }">
          <template v-if="editable(section, element)">
            <DotRating
              v-if="element.widget === 'dot_rating'"
              :id="fieldId(element.path)"
              :label="element.label"
              :model-value="(draft.value(element.path) as number | null) ?? null"
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
                :value="(draft.value(element.path) as string) ?? ''"
                :maxlength="node(element.path).maxLength"
                :aria-invalid="errorsFor(element.path).length ? 'true' : undefined"
                :aria-describedby="describedBy(element.path, !!element.description)"
                @change="setField(element.path, ($event.target as HTMLTextAreaElement).value)"
              />
              <input
                v-else-if="element.widget === 'boolean'"
                :id="fieldId(element.path)"
                type="checkbox"
                :checked="draft.value(element.path) === true"
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
                :value="(draft.value(element.path) as string | number) ?? ''"
                :aria-invalid="errorsFor(element.path).length ? 'true' : undefined"
                :aria-describedby="describedBy(element.path, !!element.description)"
                @change="setField(element.path, ($event.target as HTMLInputElement).value)"
              />
            </template>
            <p v-if="element.description" :id="fieldId(element.path, '-help')" class="help">{{ element.description }}</p>
            <p v-if="accessOf(ui, section, element) === 'approval_required'" class="help">Alterações neste campo passam pela aprovação do mestre.</p>
          </template>
          <dl v-else class="readonly">
            <dt :id="fieldId(element.path)">{{ element.label }}</dt>
            <dd>{{ display(draft.value(element.path)) }}</dd>
          </dl>
          <p v-for="(issue, index) in errorsFor(element.path)" :id="index === 0 ? fieldId(element.path, '-error') : undefined" :key="index" class="error">
            {{ t(issue.rule_id, issue.params) }}
          </p>
        </div>

        <div v-else class="collection" :id="fieldId(element.path)">
          <h3>{{ element.label }}</h3>
          <p v-if="draft.items(element.path).length === 0" class="help">Nenhum item.</p>
          <ul>
            <li v-for="item in draft.items(element.path)" :key="item.id">
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
          <p v-for="(issue, index) in errorsFor(element.path)" :key="index" class="error">{{ t(issue.rule_id, issue.params) }}</p>
        </div>
      </template>
    </fieldset>
    <p class="help">Revisão oficial nº {{ revision }}. O rascunho não altera a ficha oficial.</p>
  </form>
</template>
