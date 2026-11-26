<script setup lang="ts">
import { computed, ref } from 'vue'
import SceneMap, { type MapSubject } from '../../components/live/SceneMap.vue'
import ActionDialog from '../../components/ui/ActionDialog.vue'
import type { MapPin } from '../../live/types'
import { useMaster } from './context'

/**
 * The scene map editor (NT-15): an image of the campaign, pins the master drags, and whether
 * the players see it. Every change is saved at once (own revision; another window's change is
 * merged by retrying on the latest revision).
 */
const emit = defineEmits<{ close: [] }>()
const m = useMaster()
const s = computed(() => m.s.value)
const map = computed(() => m.map.value ?? { revision: 0, image_id: null, shown: false, pins: [] as MapPin[] })
const subjects = computed<MapSubject[]>(() => [
  ...s.value.characters.map(c => ({ kind: 'character' as const, id: c.id, name: c.name, portrait: c.portrait_image_id ?? null, live: c.live })),
  ...s.value.npcs.map(n => ({ kind: 'npc' as const, id: n.id, name: n.name, portrait: n.portrait_image_id ?? null, live: n.live })),
])
const roster = computed(() => subjects.value.filter(x => x.kind === 'character' || m.inScene.value.includes(x.id)))
const pinned = (id: string) => map.value.pins.some(p => p.id === id)
const file = ref<HTMLInputElement | null>(null)
const busy = ref(false)
function save(patch: Partial<{ image_id: string | null; shown: boolean; pins: MapPin[] }>): void {
  void m.saveMap({ image_id: map.value.image_id, shown: map.value.shown, pins: map.value.pins, ...patch })
}
function toggle(x: MapSubject): void {
  if (pinned(x.id)) save({ pins: map.value.pins.filter(p => p.id !== x.id) })
  else {
    const n = map.value.pins.length
    save({ pins: [...map.value.pins, { kind: x.kind, id: x.id, x: 0.2 + (n % 6) * 0.12, y: 0.2 + Math.floor(n / 6) * 0.15 }] })
  }
}
async function pick(ev: Event): Promise<void> {
  const f = (ev.target as HTMLInputElement).files?.[0]
  if (!f) return
  busy.value = true
  const id = await m.upload(f, 'scene')
  busy.value = false
  if (file.value) file.value.value = ''
  if (id) save({ image_id: id })
}
</script>

<template>
  <ActionDialog title="Mapa da cena" wide @close="emit('close')" @submit="emit('close')">
    <div class="map-tools">
      <button type="button" class="ghost" :disabled="busy" @click="file?.click()">{{ busy ? 'Enviando…' : map.image_id ? 'Trocar imagem' : 'Enviar imagem' }}</button>
      <input ref="file" type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" aria-label="Imagem do mapa" @change="pick" />
      <label class="check"><input type="checkbox" :checked="map.shown" @change="save({ shown: !map.shown })" /> Mostrar aos jogadores</label>
      <span class="muted small">{{ map.shown ? 'Os jogadores veem o mapa (PdMs só depois de apresentados).' : 'Só você vê.' }}</span>
    </div>
    <SceneMap :image-id="map.image_id" :pins="map.pins" :subjects="subjects" editable @move="pins => save({ pins })" />
    <div>
      <p class="muted small">Toque para pôr ou tirar do mapa; arraste para mover (ou use as setas com o pin selecionado).</p>
      <div class="map-roster">
        <button v-for="x in roster" :key="x.id" type="button" class="chip" :class="{ on: pinned(x.id) }" :aria-pressed="pinned(x.id)" @click="toggle(x)">{{ x.kind === 'npc' ? '◆ ' : '' }}{{ x.name }}</button>
      </div>
    </div>
    <template #actions><span class="grow" /><button type="submit" class="primary">Pronto</button></template>
  </ActionDialog>
</template>
