<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import Portrait from '../../components/live/Portrait.vue'
import ActionDialog from '../../components/ui/ActionDialog.vue'
import type { Place } from '../../live/types'
import { useMaster } from './context'

/**
 * Split the party into places (NT-13): name the places, then put each character in one, or
 * "com o grupo" (unplaced). Same place = near (can trade, can help); different places = far.
 */
const emit = defineEmits<{ close: [] }>()
const m = useMaster()
const characters = computed(() => m.s.value.characters)
const draft = reactive<{ places: { id: string; name: string }[]; where: Record<string, string> }>({
  places: (m.places.value?.places ?? []).map(p => ({ id: p.id, name: p.name })),
  where: Object.fromEntries(characters.value.map(c => [c.id, m.places.value?.places.find(p => p.character_ids.includes(c.id))?.id ?? ''])),
})
const newName = ref('')
const slug = (n: string) => (n.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 30) || 'local') + '-' + Math.random().toString(36).slice(2, 6)
function addPlace(): void {
  const name = newName.value.trim()
  if (!name || draft.places.length >= 12) return
  draft.places.push({ id: slug(name), name })
  newName.value = ''
}
function removePlace(id: string): void {
  draft.places = draft.places.filter(p => p.id !== id)
  for (const c of Object.keys(draft.where)) if (draft.where[c] === id) draft.where[c] = ''
}
async function save(): Promise<void> {
  const list: Place[] = draft.places.map(p => ({ ...p, character_ids: characters.value.filter(c => draft.where[c.id] === p.id).map(c => c.id) }))
  if (await m.savePlaces(list.filter(p => p.character_ids.length || draft.places.length))) emit('close')
}
async function together(): Promise<void> { if (await m.savePlaces([])) emit('close') }
</script>

<template>
  <ActionDialog title="Onde cada um está" wide @close="emit('close')" @submit="save">
    <p class="muted small">Quem está no mesmo local está perto: pode trocar itens e se ajudar. Quem está em outro local aparece como longe para o grupo.</p>
    <div class="places-new">
      <label>Novo local <input v-model="newName" maxlength="60" placeholder="Ex.: Na ponte, Na taverna" @keydown.enter.prevent="addPlace" /></label>
      <button type="button" class="ghost" :disabled="!newName.trim()" @click="addPlace">Adicionar</button>
    </div>
    <ul v-if="draft.places.length" class="places-list">
      <li v-for="p in draft.places" :key="p.id" class="tag">🧭 <input v-model="p.name" maxlength="60" :aria-label="`Nome do local ${p.name}`" /> <button type="button" class="link danger" :aria-label="`Remover ${p.name}`" @click="removePlace(p.id)">×</button></li>
    </ul>
    <ul class="places-people">
      <li v-for="c in characters" :key="c.id">
        <Portrait :name="c.name" :image-id="c.portrait_image_id ?? null" :live="c.live" :size="36" />
        <b>{{ c.name }}</b>
        <select v-model="draft.where[c.id]" :aria-label="`Onde está ${c.name}`">
          <option value="">Com o grupo</option>
          <option v-for="p in draft.places" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
      </li>
    </ul>
    <template #actions>
      <button type="button" class="link" @click="together">Juntar todos</button>
      <span class="grow" />
      <button type="button" class="ghost" @click="emit('close')">Cancelar</button>
      <button type="submit" class="primary">Salvar</button>
    </template>
  </ActionDialog>
</template>
