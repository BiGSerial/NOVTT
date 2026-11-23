<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { picture, starfield } from '../../live/art'
import { imageUrl } from '../../live/api'
import type { ArtKey } from '../../live/types'

/**
 * A scene's illustration: the master's uploaded image when there is one (and it loads), else
 * NOVTT's original drawing for the chosen setting, recoloured by the campaign theme. Decorative:
 * the scene title next to it carries the meaning.
 */
const props = defineProps<{ art?: ArtKey | null; imageId?: string | null }>()
const pic = computed(() => picture(props.art))
const stars = computed(() => starfield(pic.value.stars, (props.art ?? 'road').length * 13))
const failed = ref(false)
watch(() => props.imageId, () => { failed.value = false })
const src = computed(() => (failed.value ? null : imageUrl(props.imageId)))
const uid = `sa${Math.random().toString(36).slice(2, 8)}`
</script>

<template>
  <div class="scene-art" aria-hidden="true">
    <img v-if="src" :src="src" alt="" loading="lazy" decoding="async" @error="failed = true" />
    <svg v-else viewBox="0 0 1200 400" preserveAspectRatio="xMidYMid slice" focusable="false">
      <defs>
        <linearGradient :id="`${uid}-sky`" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" style="stop-color: var(--art-sky-top)" />
          <stop offset="1" style="stop-color: var(--art-sky-bottom)" />
        </linearGradient>
        <radialGradient :id="`${uid}-moon`">
          <stop offset="0" style="stop-color: var(--art-light)" stop-opacity=".55" />
          <stop offset="1" style="stop-color: var(--art-light)" stop-opacity="0" />
        </radialGradient>
      </defs>
      <rect width="1200" height="400" :fill="`url(#${uid}-sky)`" />
      <circle v-for="(s, i) in stars" :key="i" :cx="s.x" :cy="s.y" :r="s.r" style="fill: var(--art-light)" opacity=".55" />
      <template v-if="pic.moon">
        <circle :cx="pic.moon.x" :cy="pic.moon.y" :r="pic.moon.r * 3.2" :fill="`url(#${uid}-moon)`" />
        <circle :cx="pic.moon.x" :cy="pic.moon.y" :r="pic.moon.r" style="fill: var(--art-light)" opacity=".92" />
      </template>
      <path v-for="(l, i) in pic.layers" :key="i" :d="l.d" fill-rule="evenodd" :class="`art-${l.fill}`" :opacity="l.opacity ?? 1" />
    </svg>
  </div>
</template>
