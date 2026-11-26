<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import NotesPanel from '../../components/live/NotesPanel.vue'
import HistoryPanel from './HistoryPanel.vue'
import Portrait from '../../components/live/Portrait.vue'
import { oracle } from '../../live/oracle'
import type { Clue } from '../../live/types'
import { useMaster } from './context'

/**
 * The story column: the plot of this session (scene by scene, with its clues ready to reveal),
 * the chronicle of what happened, whispers per player, notes and improvisation tools.
 */
const m = useMaster()
const tab = ref<'plot' | 'chronicle' | 'whispers' | 'notes' | 'oracle'>('plot')
const s = computed(() => m.s.value)
const cur = computed(() => m.currentScene.value)

// ---------- Plot & clues ----------
const sceneClues = computed(() => s.value.clues.filter(c => cur.value && c.scene === cur.value.title))
const otherClues = computed(() => s.value.clues.filter(c => !cur.value || c.scene !== cur.value.title))
const openOthers = ref(false)
const whisperTarget = reactive<Record<string, string>>({})
const sceneNpcs = computed(() => s.value.npcs.filter(n => m.inScene.value.includes(n.id)))
const newClue = reactive({ title: '', body: '' })
async function addClue(): Promise<void> {
  if (!newClue.title.trim()) return
  if (await m.addClue(newClue.title.trim(), newClue.body.trim(), cur.value?.title ?? (m.compass.scene || null))) { newClue.title = ''; newClue.body = '' }
}
const clueState = (c: Clue) => (c.revealed_to === 'table' ? 'Revelada à mesa' : c.revealed_to === 'user' ? `Só ${m.playerName(c.revealed_user_id)}` : 'Ainda guardada')


// ---------- Whispers ----------
const to = ref<string>(m.players.value[0]?.user_id ?? '')
const text = ref('')
const seen = reactive<Record<string, number>>({})
const threadOf = (uid: string) => s.value.whispers.filter(w => w.sender_user_id === uid || w.recipient_user_id === uid)
const unread = (uid: string) => Math.max(0, threadOf(uid).filter(w => w.sender_user_id === uid).length - (seen[uid] ?? 0))
const unreadTotal = computed(() => m.players.value.reduce((a, p) => a + unread(p.user_id), 0))
function open(uid: string): void { to.value = uid; seen[uid] = threadOf(uid).filter(w => w.sender_user_id === uid).length }
async function send(): Promise<void> { if (to.value && await m.whisper(to.value, text.value)) { text.value = ''; open(to.value) } }
const charOf = (uid: string) => s.value.characters.find(c => c.controlled_by === uid)

// ---------- Oracle ----------
const odds = ref<'likely' | 'even' | 'unlikely'>('even')
const results = ref<{ id: string; title: string; text: string }[]>([])
function roll(kind: string): void {
  const map: Record<string, () => [string, string]> = {
    ask: () => ['Pergunta ao oráculo', oracle.ask(odds.value)],
    name: () => ['Nome', oracle.name()],
    npc: () => { const n = oracle.npc(); return ['PdM improvisado', `${n.name} — ${n.voice}. Quer ${n.wants}.`] },
    twist: () => ['Complicação', oracle.complication()],
    place: () => ['Lugar', oracle.aspects().join(' ')],
    weather: () => ['Clima', oracle.weather()],
    rumor: () => ['Boato', oracle.rumor()],
  }
  const [title, body] = map[kind]()
  results.value = [{ id: crypto.randomUUID(), title, text: body }, ...results.value].slice(0, 12)
}
</script>

