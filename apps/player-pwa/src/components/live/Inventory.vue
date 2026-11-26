<script setup lang="ts">
import { ref } from 'vue'
import type { Item } from '../../live/types'
import ActionDialog from '../ui/ActionDialog.vue'

/** Items carried (live state). The master grants, spends and removes; players read their own. */
const props = defineProps<{ items: Item[]; editable?: boolean; busy?: boolean; givable?: boolean }>()
const emit = defineEmits<{ save: [items: Item[]]; give: [item: Item] }>()
const giving = ref(false)
const form = ref({ label: '', qty: 1, note: '' })
function open(): void { form.value = { label: '', qty: 1, note: '' }; giving.value = true }
function add(): void {
  const l = form.value.label.trim().slice(0, 80)
  if (!l) return
  const n = Math.max(1, Math.min(999, Math.round(form.value.qty || 1)))
  const note = form.value.note.trim().slice(0, 120)
  const found = props.items.find(i => i.label.toLowerCase() === l.toLowerCase())
  emit('save', found
    ? props.items.map(i => (i === found ? { ...i, qty: Math.min(999, i.qty + n), ...(note ? { note } : {}) } : i))
    : [...props.items, { id: `i${Date.now().toString(36)}`, label: l, qty: n, ...(note ? { note } : {}) }])
  giving.value = false
}
const qty = (it: Item, d: number) => emit('save', props.items.map(i => (i.id === it.id ? { ...i, qty: Math.max(0, Math.min(999, i.qty + d)) } : i)).filter(i => i.qty > 0 || i.id !== it.id || d > 0))
</script>

<template>
  <div class="inventory">
    <ul>
      <li v-for="it in items" :key="it.id" class="item">
        <span class="item-qty">{{ it.qty }}×</span><span class="item-label">{{ it.label }}<small v-if="it.note">{{ it.note }}</small></span>
        <button v-if="givable" type="button" class="link item-give" :aria-label="`Dar ${it.label} a alguém`" @click="emit('give', it)">Dar…</button>
        <span v-if="editable" class="item-steps">
          <button type="button" :disabled="busy" :aria-label="`Gastar ${it.label}`" @click="qty(it, -1)">−</button>
          <button type="button" :disabled="busy" :aria-label="`Mais ${it.label}`" @click="qty(it, 1)">+</button>
        </span>
      </li>
      <li v-if="!items.length" class="muted small">Nada na mochila.</li>
    </ul>
    <button v-if="editable" type="button" class="small-btn give-btn" aria-haspopup="dialog" :disabled="busy" @click="open">+ Dar item</button>
    <ActionDialog v-if="giving" title="Dar um item" @close="giving = false" @submit="add">
      <label>Item <input v-model="form.label" maxlength="80" required placeholder="Ex.: Corda, Tocha, Poção de cura" list="inv-known" /></label>
      <datalist id="inv-known"><option v-for="it in items" :key="it.id" :value="it.label" /></datalist>
      <div class="field-row">
        <span class="field-label">Quantidade</span>
        <span class="stepper-inline">
          <button type="button" class="step" aria-label="Menos" :disabled="form.qty <= 1" @click="form.qty--">−</button>
          <output aria-live="polite">{{ form.qty }}</output>
          <button type="button" class="step" aria-label="Mais" @click="form.qty++">+</button>
        </span>
      </div>
      <label>Nota (opcional) <input v-model="form.note" maxlength="120" placeholder="Ex.: brilha perto de mortos-vivos" /></label>
      <template #actions><span class="grow" /><button type="button" class="ghost" @click="giving = false">Cancelar</button><button type="submit" class="primary" :disabled="!form.label.trim() || busy">Dar</button></template>
    </ActionDialog>
  </div>
</template>
