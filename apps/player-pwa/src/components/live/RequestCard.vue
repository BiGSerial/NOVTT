<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { describeDice } from '../../live/dice'
import type { RollRequest } from '../../live/types'

/**
 * A master's request as the roller sees it: the combination and the dice are locked; the player
 * only rolls (and picks how many dice when the master allowed it). One idempotency key per tap,
 * reused if the same tap is retried after a network error, so a retry never rolls twice.
 */
const props = defineProps<{ request: RollRequest; canRoll: boolean; busy?: boolean }>()
const emit = defineEmits<{ roll: [request: RollRequest, key: string, count?: number] }>()
const count = ref(props.request.max_dice)
watch(() => props.request.max_dice, m => { count.value = m })
let pendingKey: { revision: number; key: string } | null = null

const combo = computed(() => {
  const parts = props.request.components.map(c => (c.value === undefined ? c.label : `${c.label} ${c.value}`))
  if (props.request.modifier) parts.push(props.request.modifier > 0 ? `+${props.request.modifier}` : `${props.request.modifier}`)
  return parts.join(' + ')
})
const dice = computed(() => describeDice(props.request.dice, props.request.allows_choice ? `${props.request.min_dice}–${props.request.max_dice}` : props.request.max_dice))

function roll(): void {
  if (!pendingKey || pendingKey.revision !== props.request.revision) pendingKey = { revision: props.request.revision, key: crypto.randomUUID() }
  emit('roll', props.request, pendingKey.key, props.request.allows_choice ? count.value : undefined)
}
</script>

<template>
  <article class="request-card" :class="{ mine: canRoll }">
    <header>
      <p class="eyebrow">{{ canRoll ? 'O mestre pede' : `Pedido para ${request.subject.name}` }}<span v-if="request.secret" class="pill secret">secreta</span></p>
      <h3>{{ request.label }}</h3>
    </header>
    <p class="request-combo">{{ combo }}</p>
    <p class="request-dice"><span class="lock" aria-hidden="true">🔒</span> {{ dice }}</p>
    <p class="muted small">Tentativa {{ request.attempts_consumed + 1 }} de {{ request.attempts_allowed }}</p>
    <label v-if="canRoll && request.allows_choice" class="mini">Quantos dados
      <input v-model.number="count" type="range" :min="request.min_dice" :max="request.max_dice" />
      <output>{{ count }}</output>
    </label>
    <button v-if="canRoll" type="button" class="primary roll-btn" :disabled="busy" @click="roll">Rolar {{ request.allows_choice ? count : request.max_dice }}d{{ request.dice.faces }}</button>
  </article>
</template>
