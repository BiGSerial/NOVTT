<script setup lang="ts">
import { ref } from 'vue'
import type { Item } from '../../live/types'

/** Items carried (live state). The master grants, spends and removes; players read their own. */
const props = defineProps<{ items: Item[]; editable?: boolean; busy?: boolean }>()
const emit = defineEmits<{ save: [items: Item[]] }>()
const label = ref('')
function add(): void {
  const l = label.value.trim().slice(0, 80)
  if (!l) return
  const found = props.items.find(i => i.label.toLowerCase() === l.toLowerCase())
  emit('save', found ? props.items.map(i => (i === found ? { ...i, qty: Math.min(999, i.qty + 1) } : i)) : [...props.items, { id: `i${Date.now().toString(36)}`, label: l, qty: 1 }])
  label.value = ''
}
const qty = (it: Item, d: number) => emit('save', props.items.map(i => (i.id === it.id ? { ...i, qty: Math.max(0, Math.min(999, i.qty + d)) } : i)).filter(i => i.qty > 0 || i.id !== it.id || d > 0))
</script>

<template>
  <div class="inventory">
    <ul>
      <li v-for="it in items" :key="it.id" class="item">
        <span class="item-qty">{{ it.qty }}×</span><span class="item-label">{{ it.label }}<small v-if="it.note">{{ it.note }}</small></span>
        <span v-if="editable" class="item-steps">
          <button type="button" :disabled="busy" :aria-label="`Gastar ${it.label}`" @click="qty(it, -1)">−</button>
          <button type="button" :disabled="busy" :aria-label="`Mais ${it.label}`" @click="qty(it, 1)">+</button>
        </span>
      </li>
      <li v-if="!items.length" class="muted small">Nada na mochila.</li>
    </ul>
    <form v-if="editable" class="row" @submit.prevent="add">
      <input v-model="label" maxlength="80" placeholder="Dar um item (ex.: Corda, Tocha)" aria-label="Novo item" />
      <button type="submit" class="small-btn" :disabled="busy">Dar</button>
    </form>
  </div>
</template>
