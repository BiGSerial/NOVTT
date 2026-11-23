<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { ApiError } from '../api/client'
import BrandMark from '../components/BrandMark.vue'
import LinkBadge from '../components/live/LinkBadge.vue'
import LiveTracks from '../components/live/LiveTracks.vue'
import NotesPanel from '../components/live/NotesPanel.vue'
import RollComposer from '../components/live/RollComposer.vue'
import RollFeed from '../components/live/RollFeed.vue'
import SheetView from '../components/live/SheetView.vue'
import { devices, rolls, session as sessionApi, table as tableApi, type RequestInput } from '../live/api'
import { describeDice } from '../live/dice'
import { saveTheme, THEMES, loadTheme, type ThemeId } from '../live/theme'
import type { useLiveTable } from '../live/useLiveTable'
import { useSheets } from '../live/useSheets'
import { plain } from '../live/sheet'
import type { Campaign, Clock, Compass, OfficialRoll, ResourceTrack, RollRequest, Suggestion } from '../live/types'

/**
 * The master's live table (SPEC-MVP-002 LT-04..LT-06): the scene compass on top, the cast on the
 * left, the roll composer and open requests in the middle, rolls / notes / whispers / clues on the
 * right. Every action is one tap; the server is the authority and the view follows its events.
 */
const props = defineProps<{ table: ReturnType<typeof useLiveTable>; campaign: Campaign }>()
const t = computed(() => props.table.state.value!)
const s = computed(() => t.value.s)
const sheets = useSheets(props.campaign.id)
const busy = ref(false)

type Subject = { kind: 'character' | 'npc'; id: string }
const selected = ref<Subject | null>(null)
const paths = ref<string[]>([])
const drawer = ref(false)
const tab = ref<'rolls' | 'notes' | 'whispers' | 'clues'>('rolls')
const theme = ref<ThemeId>(loadTheme(props.campaign.id))
watch(theme, v => saveTheme(props.campaign.id, v))

const subject = computed(() => {
  const sel = selected.value
  if (!sel) return null
  const list = sel.kind === 'character' ? s.value.characters : s.value.npcs
  const found = list.find(x => x.id === sel.id)
  return found ? { ...sel, name: found.name, preset: found.preset_version_id, live: found.live } : null
})
const subjectSheet = computed(() => (selected.value ? sheets.byId.get(selected.value.id) : undefined))
const players = computed(() => s.value.members.filter(m => m.role === 'player'))
const playerName = (id: string) => s.value.members.find(m => m.user_id === id)?.name ?? '—'

function fail(e: unknown, fallback = 'Não deu certo. Tente de novo.'): void {
  const code = e instanceof ApiError ? e.code : ''
  const known: Record<string, string> = {
    'session.revision_mismatch': 'Outra janela mudou a sessão; atualizei para a versão mais nova.',
    'live_state.revision_mismatch': 'Os recursos mudaram em outra janela; tente de novo.',
    'session.paused': 'A sessão está pausada.',
    'roll.component_invalid': 'Essa combinação não existe na ficha.',
    'whisper.recipient_invalid': 'Escolha um jogador para sussurrar.',
  }
  props.table.toast(known[code] ?? fallback)
  if (code.endsWith('revision_mismatch')) void t.value.catchUp()
}

async function select(kind: 'character' | 'npc', id: string, open = false): Promise<void> {
  if (selected.value?.id !== id) paths.value = []
  selected.value = { kind, id }
  drawer.value = open || drawer.value
  const preset = (kind === 'character' ? s.value.characters : s.value.npcs).find(x => x.id === id)?.preset_version_id
  if (preset) await (kind === 'character' ? sheets.character(id, preset) : sheets.npc(id, preset)).catch(() => fail(null, 'Não foi possível abrir a ficha.'))
  // Paired tablets that "follow the table" open the same sheet (ADR 0011; never sent to players).
  props.table.realtime()?.whisper(`workspace.${s.value.me.user_id}`, 'select', { kind, id })
}
function toggle(path: string): void {
  paths.value = paths.value.includes(path) ? paths.value.filter(p => p !== path) : [...paths.value, path]
}

onMounted(() => {
  const first = s.value.characters[0]
  if (first) void select('character', first.id)
})

