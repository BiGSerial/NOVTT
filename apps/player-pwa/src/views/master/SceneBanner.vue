<script setup lang="ts">
import { computed, ref } from 'vue'
import ClockDial from '../../components/live/ClockDial.vue'
import SceneArt from '../../components/live/SceneArt.vue'
import { ART_LABELS } from '../../live/art'
import { oracle } from '../../live/oracle'
import { ART_KEYS, type ArtKey } from '../../live/types'
import { useMaster } from './context'
import WeatherControl from './WeatherControl.vue'

/**
 * The scene on top of the console: where the story is, what it must resolve, the threat clocks,
 * and the controls to walk the plot (previous / next scene, show it on the players' screens).
 */
const m = useMaster()
const sc = computed(() => m.currentScene.value)
const idx = computed(() => m.plot.value.current ?? -1)
const total = computed(() => m.plot.value.scenes.length)
const next = computed(() => m.plot.value.scenes[idx.value + 1] ?? null)
const onStage = computed(() => !!m.stage.value && !!sc.value && m.stage.value.title === sc.value.title)
const readOpen = ref(false)
const adding = ref(false)
const draft = ref({ title: '', goal: '', art: 'road' as ArtKey, read_aloud: '' })
function improvise(): void {
  draft.value = { title: '', goal: '', art: 'road', read_aloud: '' }
  adding.value = true
}
async function saveAdhoc(now: boolean): Promise<void> {
  if (!draft.value.title.trim()) return
  await m.addScene({ title: draft.value.title.trim(), goal: draft.value.goal.trim(), art: draft.value.art, read_aloud: draft.value.read_aloud.trim(), npcs: [], complication: '' }, now)
  adding.value = false
}
const newClock = ref('')
function addClock(): void { m.addClock(newClock.value.trim() || 'Nova ameaça'); newClock.value = '' }
const twist = ref('')
</script>

<template>
  <section class="scene-banner" aria-label="Cena atual">
    <SceneArt :art="sc?.art ?? 'road'" :image-id="sc?.image_id ?? null" />
    <div class="scene-shade" />
    <div class="scene-body">
      <div class="scene-main">
        <p class="scene-kicker">
          <template v-if="sc">Cena {{ idx + 1 }} de {{ total }}<template v-if="sc.location"> · {{ sc.location }}</template></template>
          <template v-else>Sem cena preparada</template>
          <span v-if="onStage" class="pill live-pill">na tela dos jogadores</span>
        </p>
        <h1 class="scene-title">{{ sc?.title || m.compass.scene || 'Improvise a abertura' }}</h1>
        <p class="scene-goal"><span>Objetivo</span>{{ sc?.goal || m.compass.objective || 'O que esta cena precisa resolver?' }}</p>
        <p v-if="sc?.complication" class="scene-twist"><span>Complicação</span>{{ sc.complication }}</p>
        <p v-else-if="twist" class="scene-twist"><span>Complicação</span>{{ twist }}</p>
        <div class="scene-actions">
          <button type="button" class="ghost on-art" :disabled="idx <= 0" @click="m.goToScene(idx - 1)">◀ Anterior</button>
          <button v-if="next" type="button" class="primary" @click="m.goToScene(idx + 1)">Próxima cena: {{ next.title }} ▶</button>
          <button v-else type="button" class="primary" @click="improvise">+ Cena improvisada</button>
          <button v-if="sc && !onStage" type="button" class="ghost on-art" @click="m.showScene(sc)">Mostrar aos jogadores</button>
          <button v-else-if="onStage" type="button" class="ghost on-art" @click="m.showStage(null)">Recolher da tela</button>
          <button v-if="sc?.read_aloud" type="button" class="ghost on-art" :aria-expanded="readOpen" @click="readOpen = !readOpen">Ler em voz alta</button>
          <button v-if="!sc?.complication" type="button" class="ghost on-art" title="Uma complicação improvisada (só para você)" @click="twist = oracle.complication()">Complicar</button>
          <WeatherControl />
        </div>
        <blockquote v-if="readOpen && sc?.read_aloud" class="read-aloud">{{ sc.read_aloud }}</blockquote>
      </div>
      <div class="scene-clocks" aria-label="Relógios de ameaça">
        <div v-for="c in m.compass.clocks" :key="c.id" class="clock-wrap">
          <ClockDial :label="c.label" :segments="c.segments" :filled="c.filled" editable @set="f => m.setClock(c, f)" />
          <button type="button" class="clock-x" :aria-label="`Remover relógio ${c.label}`" @click="m.removeClock(c)">×</button>
        </div>
        <form class="clock-new" @submit.prevent="addClock">
          <input v-model="newClock" maxlength="80" placeholder="Nova ameaça" aria-label="Nome do novo relógio" />
          <button type="submit" class="small-btn on-art">+ relógio</button>
        </form>
      </div>
    </div>
    <ol v-if="total > 1" class="scene-steps" aria-label="Trama">
      <li v-for="(x, i) in m.plot.value.scenes" :key="x.id" :class="x.status">
        <button type="button" :aria-current="i === idx ? 'step' : undefined" :title="x.title" @click="m.goToScene(i)"><span>{{ i + 1 }}</span>{{ x.title }}</button>
      </li>
    </ol>

    <div v-if="adding" class="drawer-veil" @click.self="adding = false">
      <form class="dialog" role="dialog" aria-modal="true" aria-labelledby="adhoc-title" @submit.prevent="saveAdhoc(true)" @keydown.escape="adding = false">
        <h2 id="adhoc-title">Cena improvisada</h2>
        <label>Título <input v-model="draft.title" maxlength="120" required placeholder="Ex.: Emboscada no moinho" /></label>
        <label>O que precisa ser resolvido <input v-model="draft.goal" maxlength="300" /></label>
        <label>Texto para ler (opcional) <textarea v-model="draft.read_aloud" rows="3" maxlength="1500" /></label>
        <fieldset class="art-pick"><legend>Ilustração</legend>
          <button v-for="k in ART_KEYS" :key="k" type="button" class="art-option" :class="{ on: draft.art === k }" :aria-pressed="draft.art === k" @click="draft.art = k"><SceneArt :art="k" static /><span>{{ ART_LABELS[k] }}</span></button>
        </fieldset>
        <div class="row"><button type="submit" class="primary">Ir para esta cena</button><button type="button" class="ghost" @click="saveAdhoc(false)">Guardar para depois</button><button type="button" class="link" @click="adding = false">Cancelar</button></div>
      </form>
    </div>
  </section>
</template>
