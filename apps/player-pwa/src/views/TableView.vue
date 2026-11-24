<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
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
import type { Campaign, CarryOver, Roteiro } from '../live/types'
import Portrait from '../components/live/Portrait.vue'
import { meApi, type Companion } from '../live/me'

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
// The lobby: who of this campaign is around, the last session and what was left open.
const companions = ref<Companion[]>([])
const carry = ref<CarryOver | null>(null)
const here = computed(() => companions.value.filter(p => p.campaigns.some(c => c.id === props.campaignId)))
const online = computed(() => here.value.filter(p => p.online))
let lobbyTimer: ReturnType<typeof setInterval> | undefined
async function refreshLobby(): Promise<void> { try { companions.value = (await meApi.companions()).data } catch { /* presence is a nicety */ } }
const scenesOf = (r: Roteiro) => r.content.scenes?.length ?? 0
const openingOf = (r: Roteiro) => r.content.scenes?.[0]?.title ?? ''
function when(iso: string): string {
  const days = Math.floor((Date.now() - new Date(iso).getTime()) / 86_400_000)
  return days <= 0 ? 'hoje' : days === 1 ? 'ontem' : `há ${days} dias`
}

onMounted(async () => {
  if (!me.value) return go('#/')
  applyTheme(loadTheme(props.campaignId))
  try {
    campaign.value = (await campaigns.list()).data.find(c => c.id === props.campaignId) ?? null
  } catch { /* table.start reports errors */ }
  if (!campaign.value) return go('#/campanhas')
  await table.start(me.value.id, campaign.value.role)
  if (table.phase.value === 'waiting') {
    void refreshLobby()
    lobbyTimer = setInterval(() => { if (table.phase.value === 'waiting' && !document.hidden) void refreshLobby() }, 30_000)
    if (isMaster.value) world.carryOver(props.campaignId).then(c => { carry.value = c }).catch(() => undefined)
  }
  if (isMaster.value && table.phase.value === 'waiting') {
    roteiros.value = (await world.roteiros(props.campaignId).catch(() => ({ data: [] }))).data
    roteiroId.value = roteiros.value[0]?.id ?? null
    title.value = roteiros.value[0]?.title ?? 'Sessão'
  }
})

onBeforeUnmount(() => clearInterval(lobbyTimer))

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
      <div v-else class="lobby">
        <section class="lobby-main panel">
          <p class="eyebrow">{{ campaign?.name }}</p>
          <template v-if="isMaster">
            <h1>Abrir a sessão</h1>
            <label class="lobby-title">Título da sessão <input v-model="title" maxlength="120" /></label>
            <fieldset class="choice-set">
              <legend>Começar pelo roteiro</legend>
              <div class="roteiro-tiles" role="radiogroup" aria-label="Roteiro">
                <button v-for="r in roteiros" :key="r.id" type="button" role="radio" class="roteiro-tile" :class="{ on: roteiroId === r.id }" :aria-checked="roteiroId === r.id" @click="roteiroId = r.id; title = r.title">
                  <b>{{ r.title }}</b>
                  <small>{{ scenesOf(r) }} cena(s)<template v-if="openingOf(r)"> · abre em “{{ openingOf(r) }}”</template></small>
                </button>
                <button type="button" role="radio" class="roteiro-tile blank" :class="{ on: roteiroId === null }" :aria-checked="roteiroId === null" @click="roteiroId = null">
                  <b>Sem roteiro</b><small>Improvisar com a bússola em branco</small>
                </button>
              </div>
              <p class="muted small">O roteiro preenche a cena, os relógios e as pistas. <a :href="`#/roteiro/${campaignId}`">Preparar um roteiro</a> · <a :href="`#/atlas/${campaignId}`">Atlas</a></p>
            </fieldset>
            <p v-if="startError" class="error" role="alert">{{ startError }}</p>
            <button type="button" class="primary lobby-start" :disabled="starting" @click="start">{{ starting ? 'Abrindo…' : 'Começar a sessão' }}</button>
          </template>
          <template v-else>
            <h1>Aguardando o mestre</h1>
            <p class="muted">Esta tela entra na sessão sozinha quando o mestre começar. Enquanto isso, confira sua ficha.</p>
            <p><a class="button" href="#/personagens">Seus personagens</a></p>
          </template>
        </section>

        <aside class="lobby-side">
          <section class="panel lobby-card">
            <h2>Quem está por aqui</h2>
            <p class="muted small">{{ online.length }} de {{ here.length }} online agora</p>
            <ul class="lobby-people">
              <li v-for="p in here" :key="p.user_id" :class="{ online: p.online }">
                <span class="friend-avatar"><Portrait :name="p.name" :size="34" :ring="false" /><span class="presence-dot" /></span>
                <span>{{ p.name }}<small v-if="p.campaigns.find(c => c.id === campaignId)?.role === 'master'"> · mestre</small></span>
              </li>
              <li v-if="!here.length" class="muted small">Ninguém mais nesta campanha ainda.</li>
            </ul>
          </section>
          <section v-if="campaign?.last_session" class="panel lobby-card">
            <h2>Última sessão</h2>
            <p><b>{{ campaign.last_session.title }}</b> · {{ when(campaign.last_session.started_at) }}</p>
          </section>
          <section v-if="isMaster && carry && (carry.unrevealed_clues.length || carry.open_threads.length)" class="panel lobby-card">
            <h2>Ficou em aberto</h2>
            <ul class="lobby-open">
              <li v-for="c in carry.unrevealed_clues.slice(0, 4)" :key="c.title">🗝 {{ c.title }}<small v-if="c.scene"> · {{ c.scene }}</small></li>
              <li v-for="t in carry.open_threads.slice(0, 4)" :key="t.id">🧵 {{ t.title }}</li>
            </ul>
            <p v-if="carry.unrevealed_clues.length + carry.open_threads.length > 8" class="muted small">e mais no Atlas.</p>
          </section>
        </aside>
      </div>
    </main>
    <DiceTray surface="overlay" persist :record="table.dice.value" :feedback="{ sound: true, haptics: true }" @settled="table.settled" />
    <ResultCard :roll="table.card.value" :me-id="me?.id" @close="table.dismissCard" />
    <Toasts :items="table.toasts.value" />
  </div>
</template>
