<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { ApiError } from '../api/client'
import BrandMark from '../components/BrandMark.vue'
import SceneArt from '../components/live/SceneArt.vue'
import { ART_LABELS } from '../live/art'
import { images, sheets, world } from '../live/api'
import { oracle } from '../live/oracle'
import { plain } from '../live/sheet'
import { ART_KEYS, type CarryOver, type Roteiro, type RoteiroContent, type RoteiroScene } from '../live/types'
import { go, me } from '../live/user'
import { applyTheme, loadTheme } from '../live/theme'

/**
 * Session prep (SPEC-MVP-003 LT-07): a guided, short prep in steps — characters, strong start,
 * scenes (illustrated storyboard), secrets and clues, places, NPCs, threats and rewards. It
 * starts from what is still open (unrevealed clues, open threads, what each character wants),
 * offers generators for every blank, and saves by itself.
 */
const props = defineProps<{ campaignId: string }>()
const EMPTY = (): RoteiroContent => ({ strong_start: '', scenes: [], npcs: [], clocks: [], wants: [], locations: [], rewards: [] })
const list = ref<Roteiro[]>([])
const current = ref<Roteiro | null>(null)
const title = ref('')
const c = reactive<RoteiroContent>(EMPTY())
const status = ref<'' | 'saving' | 'saved' | 'conflict' | 'invalid'>('')
const error = ref('')
const carry = ref<CarryOver | null>(null)
const npcNames = ref<string[]>([])
const step = ref<'chars' | 'start' | 'scenes' | 'secrets' | 'places' | 'npcs' | 'threats' | 'rewards'>('scenes')
const openScene = ref(0)
let loading = false

const STEPS = [
  { id: 'chars', n: 1, name: 'Personagens', hint: 'O que cada um quer agora' },
  { id: 'start', n: 2, name: 'Abertura forte', hint: 'Comece no meio da ação' },
  { id: 'scenes', n: 3, name: 'Cenas', hint: 'Possibilidades, não trilhos' },
  { id: 'secrets', n: 4, name: 'Segredos e pistas', hint: 'Coisas que podem ser descobertas' },
  { id: 'places', n: 5, name: 'Lugares', hint: 'Três detalhes marcantes' },
  { id: 'npcs', n: 6, name: 'PdMs', hint: 'Voz, desejo, segredo' },
  { id: 'threats', n: 7, name: 'Ameaças', hint: 'Relógios que avançam' },
  { id: 'rewards', n: 8, name: 'Recompensas', hint: 'O que pode ser ganho' },
] as const

function edit(r: Roteiro): void {
  loading = true
  current.value = r
  title.value = r.title
  Object.assign(c, EMPTY(), plain(r.content))
  c.scenes = c.scenes.map(sc => ({ location: '', read_aloud: '', npcs: [], art: 'road', complication: '', image_id: null, ...sc, clues: sc.clues ?? [] }))
  status.value = ''
  openScene.value = 0
  setTimeout(() => { loading = false }, 0)
}
onMounted(async () => {
  if (!me.value) return go('#/')
  applyTheme(loadTheme(props.campaignId))
  try {
    list.value = (await world.roteiros(props.campaignId)).data
    if (list.value[0]) edit(list.value[0])
  } catch (e) { error.value = e instanceof ApiError && e.status === 403 ? 'Só o mestre prepara roteiros.' : 'Não foi possível carregar.'; return }
  carry.value = await world.carryOver(props.campaignId).catch(() => null)
  npcNames.value = (await sheets.npcs(props.campaignId).catch(() => ({ data: [] }))).data.map(n => n.name)
})

