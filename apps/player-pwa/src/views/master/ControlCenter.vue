<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import Portrait from '../../components/live/Portrait.vue'
import { world } from '../../live/api'
import { oracle } from '../../live/oracle'
import type { RoteiroContent } from '../../live/types'
import { useMaster } from './context'

/**
 * The centre of the console when nobody is selected (NT-12): the current scene's prep as a
 * narration aid. What to read, who is on stage (how they talk, what they want, what they hide),
 * what is still open (complication, hidden clues), what each character wants, the place and the
 * rewards. Everything here is master-only prep; nothing is sent to the table without a click.
 */
const m = useMaster()
const s = computed(() => m.s.value)
const prep = ref<Partial<RoteiroContent> | null>(null)
const loaded = ref(false)
onMounted(async () => {
  const id = s.value.session.roteiro_id
  try {
    if (id) prep.value = (await world.roteiros(m.campaign.id)).data.find(r => r.id === id)?.content ?? null
  } catch { /* prep is a help, not a requirement: the centre still works without it */ } finally { loaded.value = true }
})

const sc = computed(() => m.currentScene.value)
const idx = computed(() => m.plot.value.current ?? -1)
const norm = (x: string) => x.trim().toLowerCase()
const readText = computed(() => sc.value?.read_aloud || (idx.value <= 0 ? prep.value?.strong_start ?? '' : ''))
const onStage = computed(() => !!m.stage.value && !!sc.value && m.stage.value.title === sc.value.title)

// NPCs of this scene: the plot's names matched to the prep (voice, wants, secret) and the cast.
const npcs = computed(() => {
  const names = sc.value?.npcs?.length ? sc.value.npcs : s.value.npcs.filter(n => m.inScene.value.includes(n.id)).map(n => n.name)
  return names.map(name => ({
    name,
    prep: prep.value?.npcs?.find(n => norm(n.name) === norm(name)) ?? null,
    cast: s.value.npcs.find(n => norm(n.name) === norm(name)) ?? null,
  }))
})
const secrets = ref<Record<string, boolean>>({})

const hidden = computed(() => s.value.clues.filter(c => !c.revealed_at && (!sc.value || c.scene === sc.value.title)))
const place = computed(() => (sc.value?.location ? prep.value?.locations?.find(l => norm(l.name) === norm(sc.value!.location!)) ?? null : null))
const wants = computed(() => s.value.characters.map(c => ({
  c,
  want: prep.value?.wants?.find(w => norm(w.character) === norm(c.name))?.text ?? '',
  spot: m.compass.spotlight[c.id] ?? 0,
  low: m.spotlightLow.value.has(c.id),
  asks: s.value.suggestions.filter(x => x.campaign_character_id === c.id).length,
})))
const twist = ref('')
const rewards = computed(() => prep.value?.rewards?.filter(Boolean) ?? [])
</script>

