<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { ApiError } from '../api/client'
import BrandMark from '../components/BrandMark.vue'
import DiceTray from '../components/DiceTray.vue'
import LinkBadge from '../components/live/LinkBadge.vue'
import LiveTracks from '../components/live/LiveTracks.vue'
import ResultCard from '../components/live/ResultCard.vue'
import RollComposer from '../components/live/RollComposer.vue'
import SheetView from '../components/live/SheetView.vue'
import Toasts from '../components/live/Toasts.vue'
import { campaigns, devices, rolls, session as sessionApi, table as tableApi } from '../live/api'
import { applyTheme, loadTheme } from '../live/theme'
import { useLiveTable } from '../live/useLiveTable'
import { useSheets } from '../live/useSheets'
import { go, loadMe, me } from '../live/user'
import type { Campaign, DiceChoice, ResourceTrack } from '../live/types'

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
const sheets = props.campaignId ? useSheets(props.campaignId) : null
const s = computed(() => live?.state.value?.s ?? null)
const sel = ref<{ kind: 'character' | 'npc'; id: string } | null>(null)
const mode = ref<'sheets' | 'initiative'>('sheets')
const follow = ref(true)
const paths = ref<string[]>([])
const busy = ref(false)
let stopFollow: (() => void) | null = null

onMounted(async () => {
  if (!props.campaignId || !live) return
  if (!me.value) return
  applyTheme(loadTheme(props.campaignId))
  await live.start(me.value.id, 'master')
  const first = s.value?.characters[0]
  if (first) await open('character', first.id)
  const rt = live.realtime()
  if (rt) stopFollow = rt.listenWhisper(`workspace.${me.value.id}`, 'select', data => {
    if (follow.value && (data.kind === 'character' || data.kind === 'npc') && typeof data.id === 'string') void open(data.kind, data.id)
  })
})
onBeforeUnmount(() => stopFollow?.())

const subject = computed(() => {
  if (!sel.value || !s.value) return null
  const list = sel.value.kind === 'character' ? s.value.characters : s.value.npcs
  const f = list.find(x => x.id === sel.value!.id)
  return f ? { ...sel.value, name: f.name, live: f.live, player: 'player_user_id' in f ? (f as { player_user_id: string }).player_user_id : null } : null
})
const sheet = computed(() => (sel.value && sheets ? sheets.byId.get(sel.value.id) : undefined))

async function open(kind: 'character' | 'npc', id: string): Promise<void> {
  if (!s.value || !sheets) return
  if (sel.value?.id !== id) paths.value = []
  sel.value = { kind, id }
  mode.value = 'sheets'
  const preset = (kind === 'character' ? s.value.characters : s.value.npcs).find(x => x.id === id)?.preset_version_id
  if (preset) await (kind === 'character' ? sheets.character(id, preset) : sheets.npc(id, preset)).catch(() => undefined)
}
function toggle(p: string): void { paths.value = paths.value.includes(p) ? paths.value.filter(x => x !== p) : [...paths.value, p] }