// ---- Rolls ----
async function request(input: Omit<RequestInput, 'campaign_character_id'>): Promise<void> {
  if (!selected.value) return
  busy.value = true
  try {
    if (selected.value.kind === 'character') {
      const r = await rolls.request(s.value.session.id, { ...input, campaign_character_id: selected.value.id })
      t.value.local.request(r)
      props.table.toast(`Pedido enviado a ${r.subject.name}`)
    } else {
      await rolls.npc(s.value.session.id, { paths: input.paths, modifier: input.modifier, dice: input.dice, label: input.label, secret: input.secret, campaign_npc_id: selected.value.id })
    }
    paths.value = []
  } catch (e) {
    fail(e)
  } finally {
    busy.value = false
  }
}
async function fromSuggestion(sg: Suggestion): Promise<void> {
  await select('character', sg.campaign_character_id)
  paths.value = [...sg.paths]
  suggestionId.value = sg.id
}
const suggestionId = ref<string | null>(null)
async function submitComposer(input: { paths: string[]; modifier: number; dice: import('../live/types').DiceChoice; attempts: number; secret: boolean; allows_choice: boolean; label: string }): Promise<void> {
  const sid = suggestionId.value
  suggestionId.value = null
  await request({ ...input, suggestion_id: sid })
}
async function dismiss(sg: Suggestion): Promise<void> {
  try { await rolls.dismiss(sg.id) } catch (e) { fail(e) }
}
async function cancel(r: RollRequest): Promise<void> {
  try { t.value.local.request(await rolls.cancel(r.id)) } catch (e) { fail(e) }
}
async function grant(r: RollRequest): Promise<void> {
  try { t.value.local.request(await rolls.grant(r.id)) } catch (e) { fail(e) }
}
async function reveal(roll: OfficialRoll): Promise<void> {
  try { await rolls.reveal(roll.id) } catch (e) { fail(e) }
}

// ---- Live state ----
async function saveLive(kind: 'character' | 'npc', id: string, resources: ResourceTrack[], conditions: string[]): Promise<void> {
  const target = (kind === 'character' ? s.value.characters : s.value.npcs).find(x => x.id === id)
  if (!target) return
  try {
    const r = await tableApi.live(props.campaign.id, kind, id, target.live?.revision ?? 0, resources, conditions)
    t.value.local.live(kind, id, r.live)
  } catch (e) { fail(e) }
}

// ---- Compass ----
const compassDraft = reactive<Compass>({ scene: '', objective: '', next_clue: '', clocks: [], spotlight: {} })
watch(() => s.value.session.compass, c => { if (c) Object.assign(compassDraft, plain({ ...c, spotlight: { ...(c.spotlight ?? {}) } })) }, { immediate: true, deep: true })
async function saveCompass(): Promise<void> {
  try {
    const g = await sessionApi.compass(s.value.session.id, s.value.session.revision, { ...compassDraft, spotlight: { ...compassDraft.spotlight } })
    t.value.local.session(g)
  } catch (e) { fail(e) }
}
function clockClick(c: Clock, i: number): void {
  c.filled = c.filled === i + 1 ? i : i + 1
  void saveCompass()
}
function addClock(): void {
  compassDraft.clocks.push({ id: `c${Date.now().toString(36)}`, label: 'Novo relógio', segments: 4, filled: 0 })
  void saveCompass()
}
function removeClock(c: Clock): void {
  compassDraft.clocks = compassDraft.clocks.filter(x => x.id !== c.id)
  void saveCompass()
}
function spotlight(id: string): void {
  compassDraft.spotlight = { ...compassDraft.spotlight, [id]: (compassDraft.spotlight[id] ?? 0) + 1 }
  void saveCompass()
}
const spotlightLow = computed(() => {
  const counts = s.value.characters.map(c => compassDraft.spotlight[c.id] ?? 0)
  const min = Math.min(...counts)
  return new Set(s.value.characters.filter(c => (compassDraft.spotlight[c.id] ?? 0) === min && Math.max(...counts) > min).map(c => c.id))
})

