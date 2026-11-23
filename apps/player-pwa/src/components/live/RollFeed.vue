<script setup lang="ts">
import { computed } from 'vue'
import { faceCounts, outcomeText } from '../../live/dice'
import type { OfficialRoll } from '../../live/types'

/** Official results, newest first. Masters can reveal secret rolls and replay any result. */
const props = defineProps<{ rolls: OfficialRoll[]; master?: boolean; limit?: number; meId?: string }>()
defineEmits<{ reveal: [roll: OfficialRoll]; replay: [roll: OfficialRoll] }>()
const items = computed(() => [...props.rolls].reverse().slice(0, props.limit ?? 40))
const time = (iso: string) => new Date(iso).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
</script>

<template>
  <ol class="feed" aria-label="Rolagens oficiais">
    <li v-for="r in items" :key="r.id" class="feed-item" :class="{ win: r.succeeded === true, lose: r.succeeded === false, secret: r.secret && !r.revealed }">
      <div class="feed-top">
        <strong>{{ r.subject.name }}</strong>
        <span class="muted small">{{ r.label }}</span>
        <span v-if="r.secret && !r.revealed" class="pill secret">{{ r.roller_user_id === meId ? 'entre você e o mestre' : 'secreta' }}</span>
        <time class="muted small" :datetime="r.created_at">{{ time(r.created_at) }}</time>
      </div>
      <div class="feed-faces">
        <span v-for="(f, i) in r.results" :key="i" class="face" :class="{ counts: faceCounts(r, f) && r.dice.reading !== 'sum' }">{{ f }}</span>
        <span class="feed-outcome">{{ outcomeText(r) }}</span>
      </div>
      <div class="feed-actions">
        <button type="button" class="link" @click="$emit('replay', r)">Ver de novo</button>
        <button v-if="master && r.secret && !r.revealed" type="button" class="link" @click="$emit('reveal', r)">Revelar à mesa</button>
      </div>
    </li>
    <li v-if="!items.length" class="muted">Nenhuma rolagem oficial ainda.</li>
  </ol>
</template>
