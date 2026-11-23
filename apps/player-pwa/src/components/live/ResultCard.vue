<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { describeDice, faceCounts, outcomeText } from '../../live/dice'
import type { OfficialRoll } from '../../live/types'

/** The official result, brought to the front once the dice settle (SPEC-MVP-002 D4). */
const props = defineProps<{ roll: OfficialRoll | null; meId?: string }>()
defineEmits<{ close: [] }>()
const box = ref<HTMLElement | null>(null)
const verdict = computed(() => (props.roll?.succeeded === true ? 'win' : props.roll?.succeeded === false ? 'lose' : 'plain'))
const combo = computed(() => {
  const r = props.roll
  if (!r) return ''
  const parts = r.components.map(c => (c.value === undefined ? c.label : `${c.label} ${c.value}`))
  if (r.modifier) parts.push(r.modifier > 0 ? `+${r.modifier}` : `${r.modifier}`)
  return parts.join(' + ')
})
watch(() => props.roll, async r => { if (r) { await nextTick(); box.value?.focus() } })
</script>

<template>
  <div v-if="roll" class="result-veil" @click.self="$emit('close')">
    <section ref="box" class="result-card" :class="verdict" role="dialog" aria-modal="false" aria-labelledby="result-title" tabindex="-1" @keydown.escape="$emit('close')">
      <p class="result-who">{{ roll.subject.name }}<span v-if="roll.secret && !roll.revealed" class="pill secret">secreta</span><span v-if="roll.revealed" class="pill">revelada</span></p>
      <h2 id="result-title">{{ roll.label }}</h2>
      <p v-if="combo" class="result-combo">{{ combo }}</p>
      <ul class="result-faces" aria-label="Faces">
        <li v-for="(f, i) in roll.results" :key="i" :class="{ counts: faceCounts(roll, f) && roll.dice.reading !== 'sum' }">{{ f }}</li>
      </ul>
      <p class="result-outcome" aria-live="assertive">{{ outcomeText(roll) }}</p>
      <p class="result-dice">{{ describeDice(roll.dice, roll.results.length) }} · tentativa {{ roll.attempt }}/{{ roll.attempts_allowed }}</p>
      <button type="button" class="ghost" @click="$emit('close')">Fechar</button>
    </section>
  </div>
</template>
