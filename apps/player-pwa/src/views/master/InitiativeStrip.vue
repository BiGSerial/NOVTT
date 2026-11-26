<script setup lang="ts">
import { computed } from 'vue'
import Portrait from '../../components/live/Portrait.vue'
import { useMaster } from './context'

/** Turn order as a strip of tokens (players see names only, never NPC numbers). */
const m = useMaster()
const turn = computed(() => m.s.value.session.initiative?.turn ?? 0)
const round = computed(() => m.s.value.session.initiative?.round ?? 1)
const entries = computed(() => m.initiative.value.map(e => ({ ...e, cast: m.byId(e.id) })))
</script>

<template>
  <section v-if="entries.length" class="init-strip" aria-label="Iniciativa">
    <span class="init-round">Rodada <b>{{ round }}</b></span>
    <ol>
      <li v-for="(e, i) in entries" :key="e.id" :class="{ turn: i === turn, past: i < turn, npc: e.kind === 'npc' }">
        <button type="button" class="init-token" :aria-current="i === turn ? 'true' : undefined" @click="m.select(e.kind, e.id)">
          <Portrait :name="e.name" :image-id="e.cast?.portrait" :live="e.cast?.live" :size="38" :kind="e.kind" />
          <span>{{ e.name }}</span>
        </button>
        <span class="init-move">
          <button type="button" :aria-label="`Antecipar ${e.name}`" @click="m.initMove(i, -1)">‹</button>
          <button type="button" :aria-label="`Tirar ${e.name}`" @click="m.initRemove(i)">×</button>
          <button type="button" :aria-label="`Adiar ${e.name}`" @click="m.initMove(i, 1)">›</button>
        </span>
      </li>
    </ol>
    <button type="button" class="primary" @click="m.nextTurn()">Próximo ▶</button>
    <button type="button" class="link" @click="m.endInitiative()">Encerrar</button>
  </section>
</template>