// ---------- Autosave ----------
let timer: ReturnType<typeof setTimeout> | undefined
onBeforeUnmount(() => { clearTimeout(timer); if (status.value === 'saving') void save() })
watch([title, () => JSON.stringify(c)], () => {
  if (loading || !current.value) return
  status.value = 'saving'
  clearTimeout(timer)
  timer = setTimeout(() => void save(), 900)
})
const clean = (): RoteiroContent => {
  const x = plain({ ...c })
  x.scenes = x.scenes.filter(sc => sc.title.trim()).map(sc => ({ ...sc, clues: sc.clues.filter(k => k.title.trim()).map(k => ({ title: k.title, body: k.body || k.title })), npcs: (sc.npcs ?? []).filter(Boolean) }))
  x.npcs = x.npcs.filter(n => n.name.trim())
  x.clocks = x.clocks.filter(k => k.label.trim())
  x.wants = x.wants.filter(w => w.character.trim())
  x.locations = x.locations.filter(l => l.name.trim()).map(l => ({ ...l, aspects: l.aspects.filter(Boolean) }))
  x.rewards = x.rewards.filter(Boolean)
  return x
}
async function save(): Promise<void> {
  if (!current.value) return
  try {
    const r = await world.saveRoteiro(current.value.id, current.value.revision, title.value.trim() || 'Sem título', clean())
    current.value = r
    list.value = list.value.map(x => (x.id === r.id ? r : x))
    status.value = 'saved'
  } catch (e) {
    status.value = e instanceof ApiError && e.status === 409 ? 'conflict' : 'invalid'
  }
}
const statusText = computed(() => ({ '': '', saving: 'Salvando…', saved: 'Salvo', conflict: 'Mudou em outra janela: recarregue a página.', invalid: 'Algum campo passou do tamanho; confira.' })[status.value])

// ---------- New prep ----------
async function create(fromLast: boolean): Promise<void> {
  const content = EMPTY()
  if (fromLast && carry.value) {
    content.wants = carry.value.characters.map(ch => ({ character: ch.name, text: ch.motivation ?? '' }))
    const pending = carry.value.unrevealed_clues.map(k => ({ title: k.title, body: k.body || k.title }))
    content.scenes = [
      { title: 'Abertura', goal: 'Retomar de onde paramos', clues: pending.slice(0, 3), art: 'road', location: '', read_aloud: '', npcs: [], complication: '' },
      ...carry.value.open_threads.slice(0, 3).map(t => ({ title: t.title, goal: t.summary.slice(0, 300), clues: [], art: 'road' as const, location: '', read_aloud: '', npcs: [], complication: '' })),
    ]
    if (pending.length > 3) content.scenes.push({ title: 'Segredos que ainda esperam', goal: 'Encaixe onde fizer sentido', clues: pending.slice(3, 11), art: 'ruins', location: '', read_aloud: '', npcs: [], complication: '' })
  } else {
    content.scenes = [{ title: 'Abertura', goal: '', clues: [], art: 'road', location: '', read_aloud: '', npcs: [], complication: '' }]
  }
  try {
    const r = await world.createRoteiro(props.campaignId, `Sessão ${list.value.length + 1}`, content)
    list.value.unshift(r)
    edit(r)
    step.value = fromLast ? 'chars' : 'start'
  } catch { error.value = 'Não foi possível criar o roteiro.' }
}

// ---------- Scenes ----------
const newScene = (): RoteiroScene => ({ title: '', goal: '', clues: [], art: 'road', location: '', read_aloud: '', npcs: [], complication: '', image_id: null })
function addScene(): void { c.scenes.push(newScene()); openScene.value = c.scenes.length - 1 }
function moveScene(i: number, d: number): void {
  const j = i + d
  if (j < 0 || j >= c.scenes.length) return
  const s = c.scenes.splice(i, 1)[0]
  c.scenes.splice(j, 0, s)
  openScene.value = j
}
function toggleNpc(sc: RoteiroScene, name: string): void {
  const l = sc.npcs ?? []
  sc.npcs = l.includes(name) ? l.filter(x => x !== name) : [...l, name].slice(0, 8)
}
const castNames = computed(() => [...new Set([...npcNames.value, ...c.npcs.map(n => n.name).filter(Boolean)])])
const fileFor = ref<number | null>(null)
const file = ref<HTMLInputElement | null>(null)
function pickImage(i: number): void { fileFor.value = i; file.value?.click() }
async function onFile(ev: Event): Promise<void> {
  const f = (ev.target as HTMLInputElement).files?.[0]
  const i = fileFor.value
  if (!f || i === null) return
  try { c.scenes[i].image_id = (await images.upload(props.campaignId, f, 'scene')).id } catch { error.value = 'Imagem não aceita (JPG, PNG ou WebP até 4 MB).' }
  if (file.value) file.value.value = ''
}