// ---- Initiative ----
const initiative = computed(() => s.value.session.initiative?.entries ?? [])
async function saveInitiative(entries: { kind: 'character' | 'npc'; id: string }[], turn: number, round: number): Promise<void> {
  try { t.value.local.session(await sessionApi.initiative(s.value.session.id, s.value.session.revision, entries, turn, round)) } catch (e) { fail(e) }
}
function initAll(): void {
  void saveInitiative([...s.value.characters.map(c => ({ kind: 'character' as const, id: c.id })), ...s.value.npcs.map(n => ({ kind: 'npc' as const, id: n.id }))], 0, 1)
}
function initMove(i: number, d: number): void {
  const e = initiative.value.map(x => ({ kind: x.kind, id: x.id }))
  const j = i + d
  if (j < 0 || j >= e.length) return
  ;[e[i], e[j]] = [e[j], e[i]]
  void saveInitiative(e, s.value.session.initiative.turn ?? 0, s.value.session.initiative.round ?? 1)
}
function initRemove(i: number): void {
  const e = initiative.value.map(x => ({ kind: x.kind, id: x.id })).filter((_, k) => k !== i)
  void saveInitiative(e, 0, s.value.session.initiative.round ?? 1)
}
function nextTurn(): void {
  const n = initiative.value.length
  if (!n) return
  const turn = (s.value.session.initiative.turn ?? 0) + 1
  void saveInitiative(initiative.value.map(x => ({ kind: x.kind, id: x.id })), turn % n, (s.value.session.initiative.round ?? 1) + (turn >= n ? 1 : 0))
}

// ---- Whispers & clues ----
const whisperTo = ref<string>('')
const whisperText = ref('')
watch(players, p => { if (!whisperTo.value && p[0]) whisperTo.value = p[0].user_id }, { immediate: true })
const thread = computed(() => s.value.whispers.filter(w => w.sender_user_id === whisperTo.value || w.recipient_user_id === whisperTo.value))
async function whisper(): Promise<void> {
  const body = whisperText.value.trim()
  if (!body || !whisperTo.value) return
  try {
    t.value.local.whisper(await tableApi.whisper(props.campaign.id, whisperTo.value, body))
    whisperText.value = ''
  } catch (e) { fail(e) }
}
const clueTitle = ref('')
const clueBody = ref('')
async function addClue(): Promise<void> {
  if (!clueTitle.value.trim()) return
  try {
    t.value.local.clue(await tableApi.createClue(s.value.session.id, clueTitle.value.trim(), clueBody.value.trim(), compassDraft.scene || null))
    clueTitle.value = ''
    clueBody.value = ''
  } catch (e) { fail(e) }
}
async function revealClue(id: string, to: 'table' | 'user', userId?: string): Promise<void> {
  try { t.value.local.clue(await tableApi.revealClue(id, to, userId)); props.table.toast(to === 'table' ? 'Pista revelada à mesa' : `Pista sussurrada a ${playerName(userId!)}`) } catch (e) { fail(e) }
}
const clueTarget = reactive<Record<string, string>>({})

// ---- NPC reveal ----
async function showNpc(id: string): Promise<void> {
  const npc = s.value.npcs.find(n => n.id === id)
  if (!npc) return
  const summary = window.prompt(`O que a mesa vê de ${npc.name}? (sem números)`, '') ?? null
  if (summary === null) return
  try { await tableApi.revealNpc(s.value.session.id, id, summary.slice(0, 300)); props.table.toast(`${npc.name} apresentado à mesa`) } catch (e) { fail(e) }
}

// ---- Session ----
async function transition(action: 'pause' | 'resume' | 'end'): Promise<void> {
  if (action === 'end' && !window.confirm('Encerrar a sessão? Pedidos abertos serão fechados; o histórico fica salvo.')) return
  try {
    t.value.local.session(await sessionApi.transition(s.value.session.id, action))
    if (action === 'end') await props.table.reload()
  } catch (e) { fail(e) }
}

// ---- Pairing ----
const pairing = ref(false)
const code = ref('')
const paired = ref<{ id: string; label: string; last_seen_at: string | null }[]>([])
async function refreshDevices(): Promise<void> {
  try { paired.value = (await devices.list()).data } catch { paired.value = [] }
}
async function openPairing(): Promise<void> {
  pairing.value = true
  await refreshDevices()
}
async function confirmCode(): Promise<void> {
  try {
    const r = await devices.confirm(code.value.trim())
    props.table.toast(`${r.device_label} pareado. Ele abre sozinho em instantes.`)
    code.value = ''
    setTimeout(() => void refreshDevices(), 2500)
  } catch (e) { fail(e, 'Código inválido ou expirado. Peça um novo no tablet.') }
}
async function revoke(id: string): Promise<void> {
  try { await devices.revoke(id); paired.value = paired.value.filter(d => d.id !== id) } catch (e) { fail(e) }
}