<template>
  <section class="control-center" aria-label="Central do mestre">
    <header class="cc-head">
      <p class="eyebrow">Central do mestre<template v-if="sc"> · cena {{ idx + 1 }} de {{ m.plot.value.scenes.length }}</template></p>
      <h2 class="cc-title">{{ sc?.title || m.compass.scene || 'Sem cena preparada' }}</h2>
      <p v-if="sc?.goal || m.compass.objective" class="cc-goal"><span>Objetivo</span>{{ sc?.goal || m.compass.objective }}</p>
    </header>

    <div class="cc-grid">
      <article v-if="readText" class="cc-card cc-read">
        <h3>Para ler em voz alta</h3>
        <blockquote>{{ readText }}</blockquote>
        <div class="cc-actions">
          <button v-if="sc && !onStage" type="button" class="small-btn" @click="m.showScene(sc)">Mostrar aos jogadores</button>
          <span v-else-if="onStage" class="pill live-pill">na tela dos jogadores</span>
        </div>
      </article>

      <article class="cc-card cc-cast">
        <h3>Quem está na cena</h3>
        <ul v-if="npcs.length" class="cc-npcs">
          <li v-for="n in npcs" :key="n.name" class="cc-npc">
            <Portrait :name="n.name" :image-id="n.cast?.portrait_image_id ?? null" :live="n.cast?.live ?? null" :size="52" kind="npc" />
            <div>
              <p class="cc-npc-name">{{ n.name }}</p>
              <p v-if="n.prep?.voice" class="cc-line"><span>Fala</span>“{{ n.prep.voice }}”</p>
              <p v-if="n.prep?.wants" class="cc-line"><span>Quer</span>{{ n.prep.wants }}</p>
              <p v-if="n.prep?.secret" class="cc-line secret">
                <span>Segredo</span>
                <template v-if="secrets[n.name]">{{ n.prep.secret }}</template>
                <button v-else type="button" class="link" @click="secrets[n.name] = true">mostrar (só você)</button>
              </p>
              <button v-if="n.cast" type="button" class="link" @click="m.select('npc', n.cast.id)">Abrir ficha</button>
            </div>
          </li>
        </ul>
        <p v-else class="muted small">Nenhum PdM nesta cena. Traga alguém da reserva no elenco (+).</p>
      </article>

      <article class="cc-card cc-open">
        <h3>Decisões em aberto</h3>
        <p v-if="sc?.complication" class="cc-line"><span>Complicação</span>{{ sc.complication }}</p>
        <p v-else-if="twist" class="cc-line"><span>Complicação</span>{{ twist }}</p>
        <button v-if="!sc?.complication" type="button" class="link" @click="twist = oracle.complication()">{{ twist ? 'outra complicação' : 'sugerir uma complicação' }}</button>
        <template v-if="hidden.length">
          <p class="cc-sub">Pistas ainda guardadas</p>
          <ul class="cc-clues">
            <li v-for="c in hidden" :key="c.id"><b>{{ c.title }}</b><span v-if="c.body"> — {{ c.body }}</span>
              <button type="button" class="link" @click="m.revealClue(c.id, 'table')">revelar à mesa</button>
            </li>
          </ul>
        </template>
        <p v-else class="muted small">Nenhuma pista guardada para esta cena.</p>
      </article>

      <article class="cc-card cc-party">
        <h3>O que cada um quer</h3>
        <ul class="cc-wants">
          <li v-for="w in wants" :key="w.c.id">
            <button type="button" class="cc-person" @click="m.select('character', w.c.id)">
              <Portrait :name="w.c.name" :image-id="w.c.portrait_image_id ?? null" :live="w.c.live" :size="40" />
              <span class="cc-person-text">
                <b>{{ w.c.name }}</b>
                <small>{{ w.want || 'Sem desejo anotado no roteiro.' }}</small>
                <small v-if="w.c.live?.conditions?.length" class="cc-conds">{{ w.c.live.conditions.join(' · ') }}</small>
              </span>
              <span class="cc-flags">
                <span v-if="w.asks" class="flag ask">✋</span>
                <span v-if="w.low" class="flag spot" title="Teve menos holofote">★ {{ w.spot }}</span>
              </span>
            </button>
          </li>
        </ul>
        <button type="button" class="link" @click="m.selectMany(s.characters.map(c => ({ kind: 'character', id: c.id })))">Selecionar o grupo todo</button>
      </article>

      <article v-if="place" class="cc-card">
        <h3>{{ place.name }}</h3>
        <ul class="cc-list"><li v-for="a in place.aspects" :key="a">{{ a }}</li></ul>
      </article>

      <article v-if="rewards.length" class="cc-card">
        <h3>Recompensas previstas</h3>
        <ul class="cc-list"><li v-for="r in rewards" :key="r">{{ r }}</li></ul>
      </article>
    </div>

    <p v-if="loaded && !prep" class="cc-noprep muted small">
      Esta sessão não tem roteiro. <a :href="`#/roteiro/${m.campaign.id}`" target="_blank" rel="noopener">Monte um no Roteiro</a> para ver falas, desejos e segredos aqui.
    </p>
  </section>
</template>
