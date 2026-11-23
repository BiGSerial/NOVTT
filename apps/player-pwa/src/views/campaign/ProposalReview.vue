<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { ApiError } from '../../api/client'
import { t } from '../../i18n/messages'
import { readPointer } from '../../live/sheet'
import { allDecided, REASON_MAX, REASON_MIN, reasonError, review, type ChangeRequestView, type Decision, type OperationView } from '../../live/campaign'

/**
 * Master review of one proposal (SPEC-SPRINT-001 rules 4 and 7): operations grouped by section
 * with the current value and the proposed one; a section can be approved at once (provisional),
 * each rejection needs its own reason, and finalization applies the approved set or nothing.
 */
const props = defineProps<{ request: ChangeRequestView; sheet: Record<string, unknown>; labels: Map<string, string>; budgetLabels: Record<string, string>; optionLabels?: Record<string, Record<string, string>> }>()
const emit = defineEmits<{ updated: [view: ChangeRequestView]; finalized: [view: ChangeRequestView] }>()

const reasons = reactive<Record<string, string>>({})
const rejecting = reactive<Record<string, boolean>>({})
const errors = reactive<Record<string, string>>({})
const status = ref('')
const busy = ref(false)
let finalizeKey: string | null = null

const issuesByOp = computed(() => {
  const map = new Map<string, string[]>()
  for (const issue of props.request.validation?.issues ?? []) {
    const params = { ...(issue.params ?? {}), budget_label: props.budgetLabels[String(issue.params?.budget ?? '')] ?? null } as Record<string, string | number | boolean | null>
    for (const id of issue.operation_ids) map.set(id, [...(map.get(id) ?? []), t(issue.rule_id, params)])
  }
  return map
})
const decided = computed(() => allDecided(props.request))
const active = computed(() => ['submitted', 'in_review', 'awaiting_resolution'].includes(props.request.status))

function show(value: unknown): string {
  if (value === undefined || value === null || value === '') return '—'
  if (typeof value === 'boolean') return value ? 'Sim' : 'Não'
  if (typeof value === 'object') return Object.entries(value as Record<string, unknown>).map(([k, v]) => `${k}: ${show(v)}`).join(', ')
  return String(value)
}

function label(op: OperationView): string {
  const base = props.labels.get(op.path) ?? op.path
  if (op.field) return `${base} › ${props.labels.get(`${op.path}#${op.field}`) ?? op.field}`
  return base
}

function before(op: OperationView): string {
  if (op.type === 'add_item') return 'novo item'
  const current = readPointer(props.sheet, op.path)
  if (!op.item_id && current !== undefined && current !== null) return props.optionLabels?.[op.path]?.[String(current)] ?? show(current)
  if (op.item_id && Array.isArray(current)) {
    const item = (current as Record<string, unknown>[]).find(i => i.id === op.item_id)
    return op.field ? show(item?.[op.field]) : show(item?.name ?? op.item_id)
  }
  return show(current)
}

function after(op: OperationView): string {
  if (op.type === 'remove_item') return 'removido'
  if (op.type === 'unset_field') return '—'
  if (op.type === 'reorder_items') return 'nova ordem'
  if (!op.item_id && op.value !== null && op.value !== undefined) return props.optionLabels?.[op.path]?.[String(op.value)] ?? show(op.value)
  return show(op.value)
}

function decisionText(op: OperationView): string {
  if (!op.decision) return 'Pendente'
  const verb = op.decision.decision === 'approved' ? 'Aprovada' : 'Rejeitada'
  return op.decision.final ? verb : `${verb} (provisória)`
}

async function send(decisions: Decision[]): Promise<void> {
  if (!decisions.length) return
  busy.value = true
  status.value = ''
  try {
    emit('updated', await review.decide(props.request.id, props.request.review_version, decisions))
    status.value = 'Decisões salvas.'
  } catch (e) {
    status.value = t(e instanceof ApiError ? e.code : 'network.error')
    if (e instanceof ApiError && e.code === 'change_request.review_version_conflict') emit('updated', await review.request(props.request.id))
  } finally { busy.value = false }
}

