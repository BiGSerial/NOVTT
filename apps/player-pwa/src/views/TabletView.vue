<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { ApiError } from '../api/client'
import BrandMark from '../components/BrandMark.vue'
import DiceTray from '../components/DiceTray.vue'
import LinkBadge from '../components/live/LinkBadge.vue'
import ResultCard from '../components/live/ResultCard.vue'
import Toasts from '../components/live/Toasts.vue'
import { campaigns, devices } from '../live/api'
import { applyTheme, loadTheme } from '../live/theme'
import { useLiveTable } from '../live/useLiveTable'
import TabletBinder from './master/TabletBinder.vue'
import { go, loadMe, me } from '../live/user'
import type { Campaign } from '../live/types'

/**
 * The master's tablet (ADR 0011, SPEC-MVP-002 LT-08): pairing by one-time code, then a binder of
 * every player and NPC sheet with big touch controls. "Seguir a mesa" opens whatever the master
 * selects on the main screen (private workspace channel, never sent to players).
 */
const props = defineProps<{ campaignId?: string }>()

// ---------- Pairing ----------
const label = ref('Tablet da mesa')
const code = ref('')
const expires = ref(0)
const now = ref(Date.now())
const pairError = ref('')
let poll: ReturnType<typeof setInterval> | undefined
let tick: ReturnType<typeof setInterval> | undefined
let pairing: { id: string; secret: string } | null = null
onBeforeUnmount(() => { clearInterval(poll); clearInterval(tick) })

async function startPairing(): Promise<void> {
  pairError.value = ''
  try {
    const p = await devices.start(label.value.trim() || 'Tablet')
    // The claim secret stays in memory only; the code on screen is useless without it.
    pairing = { id: p.pairing_id, secret: p.claim_secret }
    code.value = p.code
    expires.value = Date.parse(p.expires_at)
    clearInterval(poll)
    clearInterval(tick)
    tick = setInterval(() => { now.value = Date.now() }, 1000)
    poll = setInterval(() => void claim(), 2000)
  } catch {
    pairError.value = 'Não foi possível gerar um código agora.'
  }
}
async function claim(): Promise<void> {
  if (!pairing) return
  if (Date.now() > expires.value) {
    clearInterval(poll)
    code.value = ''
    pairError.value = 'O código expirou. Gere outro.'
    return
  }
  try {
    const r = await devices.claim(pairing.id, pairing.secret)
    if (r.status === 'claimed') {
      clearInterval(poll)
      clearInterval(tick)
      pairing = null
      await loadMe()
      await loadCampaigns()
    }
  } catch (e) {
    if (e instanceof ApiError && e.status === 410) { clearInterval(poll); code.value = ''; pairError.value = 'O código expirou. Gere outro.' }
  }
}
const left = computed(() => Math.max(0, Math.ceil((expires.value - now.value) / 1000)))

// ---------- Campaign choice ----------
const mastered = ref<Campaign[]>([])
async function loadCampaigns(): Promise<void> {
  mastered.value = (await campaigns.list()).data.filter(c => c.role === 'master')
  if (!props.campaignId && mastered.value.length === 1) go(`#/tablet/${mastered.value[0].id}`)
}
onMounted(() => { if (me.value && !props.campaignId) void loadCampaigns() })

// ---------- Binder ----------
const live = props.campaignId ? useLiveTable(props.campaignId) : null
const s = computed(() => live?.state.value?.s ?? null)
const campaign = ref<Campaign | null>(null)
const follow = ref(true)

onMounted(async () => {
  if (!props.campaignId || !live) return
  if (!me.value) return
  applyTheme(loadTheme(props.campaignId))
  campaign.value = (await campaigns.list().catch(() => ({ data: [] as Campaign[] }))).data.find(c => c.id === props.campaignId) ?? null
  await live.start(me.value.id, 'master')
})
watch(() => me.value, m => { if (m && !props.campaignId) void loadCampaigns() })
</script>

<template>
  <div class="tablet">
    <!-- Pairing -->
    <main v-if="!me" class="pairing">
      <BrandMark tagline />
      <h1>Parear este tablet com o mestre</h1>
      <template v-if="!code">
        <label>Nome deste aparelho <input v-model="label" maxlength="60" /></label>
        <button type="button" class="primary" @click="startPairing">Mostrar código</button>
      </template>
      <template v-else>
        <p class="pair-code" aria-live="polite" :aria-label="`Código ${code.split('').join(' ')}`">{{ code.slice(0, 3) }} {{ code.slice(3) }}</p>
        <p>Na mesa do mestre, toque em <strong>Parear tablet</strong> e digite este código.</p>
        <p class="muted small">Expira em {{ left }} s. O código sozinho não dá acesso a nada.</p>
      </template>
      <p v-if="pairError" class="error" role="alert">{{ pairError }}</p>
      <p class="muted small">Já é o mestre neste aparelho? <a href="#/">Entrar com email</a></p>
    </main>

    <!-- Choose a campaign -->
    <main v-else-if="!campaignId" class="pairing">
      <BrandMark />
      <h1>Fichário do mestre</h1>
      <p class="muted">Pareado como {{ me.name }}{{ me.paired_device_id ? ' (tablet)' : '' }}.</p>
      <ul class="campaign-list"><li v-for="c in mastered" :key="c.id"><a class="button primary" :href="`#/tablet/${c.id}`">{{ c.name }}</a></li></ul>
      <p v-if="!mastered.length" class="muted">Nenhuma campanha em que você é mestre.</p>
    </main>

    <!-- Binder -->
    <template v-else-if="live">
      <header class="topbar">
        <a href="#/tablet" class="brand-link"><BrandMark /></a>
        <div class="session-name"><p class="eyebrow">Fichário</p><strong>{{ s?.session.title ?? '—' }}</strong></div>
        <span class="spacer" />
        <label class="check follow"><input v-model="follow" type="checkbox" /> Seguir a mesa</label>
        <LinkBadge :state="live.link.value" />
      </header>
      <p v-if="live.phase.value === 'waiting'" class="panel muted">Nenhuma sessão aberta. Comece a sessão na mesa do mestre.</p>
      <p v-else-if="live.phase.value === 'error'" class="error panel" role="alert">Não foi possível abrir o fichário.</p>
      <TabletBinder v-else-if="s && campaign && me" :table="live" :campaign="campaign" :follow="follow" :me-id="me.id" />
      <DiceTray surface="overlay" persist :record="live.dice.value" @settled="live.settled" />
      <ResultCard :roll="live.card.value" @close="live.dismissCard" />
      <Toasts :items="live.toasts.value" />
    </template>
  </div>
</template>