<template>
  <aside class="story" aria-label="História">
    <div class="story-tabs" role="tablist">
      <button type="button" role="tab" :aria-selected="tab === 'plot'" :class="{ on: tab === 'plot' }" @click="tab = 'plot'">Trama</button>
      <button type="button" role="tab" :aria-selected="tab === 'chronicle'" :class="{ on: tab === 'chronicle' }" @click="tab = 'chronicle'">Histórico</button>
      <button type="button" role="tab" :aria-selected="tab === 'whispers'" :class="{ on: tab === 'whispers' }" @click="tab = 'whispers'">Sussurros<span v-if="unreadTotal" class="badge">{{ unreadTotal }}</span></button>
      <button type="button" role="tab" :aria-selected="tab === 'notes'" :class="{ on: tab === 'notes' }" @click="tab = 'notes'">Notas</button>
      <button type="button" role="tab" :aria-selected="tab === 'oracle'" :class="{ on: tab === 'oracle' }" @click="tab = 'oracle'">Oráculo</button>
    </div>

    <div class="story-scroll">
      <!-- Trama -->
      <section v-if="tab === 'plot'" class="plot">
        <div v-if="sceneNpcs.length" class="scene-npcs">
          <p class="rail-title">Em cena</p>
          <button v-for="n in sceneNpcs" :key="n.id" type="button" class="npc-chip" @click="m.select('npc', n.id)"><Portrait :name="n.name" :image-id="n.portrait_image_id" :live="n.live" :size="30" kind="npc" />{{ n.name }}</button>
        </div>
        <p class="rail-title">Pistas desta cena <small>{{ sceneClues.length }}</small></p>
        <article v-for="c in sceneClues" :key="c.id" class="clue-card" :class="{ revealed: c.revealed_to === 'table', whispered: c.revealed_to === 'user' }">
          <header><strong>{{ c.title }}</strong><span class="clue-state">{{ clueState(c) }}</span></header>
          <p>{{ c.body }}</p>
          <div v-if="c.revealed_to !== 'table'" class="clue-actions">
            <button type="button" class="small-btn" @click="m.revealClue(c.id, 'table')">Revelar a todos</button>
            <select :value="whisperTarget[c.id] ?? ''" :aria-label="`Sussurrar ${c.title} a`" @change="whisperTarget[c.id] = ($event.target as HTMLSelectElement).value"><option value="" disabled>Só para…</option><option v-for="p in m.players.value" :key="p.user_id" :value="p.user_id">{{ charOf(p.user_id)?.name ?? p.name }}</option></select>
            <button type="button" class="link" :disabled="!whisperTarget[c.id]" @click="m.revealClue(c.id, 'user', whisperTarget[c.id])">Enviar</button>
          </div>
        </article>
        <p v-if="!sceneClues.length" class="muted small">Nenhuma pista preparada para esta cena.</p>
        <form class="new-clue" @submit.prevent="addClue">
          <input v-model="newClue.title" maxlength="120" placeholder="Nova pista para esta cena" aria-label="Título da pista" />
          <textarea v-if="newClue.title" v-model="newClue.body" maxlength="2000" rows="2" placeholder="O que os jogadores descobrem" aria-label="Texto da pista" />
          <button v-if="newClue.title" type="submit" class="small-btn">Guardar pista</button>
        </form>
        <button v-if="otherClues.length" type="button" class="link" :aria-expanded="openOthers" @click="openOthers = !openOthers">{{ openOthers ? 'Esconder' : 'Ver' }} pistas de outras cenas ({{ otherClues.length }})</button>
        <template v-if="openOthers">
          <article v-for="c in otherClues" :key="c.id" class="clue-card small" :class="{ revealed: c.revealed_to === 'table' }">
            <header><strong>{{ c.title }}</strong><span class="clue-state">{{ c.scene || 'sem cena' }} · {{ clueState(c) }}</span></header>
            <div v-if="c.revealed_to !== 'table'" class="clue-actions"><button type="button" class="link" @click="m.revealClue(c.id, 'table')">Revelar a todos</button></div>
          </article>
        </template>
        <p class="rail-title">Cenas</p>
        <ol class="plot-list">
          <li v-for="(x, i) in m.plot.value.scenes" :key="x.id" :class="x.status">
            <button type="button" @click="m.goToScene(i)"><span class="plot-n">{{ i + 1 }}</span><span><strong>{{ x.title }}</strong><small>{{ x.goal }}</small></span></button>
          </li>
          <li v-if="!m.plot.value.scenes.length" class="muted small">Sem trama preparada. <a :href="`#/roteiro/${m.campaign.id}`" target="_blank" rel="noopener">Montar no Roteiro</a> ou improvise uma cena no topo.</li>
        </ol>
      </section>

      <!-- Histórico -->
      <HistoryPanel v-else-if="tab === 'chronicle'" />

      <!-- Sussurros -->
      <section v-else-if="tab === 'whispers'" class="whisper-box">
        <div class="whisper-people">
          <button v-for="p in m.players.value" :key="p.user_id" type="button" class="person" :class="{ on: to === p.user_id }" @click="open(p.user_id)">
            <Portrait :name="charOf(p.user_id)?.name ?? p.name" :image-id="charOf(p.user_id)?.portrait_image_id" :size="34" :ring="false" />
            <span>{{ charOf(p.user_id)?.name ?? p.name }}<small>{{ p.name }}</small></span>
            <span v-if="unread(p.user_id)" class="badge">{{ unread(p.user_id) }}</span>
          </button>
        </div>
        <ol class="thread">
          <li v-for="w in threadOf(to)" :key="w.id" :class="{ out: w.recipient_user_id === to }"><span v-if="w.kind === 'clue'" class="pill">pista</span>{{ w.body }}</li>
          <li v-if="!threadOf(to).length" class="muted small">Só {{ m.playerName(to) }} vai ler o que você escrever aqui.</li>
        </ol>
        <form class="row" @submit.prevent="send"><input v-model="text" maxlength="2000" placeholder="Um segredo só para essa pessoa" aria-label="Sussurro" /><button type="submit" class="small-btn">Sussurrar</button></form>
      </section>

      <NotesPanel v-else-if="tab === 'notes'" :campaign-id="m.campaign.id" :notes="s.notes" :state="m.t.value" @error="m.fail" />

      <!-- Oráculo -->
      <section v-else class="oracle">
        <p class="muted small">Para improvisar. Nada aqui é rolagem oficial nem aparece para os jogadores.</p>
        <div class="oracle-ask">
          <div class="seg-tabs small" role="radiogroup" aria-label="Chance">
            <button type="button" role="radio" :aria-checked="odds === 'unlikely'" :class="{ on: odds === 'unlikely' }" @click="odds = 'unlikely'">Improvável</button>
            <button type="button" role="radio" :aria-checked="odds === 'even'" :class="{ on: odds === 'even' }" @click="odds = 'even'">Meio a meio</button>
            <button type="button" role="radio" :aria-checked="odds === 'likely'" :class="{ on: odds === 'likely' }" @click="odds = 'likely'">Provável</button>
          </div>
          <button type="button" class="primary" @click="roll('ask')">Perguntar (sim / não)</button>
        </div>
        <div class="oracle-grid">
          <button type="button" class="ghost" @click="roll('name')">Nome</button>
          <button type="button" class="ghost" @click="roll('npc')">PdM</button>
          <button type="button" class="ghost" @click="roll('twist')">Complicação</button>
          <button type="button" class="ghost" @click="roll('place')">Lugar</button>
          <button type="button" class="ghost" @click="roll('weather')">Clima</button>
          <button type="button" class="ghost" @click="roll('rumor')">Boato</button>
        </div>
        <article v-for="r in results" :key="r.id" class="oracle-result">
          <p class="eyebrow">{{ r.title }}</p>
          <p>{{ r.text }}</p>
          <div class="row"><button type="button" class="link" @click="m.note(r.text, ['oraculo'])">Anotar</button></div>
        </article>
      </section>
    </div>
  </aside>
</template>