function approveSection(section: ChangeRequestView['sections'][number]): void {
  // A batch never overrides a rejection: those are revisited one by one.
  const ids = section.operations.filter(o => !o.decision?.final && o.decision?.decision !== 'rejected' && o.decision?.decision !== 'approved').map(o => o.id)
  void send(ids.length ? [{ operation_ids: ids, decision: 'approved' }] : [])
}

function approveOne(op: OperationView): void {
  void send([op.decision?.decision === 'rejected' ? { operation_ids: [op.id], decision: 'approved', override: true } : { operation_ids: [op.id], decision: 'approved' }])
}

function reject(op: OperationView): void {
  const reason = reasons[op.id] ?? ''
  errors[op.id] = reasonError(reason) ?? ''
  if (errors[op.id]) return
  rejecting[op.id] = false
  void send([{ operation_ids: [op.id], decision: 'rejected', reason: reason.trim() }])
}

async function finalize(): Promise<void> {
  busy.value = true
  status.value = ''
  finalizeKey ??= crypto.randomUUID()
  try {
    const view = await review.finalize(props.request.id, props.request.review_version, finalizeKey)
    finalizeKey = null
    status.value = view.status === 'applied' ? 'Proposta aplicada à ficha.' : 'Proposta encerrada sem mudanças.'
    emit('finalized', view)
  } catch (e) {
    status.value = t(e instanceof ApiError ? e.code : 'network.error')
    if (e instanceof ApiError && e.status !== 0) finalizeKey = null
  } finally { busy.value = false }
}
</script>

<template>
  <section class="proposal-review" :aria-label="`Proposta ${request.id.slice(0, 8)}`">
    <fieldset v-for="section in request.sections" :key="section.section_id" class="review-section">
      <legend>{{ section.label }}</legend>
      <ul class="op-list">
        <li v-for="op in section.operations" :key="op.id" class="op-row" :class="op.decision?.decision">
          <div class="op-change">
            <span class="op-label">{{ label(op) }}</span>
            <span class="op-values"><span class="before">{{ before(op) }}</span> <span aria-hidden="true">→</span><span class="sr-only">para</span> <strong class="after">{{ after(op) }}</strong></span>
          </div>
          <span class="op-decision">{{ decisionText(op) }}</span>
          <p v-if="op.decision?.reason" class="reason small">Motivo: {{ op.decision.reason }}</p>
          <p v-for="msg in issuesByOp.get(op.id) ?? []" :key="msg" class="error small">{{ msg }}</p>
          <div v-if="active && !op.decision?.final" class="op-actions">
            <button type="button" class="small-btn" :disabled="busy || op.decision?.decision === 'approved'" @click="approveOne(op)">Aprovar</button>
            <button type="button" class="small-btn" :disabled="busy" :aria-expanded="rejecting[op.id] ? 'true' : 'false'" @click="rejecting[op.id] = !rejecting[op.id]">Rejeitar…</button>
          </div>
          <div v-if="rejecting[op.id]" class="reject-form">
            <label class="field">Motivo da rejeição ({{ REASON_MIN }} a {{ REASON_MAX }} caracteres)
              <textarea v-model="reasons[op.id]" :maxlength="REASON_MAX" rows="2" />
            </label>
            <p v-if="errors[op.id]" class="error small" role="alert">{{ errors[op.id] }}</p>
            <button type="button" class="small-btn" :disabled="busy" @click="reject(op)">Confirmar rejeição</button>
          </div>
        </li>
      </ul>
      <button v-if="active" type="button" class="ghost" :disabled="busy" @click="approveSection(section)">Aprovar pendentes de {{ section.label }}</button>
    </fieldset>
    <div v-if="active" class="card-actions">
      <button type="button" class="primary" :disabled="busy || !decided" aria-describedby="finalize-help" @click="finalize">Finalizar revisão</button>
      <p id="finalize-help" class="muted small">Disponível quando todas as operações tiverem decisão. Aplica o conjunto aprovado inteiro ou nada.</p>
    </div>
    <p class="status small" role="status" aria-live="polite">{{ status }}</p>
  </section>
</template>