// ---------- Secrets pool ----------
const allClues = computed(() => c.scenes.flatMap((sc, i) => sc.clues.map((k, j) => ({ ...k, scene: i, j }))))
const quickClue = reactive({ title: '', body: '', scene: 0 })
function addClue(): void {
  if (!quickClue.title.trim() || !c.scenes[quickClue.scene]) return
  c.scenes[quickClue.scene].clues.push({ title: quickClue.title.trim(), body: quickClue.body.trim() })
  quickClue.title = ''
  quickClue.body = ''
}
function moveClue(from: number, j: number, to: number): void {
  const [k] = c.scenes[from].clues.splice(j, 1)
  c.scenes[to].clues.push(k)
}
function bringPending(k: { title: string; body: string }): void {
  if (!c.scenes.length) c.scenes.push(newScene())
  c.scenes[0].clues.push({ title: k.title, body: k.body || k.title })
}
const pendingNotUsed = computed(() => (carry.value?.unrevealed_clues ?? []).filter(k => !allClues.value.some(x => x.title === k.title)))
const ready = computed(() => c.scenes.some(sc => sc.title.trim()))
</script>

<template>
  <main class="prep">
    <header class="topbar">
      <a href="#/campanhas" class="brand-link"><BrandMark /></a>
      <div class="session-name"><p class="eyebrow">Preparação</p><strong>{{ title || 'Roteiro' }}</strong></div>
      <span class="spacer" />
      <span class="save-state" :class="status" role="status">{{ statusText }}</span>
      <a class="button primary" :href="`#/mesa/${campaignId}`">Ir para a mesa</a>
    </header>
    <p v-if="error" class="error panel" role="alert">{{ error }}</p>

    <div class="prep-shell">
      <nav class="prep-side" aria-label="Roteiros">
        <button type="button" class="primary" :disabled="!carry" @click="create(true)">Continuar a história</button>
        <p class="muted small">Traz pistas não reveladas, fios abertos e o que cada personagem quer.</p>
        <button type="button" class="ghost" @click="create(false)">Roteiro em branco</button>
        <p class="rail-title">Roteiros</p>
        <button v-for="r in list" :key="r.id" type="button" class="binder-item" :class="{ on: current?.id === r.id }" @click="edit(r)">{{ r.title }}</button>
      </nav>

      <section v-if="current" class="prep-main">
        <input v-model="title" class="prep-title" maxlength="120" aria-label="Título do roteiro" />
        <ol class="steps" aria-label="Passos">
          <li v-for="st in STEPS" :key="st.id"><button type="button" :class="{ on: step === st.id }" :aria-current="step === st.id ? 'step' : undefined" @click="step = st.id"><span>{{ st.n }}</span>{{ st.name }}</button></li>
        </ol>
        <p class="step-hint">{{ STEPS.find(x => x.id === step)?.hint }}</p>

        <!-- 1 Personagens -->
        <section v-if="step === 'chars'" class="step-body">
          <div v-for="(w, i) in c.wants" :key="i" class="want-row">
            <input v-model="w.character" maxlength="80" placeholder="Personagem" aria-label="Personagem" />
            <input v-model="w.text" maxlength="300" placeholder="O que ele quer nesta sessão" aria-label="Desejo" class="grow" />
            <button type="button" class="link" aria-label="Remover" @click="c.wants.splice(i, 1)">×</button>
          </div>
          <div class="row wrap">
            <button type="button" class="small-btn" @click="c.wants.push({ character: '', text: '' })">+ personagem</button>
            <button v-if="carry?.characters.length" type="button" class="small-btn" @click="c.wants = carry!.characters.map(ch => ({ character: ch.name, text: c.wants.find(w => w.character === ch.name)?.text || ch.motivation || '' }))">Puxar do grupo</button>
          </div>
        </section>

        <!-- 2 Abertura -->
        <section v-else-if="step === 'start'" class="step-body">
          <textarea v-model="c.strong_start" rows="5" maxlength="1000" placeholder="A primeira coisa que acontece na mesa." aria-label="Abertura forte" />
          <button type="button" class="small-btn" @click="c.strong_start = oracle.strongStart()">🎲 Sugerir uma abertura</button>
        </section>

        <!-- 3 Cenas -->
        <section v-else-if="step === 'scenes'" class="step-body">
          <div class="storyboard">
            <button v-for="(sc, i) in c.scenes" :key="i" type="button" class="board-card" :class="{ on: openScene === i }" @click="openScene = i">
              <SceneArt :art="sc.art ?? 'road'" :image-id="sc.image_id ?? null" />
              <span class="board-n">{{ i + 1 }}</span>
              <span class="board-title">{{ sc.title || 'Sem título' }}</span>
              <span class="board-meta">{{ sc.clues.length }} pista{{ sc.clues.length === 1 ? '' : 's' }}<template v-if="sc.npcs?.length"> · {{ sc.npcs.length }} PdM</template></span>
            </button>
            <button type="button" class="board-card add" @click="addScene">+ cena</button>
          </div>
          <input ref="file" type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" aria-label="Imagem da cena" @change="onFile" />
          <article v-if="c.scenes[openScene]" class="scene-editor">
            <div class="row wrap">
              <label class="grow">Título <input v-model="c.scenes[openScene].title" maxlength="120" placeholder="Ex.: A ponte sob a névoa" /></label>
              <label class="grow">Local <input v-model="c.scenes[openScene].location" maxlength="120" placeholder="Onde acontece" /></label>
            </div>
            <label>O que precisa ser resolvido <input v-model="c.scenes[openScene].goal" maxlength="300" /></label>
            <label>Texto para ler em voz alta <textarea v-model="c.scenes[openScene].read_aloud" rows="3" maxlength="1500" placeholder="Duas ou três frases que pintam a cena." /></label>
            <label>Complicação <span class="row"><input v-model="c.scenes[openScene].complication" maxlength="500" class="grow" /><button type="button" class="small-btn" @click="c.scenes[openScene].complication = oracle.complication()">🎲</button></span></label>
            <fieldset class="art-pick"><legend>Ilustração</legend>
              <button v-for="k in ART_KEYS" :key="k" type="button" class="art-option" :class="{ on: !c.scenes[openScene].image_id && c.scenes[openScene].art === k }" :aria-pressed="c.scenes[openScene].art === k" @click="c.scenes[openScene].art = k; c.scenes[openScene].image_id = null"><SceneArt :art="k" /><span>{{ ART_LABELS[k] }}</span></button>
              <button type="button" class="art-option upload" :class="{ on: !!c.scenes[openScene].image_id }" @click="pickImage(openScene)"><span>{{ c.scenes[openScene].image_id ? 'Sua imagem ✓' : 'Enviar imagem' }}</span></button>
            </fieldset>
            <fieldset v-if="castNames.length" class="npc-pick"><legend>Quem aparece</legend>
              <button v-for="n in castNames" :key="n" type="button" class="chip" :class="{ on: c.scenes[openScene].npcs?.includes(n) }" :aria-pressed="c.scenes[openScene].npcs?.includes(n) ?? false" @click="toggleNpc(c.scenes[openScene], n)">{{ n }}</button>
            </fieldset>
            <h3 class="sheet-heading">Pistas desta cena</h3>
            <div v-for="(cl, j) in c.scenes[openScene].clues" :key="j" class="clue-edit">
              <input v-model="cl.title" maxlength="120" placeholder="Pista" aria-label="Pista" />
              <input v-model="cl.body" maxlength="1000" placeholder="O que ela revela" aria-label="Texto da pista" class="grow" />
              <button type="button" class="link" aria-label="Remover pista" @click="c.scenes[openScene].clues.splice(j, 1)">×</button>
            </div>
            <div class="row wrap">
              <button type="button" class="small-btn" @click="c.scenes[openScene].clues.push({ title: '', body: '' })">+ pista</button>
              <span class="spacer" />
              <button type="button" class="link" :disabled="openScene === 0" @click="moveScene(openScene, -1)">◀ mover</button>
              <button type="button" class="link" :disabled="openScene === c.scenes.length - 1" @click="moveScene(openScene, 1)">mover ▶</button>
              <button type="button" class="link danger" @click="c.scenes.splice(openScene, 1); openScene = Math.max(0, openScene - 1)">Remover cena</button>
            </div>
          </article>
        </section>

        <!-- 4 Segredos -->
        <section v-else-if="step === 'secrets'" class="step-body">
          <p class="muted small">Pistas não pertencem a um caminho só: se os jogadores pularem uma cena, mova a pista para outra.</p>
          <div v-if="pendingNotUsed.length" class="carry">
            <p class="rail-title">Ainda não reveladas na última sessão</p>
            <button v-for="k in pendingNotUsed" :key="k.title" type="button" class="chip" @click="bringPending(k)">+ {{ k.title }}</button>
          </div>
          <table class="secrets-table">
            <thead><tr><th scope="col">Pista</th><th scope="col">Cena</th></tr></thead>
            <tbody>
              <tr v-for="k in allClues" :key="`${k.scene}-${k.j}`">
                <td><strong>{{ k.title }}</strong><small>{{ k.body }}</small></td>
                <td><select :value="k.scene" :aria-label="`Cena de ${k.title}`" @change="moveClue(k.scene, k.j, Number(($event.target as HTMLSelectElement).value))"><option v-for="(sc, i) in c.scenes" :key="i" :value="i">{{ i + 1 }}. {{ sc.title || 'sem título' }}</option></select></td>
              </tr>
              <tr v-if="!allClues.length"><td colspan="2" class="muted small">Nenhuma pista ainda.</td></tr>
            </tbody>
          </table>
          <form class="row wrap" @submit.prevent="addClue">
            <input v-model="quickClue.title" maxlength="120" placeholder="Nova pista" aria-label="Nova pista" />
            <input v-model="quickClue.body" maxlength="1000" placeholder="O que revela" aria-label="Texto" class="grow" />
            <select v-model.number="quickClue.scene" aria-label="Cena"><option v-for="(sc, i) in c.scenes" :key="i" :value="i">{{ i + 1 }}. {{ sc.title || 'sem título' }}</option></select>
            <button type="submit" class="small-btn">Guardar</button>
          </form>
        </section>

        <!-- 5 Lugares -->
        <section v-else-if="step === 'places'" class="step-body">
          <article v-for="(l, i) in c.locations" :key="i" class="prep-card">
            <div class="row"><input v-model="l.name" maxlength="80" placeholder="Nome do lugar" aria-label="Lugar" class="grow" /><button type="button" class="small-btn" @click="l.aspects = oracle.aspects()">🎲 Detalhes</button><button type="button" class="link danger" @click="c.locations.splice(i, 1)">×</button></div>
            <input v-for="(_, j) in [0, 1, 2]" :key="j" v-model="l.aspects[j]" maxlength="120" :placeholder="['O que se vê', 'O que se ouve', 'O que se sente'][j]" :aria-label="`Detalhe ${j + 1}`" />
          </article>
          <button type="button" class="small-btn" @click="c.locations.push({ name: '', aspects: ['', '', ''] })">+ lugar</button>
        </section>

        <!-- 6 PdMs -->
        <section v-else-if="step === 'npcs'" class="step-body">
          <article v-for="(n, i) in c.npcs" :key="i" class="prep-card">
            <div class="row wrap">
              <label class="grow">Nome <input v-model="n.name" maxlength="80" /></label>
              <label class="grow">Voz e jeito <input v-model="n.voice" maxlength="200" /></label>
            </div>
            <div class="row wrap">
              <label class="grow">Quer <input v-model="n.wants" maxlength="200" /></label>
              <label class="grow">Segredo <input v-model="n.secret" maxlength="300" /></label>
            </div>
            <button type="button" class="link danger" @click="c.npcs.splice(i, 1)">Remover</button>
          </article>
          <div class="row wrap">
            <button type="button" class="small-btn" @click="c.npcs.push({ name: '', voice: '', wants: '', secret: '' })">+ PdM</button>
            <button type="button" class="small-btn" @click="c.npcs.push(oracle.npc())">🎲 PdM improvisado</button>
          </div>
        </section>

        <!-- 7 Ameaças -->
        <section v-else-if="step === 'threats'" class="step-body">
          <div v-for="(k, i) in c.clocks" :key="i" class="row">
            <input v-model="k.label" maxlength="80" placeholder="Ex.: A cheia do rio" aria-label="Relógio" class="grow" />
            <label class="mini">Partes <select v-model.number="k.segments"><option v-for="n in [4, 6, 8]" :key="n" :value="n">{{ n }}</option></select></label>
            <button type="button" class="link" aria-label="Remover relógio" @click="c.clocks.splice(i, 1)">×</button>
          </div>
          <button type="button" class="small-btn" @click="c.clocks.push({ label: '', segments: 4 })">+ relógio</button>
        </section>

        <!-- 8 Recompensas -->
        <section v-else class="step-body">
          <div v-for="(_, i) in c.rewards" :key="i" class="row"><input v-model="c.rewards[i]" maxlength="200" class="grow" aria-label="Recompensa" /><button type="button" class="link" aria-label="Remover" @click="c.rewards.splice(i, 1)">×</button></div>
          <button type="button" class="small-btn" @click="c.rewards.push('')">+ recompensa</button>
        </section>

        <footer class="prep-foot">
          <span class="muted small">Salva sozinho enquanto você escreve.</span>
          <span class="spacer" />
          <a v-if="ready" class="button primary" :href="`#/mesa/${campaignId}`">Começar a sessão com este roteiro</a>
        </footer>
      </section>
      <section v-else class="prep-main empty-bench">
        <p class="char-name">Nenhum roteiro ainda</p>
        <p class="muted">Comece por “Continuar a história” ou por um roteiro em branco.</p>
      </section>
    </div>
  </main>
</template>