function fail(e: unknown): void {
  live?.toast(e instanceof ApiError && e.code.endsWith('revision_mismatch') ? 'Mudou em outra tela; tente de novo.' : 'Não deu certo. Tente de novo.')
  void live?.state.value?.catchUp()
}
async function saveLive(resources: ResourceTrack[], conditions: string[]): Promise<void> {
  const subj = subject.value
  if (!subj || !s.value || !props.campaignId) return
  busy.value = true
  try { live!.state.value!.local.live(subj.kind, subj.id, (await tableApi.live(props.campaignId, subj.kind, subj.id, subj.live?.revision ?? 0, resources, conditions)).live) } catch (e) { fail(e) } finally { busy.value = false }
}
async function submit(input: { paths: string[]; modifier: number; dice: DiceChoice; attempts: number; secret: boolean; allows_choice: boolean; label: string }): Promise<void> {
  const subj = subject.value
  if (!subj || !s.value) return
  busy.value = true
  try {
    if (subj.kind === 'character') {
      live!.state.value!.local.request(await rolls.request(s.value.session.id, { ...input, campaign_character_id: subj.id }))
      live!.toast(`Pedido enviado a ${subj.name}`)
    } else {
      await rolls.npc(s.value.session.id, { paths: input.paths, modifier: input.modifier, dice: input.dice, label: input.label, secret: input.secret, campaign_npc_id: subj.id })
    }
    paths.value = []
  } catch (e) { fail(e) } finally { busy.value = false }
}
const whisper = ref('')
async function sendWhisper(): Promise<void> {
  const subj = subject.value
  if (!subj?.player || !whisper.value.trim() || !props.campaignId) return
  try { live!.state.value!.local.whisper(await tableApi.whisper(props.campaignId, subj.player, whisper.value.trim())); whisper.value = ''; live!.toast('Sussurro enviado') } catch (e) { fail(e) }
}
async function showNpc(): Promise<void> {
  const subj = subject.value
  if (!subj || subj.kind !== 'npc' || !s.value) return
  try { await tableApi.revealNpc(s.value.session.id, subj.id, ''); live!.toast(`${subj.name} apresentado à mesa`) } catch (e) { fail(e) }
}
async function nextTurn(): Promise<void> {
  if (!s.value) return
  const e = s.value.session.initiative?.entries ?? []
  if (!e.length) return
  const turn = (s.value.session.initiative.turn ?? 0) + 1
  try { live!.state.value!.local.session(await sessionApi.initiative(s.value.session.id, s.value.session.revision, e.map(x => ({ kind: x.kind, id: x.id })), turn % e.length, (s.value.session.initiative.round ?? 1) + (turn >= e.length ? 1 : 0))) } catch (er) { fail(er) }
}
const pct = (l: { resources: ResourceTrack[] } | null) => { const r = l?.resources[0]; return r ? `${Math.max(0, Math.min(100, (r.current / r.max) * 100))}%` : '0%' }
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
        <div class="segmented-row" role="group" aria-label="Modo">
          <button type="button" :class="{ on: mode === 'sheets' }" :aria-pressed="mode === 'sheets'" @click="mode = 'sheets'">Fichas</button>
          <button type="button" :class="{ on: mode === 'initiative' }" :aria-pressed="mode === 'initiative'" @click="mode = 'initiative'">Iniciativa</button>
        </div>
        <label class="check follow"><input v-model="follow" type="checkbox" /> Seguir a mesa</label>
        <LinkBadge :state="live.link.value" />
      </header>
      <p v-if="live.phase.value === 'waiting'" class="panel muted">Nenhuma sessão aberta. Comece a sessão na mesa do mestre.</p>
      <p v-else-if="live.phase.value === 'error'" class="error panel" role="alert">Não foi possível abrir o fichário.</p>
      <div v-else-if="s" class="binder">
        <nav class="binder-list" aria-label="Personagens">
          <p class="eyebrow">Jogadores</p>
          <button v-for="c in s.characters" :key="c.id" type="button" class="binder-item" :class="{ on: sel?.id === c.id }" @click="open('character', c.id)">
            <span>{{ c.name }}</span><span class="mini-bar"><span :style="{ width: pct(c.live) }" /></span>
          </button>
          <p class="eyebrow">PdMs e ameaças</p>
          <button v-for="n in s.npcs" :key="n.id" type="button" class="binder-item npc" :class="{ on: sel?.id === n.id }" @click="open('npc', n.id)">
            <span>{{ n.name }}</span><span class="mini-bar"><span :style="{ width: pct(n.live) }" /></span>
          </button>
        </nav>
        <section v-if="mode === 'initiative'" class="binder-sheet">
          <h2>Iniciativa · rodada {{ s.session.initiative?.round ?? 1 }}</h2>
          <ol class="initiative big">
            <li v-for="(e, i) in s.session.initiative?.entries ?? []" :key="e.id" :class="{ turn: i === (s.session.initiative.turn ?? 0) }"><button type="button" class="link" @click="open(e.kind, e.id)">{{ e.name }}</button></li>
          </ol>
          <p v-if="!(s.session.initiative?.entries?.length)" class="muted">Monte a iniciativa na mesa do mestre.</p>
          <button type="button" class="primary" @click="nextTurn">Próximo turno</button>
        </section>
        <section v-else-if="subject" class="binder-sheet">
          <h2>{{ subject.name }} <span class="muted small">{{ subject.kind === 'npc' ? 'PdM' : 'Jogador' }}</span></h2>
          <LiveTracks :live="subject.live" editable :busy="busy" @save="saveLive" />
          <SheetView v-if="sheet" :sections="sheet.sections" :selected="paths" selectable @toggle="toggle" />
          <RollComposer v-if="sheet" :subject-name="subject.name" :subject-kind="subject.kind" :fields="sheet.numeric" :selected="paths" :busy="busy || s.session.status !== 'active'" @toggle="toggle" @submit="submit" />
          <form v-if="subject.player" class="row" @submit.prevent="sendWhisper"><input v-model="whisper" maxlength="2000" :placeholder="`Sussurrar a ${subject.name}`" aria-label="Sussurro" /><button type="submit" class="small-btn">Sussurrar</button></form>
          <button v-else type="button" class="small-btn" @click="showNpc">Mostrar aos jogadores (só nome)</button>
        </section>
      </div>
      <DiceTray surface="overlay" :record="live.dice.value" @settled="live.settled" />
      <ResultCard :roll="live.card.value" @close="live.dismissCard" />
      <Toasts :items="live.toasts.value" />
    </template>
  </div>
</template>
