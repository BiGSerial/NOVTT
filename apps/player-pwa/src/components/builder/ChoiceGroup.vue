<script setup lang="ts">
import { computed } from 'vue'
import type { Scalar } from '../../sheets/types'

export interface ChoiceCard { value: Scalar; name: string; summary?: string | null; available: boolean; requirement?: string | null }

/**
 * A preset choice as cards. Locked options say why ("Requer Vocação: Lâmina e Nível 3 ou
 * mais"); when nothing is available yet, the group folds into one line with the options and
 * their requirements behind a disclosure, so the step is not a wall of grey cards.
 */
const props = defineProps<{ id: string; label: string; cards: ChoiceCard[]; chosen: unknown; hideLabel?: boolean }>()
const emit = defineEmits<{ choose: [value: Scalar] }>()
const open = computed(() => props.cards.some(c => c.available) || props.chosen != null && props.chosen !== '')
const sharedReason = computed(() => {
  const reasons = [...new Set(props.cards.map(c => c.requirement).filter(Boolean))]
  return reasons.length === 1 ? reasons[0] : null
})
</script>

<template>
  <section class="choice-group" :aria-labelledby="`${id}-title`">
    <h3 :id="`${id}-title`" :class="{ 'sr-only': hideLabel }">{{ label }}</h3>
    <div v-if="open" class="choice-cards" role="radiogroup" :aria-labelledby="`${id}-title`">
      <button
        v-for="c in cards"
        :key="String(c.value)"
        type="button"
        role="radio"
        class="choice-card"
        :class="{ selected: chosen === c.value, locked: !c.available && chosen !== c.value }"
        :aria-checked="chosen === c.value ? 'true' : 'false'"
        :aria-disabled="!c.available && chosen !== c.value ? 'true' : undefined"
        @click="c.available || chosen === c.value ? emit('choose', c.value) : undefined"
      >
        <span class="choice-name">{{ c.name }}</span>
        <span v-if="c.summary" class="choice-summary">{{ c.summary }}</span>
        <span v-if="!c.available" class="choice-lock">🔒 {{ c.requirement ? `Requer ${c.requirement}` : 'Pré-requisito não atendido' }}</span>
      </button>
    </div>
    <details v-else class="choice-later">
      <summary>Ainda não disponível<template v-if="sharedReason"> · requer {{ sharedReason }}</template>. Ver opções</summary>
      <ul>
        <li v-for="c in cards" :key="String(c.value)"><b>{{ c.name }}</b><template v-if="c.requirement"> — requer {{ c.requirement }}</template></li>
      </ul>
    </details>
  </section>
</template>