function replay(roll: OfficialRoll): void { props.table.enqueue(roll) }
const origin = location.origin
const dicePreview = (r: RollRequest) => describeDice(r.dice, r.allows_choice ? `${r.min_dice}–${r.max_dice}` : r.max_dice)
</script>

<template>
  <div class="master-table" :class="{ paused: s.session.status === 'paused' }">
    <header class="topbar master-bar">
      <a href="#/campanhas" class="brand-link" aria-label="Voltar às campanhas"><BrandMark /></a>
      <div class="session-name">
        <p class="eyebrow">{{ campaign.name }}</p>
        <strong>{{ s.session.title }}</strong>
        <span v-if="s.session.status === 'paused'" class="pill">pausada</span>
      </div>
      <span class="spacer" />
      <LinkBadge :state="table.link.value" />
      <label class="mini theme-pick">Tema <select v-model="theme"><option v-for="th in THEMES" :key="th.id" :value="th.id">{{ th.name }}</option></select></label>
      <a class="button ghost" :href="`#/roteiro/${campaign.id}`" target="_blank" rel="noopener">Roteiro</a>
      <a class="button ghost" :href="`#/atlas/${campaign.id}`" target="_blank" rel="noopener">Atlas</a>
      <button type="button" class="ghost" @click="openPairing">Parear tablet</button>
      <button v-if="s.session.status === 'active'" type="button" class="ghost" @click="transition('pause')">Pausar</button>
      <button v-else type="button" class="ghost" @click="transition('resume')">Retomar</button>
      <button type="button" class="ghost danger" @click="transition('end')">Encerrar</button>
    </header>

    <section class="compass" aria-label="Bússola da cena">
      <label class="compass-field"><span>Cena</span><input v-model="compassDraft.scene" maxlength="120" placeholder="Onde estamos?" @change="saveCompass" /></label>
      <label class="compass-field grow"><span>Objetivo</span><input v-model="compassDraft.objective" maxlength="300" placeholder="O que a cena precisa resolver?" @change="saveCompass" /></label>
      <label class="compass-field grow"><span>Próxima pista</span><input v-model="compassDraft.next_clue" maxlength="300" placeholder="O que plantar a seguir" @change="saveCompass" /></label>
      <div class="clocks">
        <div v-for="c in compassDraft.clocks" :key="c.id" class="clock">
          <input v-model="c.label" class="clock-label" maxlength="80" :aria-label="`Nome do relógio ${c.label}`" @change="saveCompass" />
          <div class="segments" role="group" :aria-label="`${c.label}: ${c.filled} de ${c.segments}`">
            <button v-for="i in c.segments" :key="i" type="button" class="seg-dot" :class="{ full: i <= c.filled }" :aria-label="`Marcar ${i}`" @click="clockClick(c, i - 1)" />
          </div>
          <button type="button" class="link small" :aria-label="`Remover relógio ${c.label}`" @click="removeClock(c)">×</button>
        </div>
        <button type="button" class="link" @click="addClock">+ relógio</button>
      </div>
    </section>

    <div class="master-grid">
      <aside class="cast" aria-label="Elenco">
        <h2>Jogadores</h2>
        <article v-for="c in s.characters" :key="c.id" class="cast-card" :class="{ on: selected?.id === c.id, low: spotlightLow.has(c.id) }">
          <button type="button" class="cast-head" @click="select('character', c.id)">
            <strong>{{ c.name }}</strong><span class="muted small">{{ playerName(c.player_user_id) }}</span>
          </button>
          <LiveTracks :live="c.live" />
          <div class="cast-actions">
            <button type="button" class="link" :title="spotlightLow.has(c.id) ? 'Este personagem teve menos holofote' : ''" @click="spotlight(c.id)">Holofote {{ compassDraft.spotlight[c.id] ?? 0 }} +1</button>
            <button type="button" class="link" @click="select('character', c.id, true)">Ver ficha</button>
          </div>
        </article>

        <h2 v-if="s.suggestions.length">Sugestões dos jogadores</h2>
        <article v-for="sg in s.suggestions" :key="sg.id" class="suggestion">
          <p><strong>{{ sg.character_name }}</strong> quer rolar <span class="muted">{{ sg.paths.map(p => p.split('/').pop()).join(' + ') }}</span></p>
          <p v-if="sg.note" class="muted small">“{{ sg.note }}”</p>
          <div class="row"><button type="button" class="small-btn" @click="fromSuggestion(sg)">Montar pedido</button><button type="button" class="link" @click="dismiss(sg)">Dispensar</button></div>
        </article>

        <h2>PdMs e ameaças</h2>
        <article v-for="n in s.npcs" :key="n.id" class="cast-card npc" :class="{ on: selected?.id === n.id }">
          <button type="button" class="cast-head" @click="select('npc', n.id)"><strong>{{ n.name }}</strong></button>
          <LiveTracks :live="n.live" editable :busy="busy" @save="(r, c) => saveLive('npc', n.id, r, c)" />
          <div class="cast-actions">
            <button type="button" class="link" @click="select('npc', n.id)">Rolar</button>
            <button type="button" class="link" @click="showNpc(n.id)">Mostrar à mesa</button>
            <button type="button" class="link" @click="select('npc', n.id, true)">Ver ficha</button>
          </div>
        </article>

        <h2>Iniciativa <span v-if="initiative.length" class="muted small">rodada {{ s.session.initiative.round ?? 1 }}</span></h2>
        <ol v-if="initiative.length" class="initiative">
          <li v-for="(e, i) in initiative" :key="e.id" :class="{ turn: i === (s.session.initiative.turn ?? 0) }">
            <span>{{ e.name }}</span>
            <span class="init-actions">
              <button type="button" class="link" :aria-label="`Subir ${e.name}`" @click="initMove(i, -1)">↑</button>
              <button type="button" class="link" :aria-label="`Descer ${e.name}`" @click="initMove(i, 1)">↓</button>
              <button type="button" class="link" :aria-label="`Tirar ${e.name}`" @click="initRemove(i)">×</button>
            </span>
          </li>
        </ol>
        <div class="row"><button type="button" class="small-btn" @click="initAll">{{ initiative.length ? 'Recomeçar com todos' : 'Montar com todos' }}</button><button v-if="initiative.length" type="button" class="small-btn" @click="nextTurn">Próximo turno</button></div>
      </aside>

      <main class="stage" aria-label="Pedidos e rolagens">
        <RollComposer
          v-if="subject && subjectSheet"
          :subject-name="subject.name" :subject-kind="subject.kind" :fields="subjectSheet.numeric" :selected="paths" :busy="busy || s.session.status !== 'active'"
          @toggle="toggle" @submit="submitComposer"
        />
        <p v-else class="muted panel">Escolha alguém do elenco para pedir uma rolagem.</p>
        <p v-if="suggestionId" class="muted small">Montando a partir da sugestão do jogador: ajuste os dados e envie.</p>

        <h2>Pedidos abertos</h2>
        <ul class="open-requests">
          <li v-for="r in s.requests" :key="r.id" class="open-request">
            <div><strong>{{ r.subject.name }}</strong> · {{ r.label }} <span v-if="r.secret" class="pill secret">secreta</span></div>
            <div class="muted small">{{ dicePreview(r) }} · tentativa {{ r.attempts_consumed + 1 }}/{{ r.attempts_allowed }} · aguardando o jogador</div>
            <div class="row"><button type="button" class="link" @click="grant(r)">+1 tentativa</button><button type="button" class="link" @click="cancel(r)">Cancelar</button></div>
          </li>
          <li v-if="!s.requests.length" class="muted small">Nenhum pedido esperando.</li>
        </ul>
      </main>

      <aside class="side" aria-label="Registro da mesa">
        <div class="tabs" role="tablist">
          <button type="button" role="tab" :aria-selected="tab === 'rolls'" :class="{ on: tab === 'rolls' }" @click="tab = 'rolls'">Rolagens</button>
          <button type="button" role="tab" :aria-selected="tab === 'notes'" :class="{ on: tab === 'notes' }" @click="tab = 'notes'">Anotações</button>
          <button type="button" role="tab" :aria-selected="tab === 'whispers'" :class="{ on: tab === 'whispers' }" @click="tab = 'whispers'">Sussurros</button>
          <button type="button" role="tab" :aria-selected="tab === 'clues'" :class="{ on: tab === 'clues' }" @click="tab = 'clues'">Pistas</button>
        </div>
        <RollFeed v-if="tab === 'rolls'" :rolls="s.rolls" master @reveal="reveal" @replay="replay" />
        <NotesPanel v-else-if="tab === 'notes'" :campaign-id="campaign.id" :notes="s.notes" :state="t" @error="fail" />
        <section v-else-if="tab === 'whispers'" class="whispers">
          <label class="mini">Para <select v-model="whisperTo"><option v-for="p in players" :key="p.user_id" :value="p.user_id">{{ p.name }}</option></select></label>
          <ol class="thread">
            <li v-for="w in thread" :key="w.id" :class="{ out: w.recipient_user_id === whisperTo }"><span class="pill" v-if="w.kind === 'clue'">pista</span>{{ w.body }}</li>
            <li v-if="!thread.length" class="muted small">Só {{ playerName(whisperTo) }} vai ler o que você escrever aqui.</li>
          </ol>
          <form class="row" @submit.prevent="whisper"><input v-model="whisperText" maxlength="2000" placeholder="Um segredo só para essa pessoa" aria-label="Sussurro" /><button type="submit" class="small-btn">Sussurrar</button></form>
        </section>
        <section v-else class="clues">
          <article v-for="c in s.clues" :key="c.id" class="clue" :class="{ revealed: c.revealed_to === 'table' }">
            <p><strong>{{ c.title }}</strong> <span v-if="c.scene" class="muted small">· {{ c.scene }}</span></p>
            <p class="small">{{ c.body }}</p>
            <p v-if="c.revealed_to === 'table'" class="muted small">Revelada à mesa</p>
            <p v-else-if="c.revealed_to === 'user'" class="muted small">Sussurrada a {{ playerName(c.revealed_user_id ?? '') }}</p>
            <div v-if="c.revealed_to !== 'table'" class="row wrap">
              <button type="button" class="small-btn" @click="revealClue(c.id, 'table')">Revelar a todos</button>
              <select v-model="clueTarget[c.id]" aria-label="Sussurrar a"><option value="" disabled>Sussurrar a…</option><option v-for="p in players" :key="p.user_id" :value="p.user_id">{{ p.name }}</option></select>
              <button type="button" class="link" :disabled="!clueTarget[c.id]" @click="revealClue(c.id, 'user', clueTarget[c.id])">Enviar</button>
            </div>
          </article>
          <form class="new-clue" @submit.prevent="addClue">
            <input v-model="clueTitle" maxlength="120" placeholder="Nova pista" aria-label="Título da pista" />
            <textarea v-model="clueBody" maxlength="2000" rows="2" placeholder="O que os jogadores descobrem" aria-label="Texto da pista" />
            <button type="submit" class="small-btn">Guardar pista</button>
          </form>
        </section>
      </aside>
    </div>

    <div v-if="drawer && subject" class="drawer-veil" @click.self="drawer = false">
      <aside class="drawer" role="dialog" aria-modal="true" :aria-label="`Ficha de ${subject.name}`" @keydown.escape="drawer = false">
        <header class="drawer-head"><h2>{{ subject.name }}</h2><button type="button" class="ghost" @click="drawer = false">Fechar</button></header>
        <LiveTracks :live="subject.live" editable :busy="busy" @save="(r, c) => saveLive(subject!.kind, subject!.id, r, c)" />
        <SheetView v-if="subjectSheet" :sections="subjectSheet.sections" :selected="paths" selectable @toggle="toggle" />
        <p v-else role="status">Carregando ficha…</p>
        <div class="row"><button type="button" class="primary" @click="drawer = false">Pedir rolagem com a combinação</button></div>
      </aside>
    </div>

    <div v-if="pairing" class="drawer-veil" @click.self="pairing = false">
      <section class="dialog" role="dialog" aria-modal="true" aria-labelledby="pair-title" @keydown.escape="pairing = false">
        <h2 id="pair-title">Parear um tablet</h2>
        <ol class="small"><li>No tablet, abra <strong>{{ origin }}/#/tablet</strong>.</li><li>Digite aqui o código de 6 dígitos que aparece nele.</li></ol>
        <form class="row" @submit.prevent="confirmCode"><input v-model="code" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="000000" aria-label="Código do tablet" /><button type="submit" class="primary">Confirmar</button></form>
        <h3>Aparelhos pareados</h3>
        <ul class="devices"><li v-for="d in paired" :key="d.id">{{ d.label }} <button type="button" class="link danger" @click="revoke(d.id)">Desparear</button></li><li v-if="!paired.length" class="muted small">Nenhum.</li></ul>
        <button type="button" class="ghost" @click="pairing = false">Fechar</button>
      </section>
    </div>
  </div>
</template>
