<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ApiError } from '../../api/client'
import { t } from '../../i18n/messages'
import { ACTIVE_REQUEST, review, STATUS_LABEL, type CampaignCharacterView, type ChangeRequestView, type PresetVersionFull } from '../../live/campaign'
import ProposalReview from './ProposalReview.vue'

/** One character for the master: creation progress, balances, the active proposal, grants, activation. */
const props = defineProps<{ character: CampaignCharacterView; preset: PresetVersionFull; labels: Map<string, string> }>()
const emit = defineEmits<{ changed: [] }>()

const balances = ref<Record<string, number>>({})
const request = ref<ChangeRequestView | null>(null)
const status = ref('')
const busy = ref(false)
const grantBudget = ref('')
const grantAmount = ref(1)
const grantReason = ref('')
let grantKey: string | null = null

const budgets = computed(() => props.preset.rules.budgets ?? [])
const budgetLabels = computed(() => Object.fromEntries(budgets.value.map(b => [b.id, b.label])))
const stepLabels = computed(() => Object.fromEntries((props.preset.rules.creation?.steps ?? []).map(s => [s.id, s.label])))
const canActivate = computed(() => props.character.status !== 'approved' && props.character.sheet_revision > 0 && !request.value)

async function load(): Promise<void> {
  try {
    const [g, list] = await Promise.all([review.grants(props.character.id), review.requests(props.character.id)])
    balances.value = g.balances
    const active = list.data.find(r => ACTIVE_REQUEST.includes(r.status))
    request.value = active ? await review.request(active.id) : null
    grantBudget.value ||= budgets.value.find(b => b.kind === 'experience')?.id ?? budgets.value[0]?.id ?? ''
  } catch (e) { status.value = t(e instanceof ApiError ? e.code : 'network.error') }
}

onMounted(load)

async function activate(): Promise<void> {
  busy.value = true
  status.value = ''
  try {
    await review.approve(props.character.id)
    status.value = 'Personagem ativado.'
    emit('changed')
  } catch (e) { status.value = t(e instanceof ApiError ? e.code : 'network.error') } finally { busy.value = false }
}

async function grant(): Promise<void> {
  if (!grantBudget.value || !Number.isInteger(grantAmount.value) || grantAmount.value === 0) { status.value = 'Informe um valor diferente de zero.'; return }
  busy.value = true
  status.value = ''
  grantKey ??= crypto.randomUUID()
  try {
    await review.grant(props.character.id, grantBudget.value, grantAmount.value, grantReason.value.trim(), grantKey)
    grantKey = null
    grantReason.value = ''
    status.value = `${grantAmount.value > 0 ? 'Concedido' : 'Retirado'}: ${Math.abs(grantAmount.value)} em ${budgetLabels.value[grantBudget.value] ?? grantBudget.value}. O jogador decide onde gastar.`
    await load()
  } catch (e) {
    status.value = t(e instanceof ApiError ? e.code : 'network.error')
    if (e instanceof ApiError && e.status !== 0) grantKey = null
  } finally { busy.value = false }
}

function finalized(): void {
  request.value = null
  emit('changed')
  void load()
}
</script>

<template>
  <article class="character-review parchment-panel" :aria-labelledby="`cr-${character.id}`">
    <header class="review-head">
      <h2 :id="`cr-${character.id}`">{{ character.name }}</h2>
      <span class="status-pill" :class="character.status">{{ STATUS_LABEL[character.status] }}</span>
      <span class="muted small">revisão {{ character.sheet_revision }}</span>
    </header>

    <ol v-if="character.creation" class="step-track" aria-label="Etapas da criação">
      <li v-for="s in character.creation.steps" :key="s.id" :class="{ done: s.complete }">
        <span aria-hidden="true">{{ s.complete ? '✔' : '○' }}</span> {{ stepLabels[s.id] ?? s.id }}
        <span class="sr-only">{{ s.complete ? 'completa' : 'incompleta' }}</span>
      </li>
    </ol>

    <dl v-if="Object.keys(balances).length" class="balance-row" aria-label="Saldos">
      <div v-for="b in budgets.filter(b => b.id in balances)" :key="b.id"><dt>{{ b.label }}</dt><dd>{{ balances[b.id] }}</dd></div>
    </dl>

    <ProposalReview
      v-if="request"
      :request="request"
      :sheet="character.sheet"
      :labels="labels"
      :budget-labels="budgetLabels"
      @updated="v => (request = v)"
      @finalized="finalized"
    />
    <p v-else class="muted small">Nenhuma proposta em análise.</p>

    <div class="card-actions">
      <button v-if="character.status !== 'approved'" type="button" class="primary" :disabled="busy || !canActivate" @click="activate">Ativar personagem</button>
      <a class="button" :href="`#/personagens/${character.id}`">Abrir ficha</a>
    </div>

    <form v-if="budgets.length" class="grant-form" @submit.prevent="grant">
      <p class="eyebrow">Conceder pontos</p>
      <label class="field">Recurso
        <select v-model="grantBudget">
          <option v-for="b in budgets" :key="b.id" :value="b.id">{{ b.label }}</option>
        </select>
      </label>
      <label class="field">Quantidade
        <input v-model.number="grantAmount" type="number" step="1" min="-10000" max="10000" />
      </label>
      <label class="field grow">Motivo (opcional)
        <input v-model="grantReason" type="text" maxlength="1000" />
      </label>
      <button type="submit" class="ghost" :disabled="busy">Conceder</button>
    </form>
    <p class="status small" role="status" aria-live="polite">{{ status }}</p>
  </article>
</template>
