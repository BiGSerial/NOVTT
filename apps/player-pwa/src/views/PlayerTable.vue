<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { ApiError } from '../api/client'
import BrandMark from '../components/BrandMark.vue'
import LinkBadge from '../components/live/LinkBadge.vue'
import LiveTracks from '../components/live/LiveTracks.vue'
import RequestCard from '../components/live/RequestCard.vue'
import RollFeed from '../components/live/RollFeed.vue'
import SheetView from '../components/live/SheetView.vue'
import { rolls, table as tableApi } from '../live/api'
import type { useLiveTable } from '../live/useLiveTable'
import { useSheets } from '../live/useSheets'
import type { Campaign, OfficialRoll, RollRequest } from '../live/types'

/**
 * The player's phone (SPEC-MVP-002, PWA): the master's requests on top with locked dice, the
 * character sheet to compose a combination and suggest it, the table (initiative, who is here,
 * revealed NPCs), the diary (whispers and clues) and the roll feed.
 */
const props = defineProps<{ table: ReturnType<typeof useLiveTable>; campaign: Campaign }>()
const t = computed(() => props.table.state.value!)
const s = computed(() => t.value.s)
const me = computed(() => s.value.me.user_id)
const sheets = useSheets(props.campaign.id)
const tab = ref<'sheet' | 'table' | 'diary' | 'rolls'>('sheet')
const busy = ref(false)

const mine = computed(() => s.value.characters.filter(c => c.controlled_by === me.value))
const charId = ref<string | null>(null)
watch(mine, m => { if (!charId.value && m[0]) charId.value = m[0].id }, { immediate: true })
const character = computed(() => mine.value.find(c => c.id === charId.value) ?? null)
const sheet = computed(() => (charId.value ? sheets.byId.get(charId.value) : undefined))
onMounted(() => { if (character.value) void sheets.character(character.value.id, character.value.preset_version_id) })
watch(charId, id => { const c = mine.value.find(x => x.id === id); if (c) void sheets.character(c.id, c.preset_version_id) })

const myRequests = computed(() => s.value.requests.filter(r => r.roller_user_id === me.value))
const otherRequests = computed(() => s.value.requests.filter(r => r.roller_user_id !== me.value))
const master = computed(() => s.value.members.find(m => m.role === 'master'))
const diary = computed(() => s.value.whispers)
const unread = ref(0)
watch(() => s.value.whispers.length, (n, o) => { if (n > (o ?? n) && tab.value !== 'diary') unread.value += n - (o ?? n) })
watch(tab, v => { if (v === 'diary') unread.value = 0 })

function fail(e: unknown): void {
  const code = e instanceof ApiError ? e.code : ''
  const known: Record<string, string> = {
    'roll.revision_mismatch': 'O mestre mudou o pedido; veja a versão nova.',
    'roll.request_not_open': 'Esse pedido não está mais aberto.',
    'session.paused': 'A sessão está pausada.',
    'suggestion.too_many': 'Espere o mestre responder às suas sugestões.',
    'network.error': 'Sem conexão. Toque de novo: o servidor não rola duas vezes.',
  }
  props.table.toast(known[code] ?? 'Não deu certo. Tente de novo.')
  void t.value.catchUp()
}

async function roll(request: RollRequest, key: string, count?: number): Promise<void> {
  busy.value = true
  try {
    const result: OfficialRoll = await rolls.roll(request.id, request.revision, key, count)
    if (t.value.local.roll(result)) props.table.enqueue(result)
  } catch (e) {
    fail(e)
  } finally {
    busy.value = false
  }
}

// Composing a combination to suggest (D1): never an official roll by itself.
const picked = ref<string[]>([])
const note = ref('')
function toggle(path: string): void {
  picked.value = picked.value.includes(path) ? picked.value.filter(p => p !== path) : [...picked.value, path].slice(-4)
}
const pickedLabel = computed(() => sheet.value?.numeric.filter(f => picked.value.includes(f.path)).map(f => `${f.label} ${f.value}`).join(' + ') ?? '')
async function suggest(): Promise<void> {
  if (!character.value || !picked.value.length) return
  try {
    await rolls.suggest(s.value.session.id, character.value.id, picked.value, note.value.trim())
    props.table.toast('Sugestão enviada ao mestre')
    picked.value = []
    note.value = ''
  } catch (e) { fail(e) }
}

const reply = ref('')
async function send(): Promise<void> {
  const body = reply.value.trim()
  if (!body || !master.value) return
  try { t.value.local.whisper(await tableApi.whisper(props.campaign.id, master.value.user_id, body)); reply.value = '' } catch (e) { fail(e) }
}
const clues = computed(() => s.value.clues.filter(c => c.revealed_at))
const turn = computed(() => s.value.session.initiative?.entries?.[s.value.session.initiative.turn ?? 0])
</script>

