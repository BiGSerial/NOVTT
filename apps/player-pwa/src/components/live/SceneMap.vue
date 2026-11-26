<script setup lang="ts">
import { computed, ref } from 'vue'
import Portrait from './Portrait.vue'
import { imageUrl } from '../../live/api'
import type { LiveState, MapPin } from '../../live/types'

/**
 * A scene map: the image and the pins on it (fractions of the image, so it scales). Read-only for
 * players; in `editable` mode the master drags pins (pointer events: mouse, touch, pen) and the
 * parent saves on release. A table aid, not a tactical grid.
 */
export interface MapSubject { kind: 'character' | 'npc'; id: string; name: string; portrait: string | null; live?: LiveState | null }
const props = defineProps<{ imageId: string | null; pins: MapPin[]; subjects: MapSubject[]; editable?: boolean; highlight?: string | null }>()
const emit = defineEmits<{ move: [pins: MapPin[]] }>()
const box = ref<HTMLElement | null>(null)
const dragging = ref<string | null>(null)
const local = ref<MapPin[] | null>(null)
const shown = computed(() => local.value ?? props.pins)
const subject = (id: string) => props.subjects.find(s => s.id === id)
function down(ev: PointerEvent, pin: MapPin): void {
  if (!props.editable) return
  dragging.value = pin.id
  local.value = props.pins.map(p => ({ ...p }))
  ;(ev.target as HTMLElement).setPointerCapture?.(ev.pointerId)
}
function moveTo(ev: PointerEvent): void {
  if (!dragging.value || !box.value || !local.value) return
  const r = box.value.getBoundingClientRect()
  const x = Math.min(1, Math.max(0, (ev.clientX - r.left) / r.width))
  const y = Math.min(1, Math.max(0, (ev.clientY - r.top) / r.height))
  local.value = local.value.map(p => (p.id === dragging.value ? { ...p, x: Math.round(x * 10000) / 10000, y: Math.round(y * 10000) / 10000 } : p))
}
function up(): void {
  if (dragging.value && local.value) emit('move', local.value)
  dragging.value = null
  local.value = null
}
// Keyboard: arrows nudge a focused pin (accessibility for the editor).
function key(ev: KeyboardEvent, pin: MapPin): void {
  if (!props.editable) return
  const d = ev.shiftKey ? 0.05 : 0.01
  const dx = ev.key === 'ArrowLeft' ? -d : ev.key === 'ArrowRight' ? d : 0
  const dy = ev.key === 'ArrowUp' ? -d : ev.key === 'ArrowDown' ? d : 0
  if (!dx && !dy) return
  ev.preventDefault()
  emit('move', props.pins.map(p => (p.id === pin.id ? { ...p, x: Math.min(1, Math.max(0, p.x + dx)), y: Math.min(1, Math.max(0, p.y + dy)) } : p)))
}
</script>

<template>
  <div ref="box" class="scene-map" :class="{ editable }" @pointermove="moveTo" @pointerup="up" @pointercancel="up">
    <img v-if="imageId" :src="imageUrl(imageId)!" alt="Mapa da cena" draggable="false" />
    <div v-else class="scene-map-empty">Sem imagem. {{ editable ? 'Envie um mapa ou uma ilustração da cena.' : '' }}</div>
    <button
      v-for="p in shown" :key="p.id" type="button" class="map-pin" :class="[p.kind, { dragging: dragging === p.id, me: highlight === p.id }]"
      :style="{ left: `${p.x * 100}%`, top: `${p.y * 100}%` }" :aria-label="`${subject(p.id)?.name ?? 'Pin'}${editable ? ' (arraste ou use as setas)' : ''}`"
      :tabindex="editable ? 0 : -1" @pointerdown="down($event, p)" @keydown="key($event, p)"
    >
      <Portrait :name="subject(p.id)?.name ?? '?'" :image-id="subject(p.id)?.portrait ?? null" :live="subject(p.id)?.live ?? null" :size="40" :kind="p.kind" />
      <span class="map-pin-label">{{ subject(p.id)?.name }}</span>
    </button>
  </div>
</template>
