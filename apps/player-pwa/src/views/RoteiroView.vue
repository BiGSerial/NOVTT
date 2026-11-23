<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ApiError } from '../api/client'
import BrandMark from '../components/BrandMark.vue'
import { world } from '../live/api'
import { plain } from '../live/sheet'
import { go, me } from '../live/user'
import type { Roteiro, RoteiroContent } from '../live/types'

/**
 * Session prep (SPEC-MVP-002 LT-07): a strong start, scenes with their goal and clues, the NPCs
 * to feature (voice, want, secret), threat clocks and what each character wants. Starting a
 * session from it fills the compass, clocks and clues.
 */
const props = defineProps<{ campaignId: string }>()
const list = ref<Roteiro[]>([])
const current = ref<Roteiro | null>(null)
const title = ref('')
const c = reactive<RoteiroContent>({ strong_start: '', scenes: [], npcs: [], clocks: [], wants: [] })
const status = ref('')
const error = ref('')

function edit(r: Roteiro): void {
  current.value = r
  title.value = r.title
  Object.assign(c, { strong_start: '', scenes: [], npcs: [], clocks: [], wants: [] }, plain(r.content))
  status.value = ''
}
onMounted(async () => {
  if (!me.value) return go('#/')
  try {
    list.value = (await world.roteiros(props.campaignId)).data
    if (list.value[0]) edit(list.value[0])
  } catch (e) { error.value = e instanceof ApiError && e.status === 403 ? 'Só o mestre prepara roteiros.' : 'Não foi possível carregar.' }
})
async function create(): Promise<void> {
  const r = await world.createRoteiro(props.campaignId, `Sessão ${list.value.length + 1}`, { strong_start: '', scenes: [{ title: 'Abertura', goal: '', clues: [] }], npcs: [], clocks: [], wants: [] })
  list.value.unshift(r)
  edit(r)
}
async function save(): Promise<void> {
  if (!current.value) return
  status.value = 'Salvando…'
  try {
    const r = await world.saveRoteiro(current.value.id, current.value.revision, title.value.trim() || 'Sem título', plain({ ...c }))
    current.value = r
    list.value = list.value.map(x => (x.id === r.id ? r : x))
    status.value = 'Salvo'
  } catch (e) {
    status.value = e instanceof ApiError && e.status === 409 ? 'Este roteiro mudou em outra janela: recarregue antes de salvar.' : 'Não foi possível salvar (confira tamanhos).'
  }
}
const ready = computed(() => c.scenes.length > 0)
</script>

<template>
  <main class="page wide prep">
    <header class="topbar">
      <a href="#/campanhas" class="brand-link"><BrandMark /></a>
      <h1>Roteiro</h1>
      <span class="spacer" />
      <a class="button" :href="`#/mesa/${campaignId}`">Ir para a mesa</a>
    </header>
    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <div v-else class="prep-grid">
      <nav class="prep-list" aria-label="Roteiros">
        <button type="button" class="primary" @click="create">Novo roteiro</button>
        <button v-for="r in list" :key="r.id" type="button" class="binder-item" :class="{ on: current?.id === r.id }" @click="edit(r)">{{ r.title }}</button>
      </nav>
      <form v-if="current" class="prep-form" @submit.prevent="save">
        <label>Título <input v-model="title" maxlength="120" /></label>
        <label>Abertura forte <span class="muted small">(a primeira coisa que acontece; comece no meio da ação)</span>
          <textarea v-model="c.strong_start" rows="3" maxlength="1000" />
        </label>

        <h2>Cenas</h2>
        <fieldset v-for="(sc, i) in c.scenes" :key="i" class="prep-card">
          <legend>Cena {{ i + 1 }}</legend>
          <label>Título <input v-model="sc.title" maxlength="120" required /></label>
          <label>O que precisa ser resolvido <input v-model="sc.goal" maxlength="300" /></label>
          <div v-for="(cl, j) in sc.clues" :key="j" class="row wrap clue-edit">
            <input v-model="cl.title" maxlength="120" placeholder="Pista" aria-label="Pista" required />
            <input v-model="cl.body" maxlength="1000" placeholder="O que ela revela" aria-label="Texto da pista" class="grow" />
            <button type="button" class="link" aria-label="Remover pista" @click="sc.clues.splice(j, 1)">×</button>
          </div>
          <div class="row"><button type="button" class="link" @click="sc.clues.push({ title: '', body: '' })">+ pista</button><button type="button" class="link danger" @click="c.scenes.splice(i, 1)">Remover cena</button></div>
        </fieldset>
        <button type="button" class="small-btn" @click="c.scenes.push({ title: '', goal: '', clues: [] })">+ cena</button>

        <h2>PdMs em destaque</h2>
        <fieldset v-for="(n, i) in c.npcs" :key="i" class="prep-card">
          <legend>{{ n.name || 'PdM' }}</legend>
          <div class="row wrap">
            <label class="grow">Nome <input v-model="n.name" maxlength="80" required /></label>
            <label class="grow">Voz <input v-model="n.voice" maxlength="200" placeholder="Como fala" /></label>
          </div>
          <div class="row wrap">
            <label class="grow">Quer <input v-model="n.wants" maxlength="200" /></label>
            <label class="grow">Segredo <input v-model="n.secret" maxlength="300" /></label>
          </div>
          <button type="button" class="link danger" @click="c.npcs.splice(i, 1)">Remover</button>
        </fieldset>
        <button type="button" class="small-btn" @click="c.npcs.push({ name: '', voice: '', wants: '', secret: '' })">+ PdM</button>

        <h2>Relógios de ameaça</h2>
        <div v-for="(k, i) in c.clocks" :key="i" class="row">
          <input v-model="k.label" maxlength="80" placeholder="Ex.: Cheia do rio" aria-label="Relógio" required class="grow" />
          <label class="mini">Partes <input v-model.number="k.segments" type="number" min="2" max="12" /></label>
          <button type="button" class="link" aria-label="Remover relógio" @click="c.clocks.splice(i, 1)">×</button>
        </div>
        <button type="button" class="small-btn" @click="c.clocks.push({ label: '', segments: 4 })">+ relógio</button>

        <h2>O que cada personagem quer</h2>
        <div v-for="(w, i) in c.wants" :key="i" class="row">
          <input v-model="w.character" maxlength="80" placeholder="Personagem" aria-label="Personagem" required />
          <input v-model="w.text" maxlength="300" placeholder="O que ele quer nesta sessão" aria-label="Desejo" class="grow" />
          <button type="button" class="link" aria-label="Remover" @click="c.wants.splice(i, 1)">×</button>
        </div>
        <button type="button" class="small-btn" @click="c.wants.push({ character: '', text: '' })">+ desejo</button>

        <div class="save-bar">
          <span class="muted" role="status">{{ status }}</span>
          <button type="submit" class="primary">Salvar roteiro</button>
          <a v-if="ready" class="button" :href="`#/mesa/${campaignId}`">Começar a sessão com ele</a>
        </div>
      </form>
    </div>
  </main>
</template>