<template>
  <div class="player" :class="{ paused: s.session.status === 'paused' }">
    <header class="topbar player-bar">
      <a href="#/campanhas" class="brand-link" aria-label="Campanhas"><BrandMark /></a>
      <div class="session-name"><p class="eyebrow">{{ s.session.title }}</p><strong>{{ character?.name ?? 'Sem personagem' }}</strong></div>
      <span class="spacer" />
      <LinkBadge :state="table.link.value" />
    </header>
    <p v-if="s.session.status === 'paused'" class="banner">Sessão pausada pelo mestre.</p>
    <p v-if="turn" class="turn-banner" :class="{ mine: turn.id === charId }">{{ turn.id === charId ? 'É a sua vez!' : `Vez de ${turn.name}` }}</p>

    <section v-if="myRequests.length" class="requests" aria-label="Pedidos do mestre">
      <RequestCard v-for="r in myRequests" :key="r.id" :request="r" :can-roll="s.session.status === 'active'" :busy="busy" @roll="roll" />
    </section>

    <main class="player-main">
      <section v-if="tab === 'sheet'" aria-label="Ficha">
        <select v-if="mine.length > 1" v-model="charId" aria-label="Personagem"><option v-for="c in mine" :key="c.id" :value="c.id">{{ c.name }}</option></select>
        <LiveTracks v-if="character" :live="character.live" />
        <SheetView v-if="sheet" :sections="sheet.sections" :selected="picked" selectable @toggle="toggle" />
        <p v-else-if="character" role="status">Carregando ficha…</p>
        <p v-else class="muted">Você não tem personagem nesta campanha.</p>
        <div v-if="picked.length" class="suggest-bar">
          <p><strong>{{ pickedLabel }}</strong></p>
          <input v-model="note" maxlength="200" placeholder="O que você quer fazer? (opcional)" aria-label="Intenção" />
          <div class="row"><button type="button" class="primary" @click="suggest">Sugerir ao mestre</button><button type="button" class="link" @click="picked = []">Limpar</button></div>
          <p class="muted small">O mestre decide os dados. Para treinar, use os <a href="#/dados" target="_blank" rel="noopener">dados livres</a> (não oficiais).</p>
        </div>
      </section>

      <section v-else-if="tab === 'table'" aria-label="Mesa">
        <h2>Iniciativa</h2>
        <ol v-if="s.session.initiative?.entries?.length" class="initiative">
          <li v-for="(e, i) in s.session.initiative.entries" :key="e.id" :class="{ turn: i === (s.session.initiative.turn ?? 0), me: e.id === charId }">{{ e.name }}</li>
        </ol>
        <p v-else class="muted small">Sem iniciativa agora.</p>
        <h2>Grupo</h2>
        <article v-for="c in s.characters" :key="c.id" class="cast-card"><strong>{{ c.name }}</strong><LiveTracks :live="c.live" /></article>
        <h2 v-if="s.session.revealed_npcs.length">Quem vocês conheceram</h2>
        <article v-for="n in s.session.revealed_npcs" :key="n.id" class="npc-reveal"><strong>{{ n.name }}</strong><p v-if="n.summary" class="small">{{ n.summary }}</p></article>
        <h2 v-if="otherRequests.length">Outros pedidos</h2>
        <RequestCard v-for="r in otherRequests" :key="r.id" :request="r" :can-roll="false" />
      </section>

      <section v-else-if="tab === 'diary'" aria-label="Diário">
        <h2>Pistas</h2>
        <article v-for="c in clues" :key="c.id" class="clue"><p><strong>{{ c.title }}</strong> <span class="pill">{{ c.revealed_to === 'table' ? 'todos' : 'só você' }}</span></p><p class="small">{{ c.body }}</p></article>
        <p v-if="!clues.length" class="muted small">Nenhuma pista ainda.</p>
        <h2>Sussurros com o mestre</h2>
        <ol class="thread">
          <li v-for="w in diary" :key="w.id" :class="{ out: w.sender_user_id === me }"><span v-if="w.kind === 'clue'" class="pill">pista</span>{{ w.body }}</li>
          <li v-if="!diary.length" class="muted small">O que o mestre sussurrar só para você aparece aqui.</li>
        </ol>
        <form class="row" @submit.prevent="send"><input v-model="reply" maxlength="2000" placeholder="Responder só ao mestre" aria-label="Mensagem ao mestre" /><button type="submit" class="small-btn">Enviar</button></form>
      </section>

      <section v-else aria-label="Rolagens"><RollFeed :rolls="s.rolls" @replay="table.enqueue" /></section>
    </main>

    <nav class="bottom-nav" aria-label="Seções">
      <button type="button" :class="{ on: tab === 'sheet' }" :aria-current="tab === 'sheet' ? 'page' : undefined" @click="tab = 'sheet'">Ficha</button>
      <button type="button" :class="{ on: tab === 'table' }" :aria-current="tab === 'table' ? 'page' : undefined" @click="tab = 'table'">Mesa</button>
      <button type="button" :class="{ on: tab === 'diary' }" :aria-current="tab === 'diary' ? 'page' : undefined" @click="tab = 'diary'">Diário<span v-if="unread" class="badge">{{ unread }}</span></button>
      <button type="button" :class="{ on: tab === 'rolls' }" :aria-current="tab === 'rolls' ? 'page' : undefined" @click="tab = 'rolls'">Rolagens</button>
    </nav>
  </div>
</template>
