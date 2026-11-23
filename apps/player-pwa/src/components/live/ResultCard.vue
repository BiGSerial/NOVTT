<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { describeDice, faceCounts } from '../../live/dice'
import type { OfficialRoll } from '../../live/types'

/**
 * The official result, brought to the front once the dice settle (SPEC-MVP-002 D4). It docks
 * under the dice (which stay on the table) instead of covering them, and leaves on tap / Esc or
 * after a while. Who saw it is part of the message: "só você e o mestre" for private rolls.
 */
const props = defineProps<{ roll: OfficialRoll | null; meId?: string }>()
defineEmits<{ close: [] }>()
const box = ref<HTMLElement | null>(null)
const verdict = computed(() => (props.roll?.succeeded === true ? 'win' : props.roll?.succeeded === false ? 'lose' : 'plain'))
const headline = computed(() => (verdict.value === 'win' ? 'Sucesso' : verdict.value === 'lose' ? 'Falha' : ''))
const value = computed(() => {
  const r = props.roll
  if (!r) return ''
  return r.dice.reading === 'successes' ? `${r.successes} ${r.successes === 1 ? 'sucesso' : 'sucessos'}` : `${r.total}`
})
const combo = computed(() => {
  const r = props.roll
  if (!r) return ''
  const parts = r.components.map(c => (c.value === undefined ? c.label : `${c.label} ${c.value}`))
  if (r.modifier) parts.push(r.modifier > 0 ? `+${r.modifier}` : `${r.modifier}`)
  return parts.join(' + ')
})
const audience = computed(() => (props.roll?.secret && !props.roll.revealed ? (props.roll.roller_user_id === props.meId ? 'Só você e o mestre veem' : 'Secreta') : props.roll?.revealed ? 'Revelada à mesa' : 'À vista da mesa'))
watch(() => props.roll, async r => { if (r) { await nextTick(); box.value?.focus({ preventScroll: true }) } })
</script>

<template>
  <Transition name="rise">
    <div v-if="roll" class="result-dock">
      <section ref="box" :key="roll.id" class="result-card" :class="verdict" role="dialog" aria-modal="false" aria-labelledby="result-title" tabindex="-1" @keydown.escape="$emit('close')" @click="$emit('close')">
        <p class="result-who"><span>{{ roll.subject.name }}</span><span class="pill" :class="{ secret: roll.secret && !roll.revealed }">{{ audience }}</span></p>
        <h2 id="result-title">{{ roll.label }}</h2>
        <p v-if="headline" class="result-verdict" aria-live="assertive">{{ headline }}</p>
        <p class="result-value" :aria-live="headline ? undefined : 'assertive'">{{ value }}</p>
        <ul class="result-faces" aria-label="Faces">
          <li v-for="(f, i) in roll.results" :key="i" :class="{ counts: faceCounts(roll, f) && roll.dice.reading !== 'sum' }" :style="{ '--i': i }">{{ f }}</li>
        </ul>
        <p v-if="combo" class="result-combo">{{ combo }}</p>
        <p class="result-dice">{{ describeDice(roll.dice, roll.results.length) }} · tentativa {{ roll.attempt }}/{{ roll.attempts_allowed }}</p>
        <p class="result-hint">Toque para fechar</p>
      </section>
    </div>
  </Transition>
</template>
