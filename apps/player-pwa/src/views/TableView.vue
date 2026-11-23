<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import DiceTray from '../components/DiceTray.vue'
import LinkBadge from '../components/live/LinkBadge.vue'
import ResultCard from '../components/live/ResultCard.vue'
import Toasts from '../components/live/Toasts.vue'
import BrandMark from '../components/BrandMark.vue'
import MasterTable from './MasterTable.vue'
import PlayerTable from './PlayerTable.vue'
import { ApiError } from '../api/client'
import { campaigns, world } from '../live/api'
import { applyTheme, loadTheme } from '../live/theme'
import { useLiveTable } from '../live/useLiveTable'
import { go, me } from '../live/user'
import type { Campaign, Roteiro } from '../live/types'

/** The live table of one campaign: the master's console or the player's phone, by role. */
const props = defineProps<{ campaignId: string }>()
const table = useLiveTable(props.campaignId)
const campaign = ref<Campaign | null>(null)
const roteiros = ref<Roteiro[]>([])
const title = ref('Sessão')
const roteiroId = ref<string | null>(null)
const starting = ref(false)
const startError = ref('')
const isMaster = computed(() => campaign.value?.role === 'master')

onMounted(async () => {
  if (!me.value) return go('#/')
  applyTheme(loadTheme(props.campaignId))
  try {
    campaign.value = (await campaigns.list()).data.find(c => c.id === props.campaignId) ?? null
  } catch { /* table.start reports errors */ }
  if (!campaign.value) return go('#/campanhas')
  await table.start(me.value.id, campaign.value.role)
  if (isMaster.value && table.phase.value === 'waiting') {
    roteiros.value = (await world.roteiros(props.campaignId).catch(() => ({ data: [] }))).data
    roteiroId.value = roteiros.value[0]?.id ?? null
    title.value = roteiros.value[0]?.title ?? 'Sessão'
  }
})

async function start(): Promise<void> {
  starting.value = true
  startError.value = ''
  try {
    await campaigns.start(props.campaignId, title.value.trim() || 'Sessão', roteiroId.value)
    await table.reload()
  } catch (e) {
    startError.value = e instanceof ApiError && e.code === 'session.already_open' ? 'Já existe uma sessão aberta.' : 'Não foi possível iniciar a sessão.'
  } finally {
    starting.value = false
  }
}
</script>

<template>
  <div class="table-shell">
    <template v-if="table.phase.value === 'ready' && table.state.value">
      <MasterTable v-if="table.state.value.isMaster" :table="table" :campaign="campaign!" />
      <PlayerTable v-else :table="table" :campaign="campaign!" />
    </template>
    <main v-else class="page wide waiting">
      <header class="topbar">
        <a href="#/campanhas" class="brand-link"><BrandMark /></a>
        <span class="spacer" />
        <LinkBadge :state="table.link.value" />
      </header>
      <p v-if="table.phase.value === 'loading'" role="status">Abrindo a mesa…</p>
      <p v-else-if="table.phase.value === 'error'" class="error" role="alert">Não foi possível abrir a mesa ({{ table.error.value }}).</p>
      <section v-else-if="isMaster" class="panel start-panel">
        <p class="eyebrow">{{ campaign?.name }}</p>
        <h1>Abrir a sessão</h1>
        <label>Título <input v-model="title" maxlength="120" /></label>
        <label>Começar pelo roteiro
          <select v-model="roteiroId">
            <option :value="null">Sem roteiro (bússola em branco)</option>
            <option v-for="r in roteiros" :key="r.id" :value="r.id">{{ r.title }}</option>
          </select>
        </label>
        <p class="muted small">O roteiro preenche a cena, os relógios e as pistas da sessão.</p>
        <p v-if="startError" class="error" role="alert">{{ startError }}</p>
        <button type="button" class="primary" :disabled="starting" @click="start">Começar a sessão</button>
        <p class="muted small"><a :href="`#/roteiro/${campaignId}`">Preparar um roteiro</a> · <a :href="`#/atlas/${campaignId}`">Atlas</a></p>
      </section>
      <section v-else class="panel start-panel">
        <p class="eyebrow">{{ campaign?.name }}</p>
        <h1>Aguardando o mestre</h1>
        <p class="muted">Esta tela entra na sessão sozinha quando o mestre começar.</p>
      </section>
    </main>
    <DiceTray surface="overlay" persist :record="table.dice.value" :feedback="{ sound: true, haptics: true }" @settled="table.settled" />
    <ResultCard :roll="table.card.value" :me-id="me?.id" @close="table.dismissCard" />
    <Toasts :items="table.toasts.value" />
  </div>
</template>
