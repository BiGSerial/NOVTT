<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { imageUrl } from '../../live/api'
import type { LiveState } from '../../live/types'

/**
 * A character or NPC portrait token: the uploaded image, or an original heraldic medallion drawn
 * from the name (colours and sigil are deterministic). The ring shows the first resource track
 * (usually vitality) so a glance at the cast reads the table's health.
 */
const props = withDefaults(defineProps<{
  name: string
  imageId?: string | null
  live?: LiveState | null
  size?: number
  kind?: 'character' | 'npc' | 'threat'
  ring?: boolean
}>(), { size: 56, kind: 'character', ring: true, imageId: null, live: null })

function hash(s: string): number {
  let h = 2166136261
  for (const ch of s) h = Math.imul(h ^ ch.charCodeAt(0), 16777619)
  return h >>> 0
}
const h = computed(() => hash(props.name))
const hue = computed(() => h.value % 360)
const sigil = computed(() => h.value % 6)
const initials = computed(() => props.name.replace(/\(.*?\)/g, '').trim().split(/\s+/).filter(w => w.length > 2 || /^[A-ZÀ-Ý]/.test(w)).slice(0, 2).map(w => w[0]).join('').toUpperCase() || '?')
const track = computed(() => props.live?.resources?.[0] ?? null)
const pct = computed(() => (track.value && track.value.max > 0 ? Math.max(0, Math.min(1, track.value.current / track.value.max)) : null))
const state = computed(() => (pct.value === null ? '' : pct.value <= 0 ? 'down' : pct.value <= 1 / 3 ? 'low' : pct.value <= 2 / 3 ? 'mid' : 'ok'))
const failed = ref(false)
watch(() => props.imageId, () => { failed.value = false })
const src = computed(() => (failed.value ? null : imageUrl(props.imageId)))
const SIGILS = [
  'M50 22 L58 42 L78 42 L62 54 L68 74 L50 62 L32 74 L38 54 L22 42 L42 42 Z',
  'M50 20 C66 34 70 50 50 80 C30 50 34 34 50 20 Z',
  'M30 30 L70 30 L70 54 C70 68 60 76 50 80 C40 76 30 68 30 54 Z',
  'M50 22 A28 28 0 1 0 50 78 A20 20 0 1 1 50 22 Z',
  'M24 70 L38 34 L50 56 L62 34 L76 70 Z',
  'M50 20 L80 50 L50 80 L20 50 Z',
]
</script>

<template>
  <span
    class="portrait" :class="[kind, state, { ringed: ring && pct !== null }]"
    :style="{ '--size': `${size}px`, '--hue': hue, '--pct': pct === null ? 1 : pct }"
    role="img" :aria-label="pct !== null && track ? `${name}, ${track.label} ${track.current} de ${track.max}` : name"
  >
    <span class="portrait-face">
      <img v-if="src" :src="src" alt="" loading="lazy" decoding="async" @error="failed = true" />
      <svg v-else viewBox="0 0 100 100" aria-hidden="true" focusable="false">
        <defs>
          <radialGradient :id="`pg${h}`" cx=".35" cy=".3" r=".9">
            <stop offset="0" :stop-color="`hsl(${hue} 45% 42%)`" />
            <stop offset="1" :stop-color="`hsl(${(hue + 40) % 360} 50% 14%)`" />
          </radialGradient>
        </defs>
        <rect width="100" height="100" :fill="`url(#pg${h})`" />
        <path :d="SIGILS[sigil]" :fill="`hsl(${hue} 60% 75%)`" opacity=".22" />
        <path d="M50 30 a14 15 0 1 1 -0.1 0 Z M22 100 C24 74 36 64 50 64 C64 64 76 74 78 100 Z" :fill="`hsl(${hue} 30% 8%)`" opacity=".55" />
        <text x="50" y="58" text-anchor="middle" class="portrait-initials">{{ initials }}</text>
      </svg>
    </span>
  </span>
</template>
