<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { picture, starfield } from '../../live/art'
import { imageUrl } from '../../live/api'
import type { ArtKey } from '../../live/types'
import { useSky } from '../../live/useAtmosphere'
import WeatherCanvas from './WeatherCanvas.vue'

/**
 * A scene's illustration: the master's uploaded image when there is one (and it loads), else
 * NOVTT's original drawing for the chosen setting, recoloured by the campaign theme. Inside a
 * live table the sky follows the session atmosphere (hour, sun and moon on their arcs, stars,
 * weather); elsewhere (Roteiro thumbnails) it is the static night drawing. Decorative: the scene
 * title next to it carries the meaning.
 */
const props = defineProps<{ art?: ArtKey | null; imageId?: string | null; static?: boolean }>()
const live = useSky()
const sky = computed(() => (props.static ? null : live.value))
const pic = computed(() => picture(props.art))
const stars = computed(() => starfield(Math.max(pic.value.stars, sky.value ? 40 : 0), (props.art ?? 'road').length * 13))
const failed = ref(false)
watch(() => props.imageId, () => { failed.value = false })
const src = computed(() => (failed.value ? null : imageUrl(props.imageId)))
const uid = `sa${Math.random().toString(36).slice(2, 8)}`
const bodyR = computed(() => pic.value.moon?.r ?? 30)
/** Uploaded pictures get a time-of-day tint instead of a redrawn sky. */
const photoTint = computed(() => (sky.value ? { background: sky.value.top, opacity: String(Math.max(0, 0.42 - sky.value.daylight * 1.4)) } : null))
</script>

<template>
  <div class="scene-art" aria-hidden="true">
    <template v-if="src">
      <img :src="src" alt="" loading="lazy" decoding="async" @error="failed = true" />
      <div v-if="photoTint" class="photo-tint" :style="photoTint" />
    </template>
    <svg v-else viewBox="0 0 1200 400" preserveAspectRatio="xMidYMid slice" focusable="false">
      <defs>
        <linearGradient :id="`${uid}-sky`" x1="0" y1="0" x2="0" y2="1">
          <stop v-if="sky" offset="0" :stop-color="sky.top" />
          <stop v-if="sky" offset="1" :stop-color="sky.bottom" />
          <stop v-if="!sky" offset="0" style="stop-color: var(--art-sky-top)" />
          <stop v-if="!sky" offset="1" style="stop-color: var(--art-sky-bottom)" />
        </linearGradient>
        <radialGradient :id="`${uid}-moon`">
          <stop offset="0" style="stop-color: var(--art-light)" stop-opacity=".55" />
          <stop offset="1" style="stop-color: var(--art-light)" stop-opacity="0" />
        </radialGradient>
        <radialGradient :id="`${uid}-sun`">
          <stop offset="0" style="stop-color: var(--art-glow)" stop-opacity=".7" />
          <stop offset=".45" style="stop-color: var(--art-glow)" stop-opacity=".18" />
          <stop offset="1" style="stop-color: var(--art-glow)" stop-opacity="0" />
        </radialGradient>
      </defs>
      <rect width="1200" height="400" :fill="`url(#${uid}-sky)`" />
      <template v-if="sky">
        <g :opacity="sky.stars * .6">
          <circle v-for="(s, i) in stars" :key="i" :cx="s.x" :cy="s.y" :r="s.r" style="fill: var(--art-light)" />
        </g>
        <g v-if="sky.sun.opacity > .01" :opacity="sky.sun.opacity">
          <circle :cx="sky.sun.x" :cy="sky.sun.y" :r="bodyR * 4.5" :fill="`url(#${uid}-sun)`" />
          <circle :cx="sky.sun.x" :cy="sky.sun.y" :r="bodyR * .95" fill="#fff4d6" />
        </g>
        <g v-if="sky.moon.opacity > .01" :opacity="sky.moon.opacity">
          <circle :cx="sky.moon.x" :cy="sky.moon.y" :r="bodyR * 3.2" :fill="`url(#${uid}-moon)`" />
          <circle :cx="sky.moon.x" :cy="sky.moon.y" :r="bodyR" style="fill: var(--art-light)" opacity=".92" />
        </g>
      </template>
      <template v-else>
        <circle v-for="(s, i) in stars" :key="i" :cx="s.x" :cy="s.y" :r="s.r" style="fill: var(--art-light)" opacity=".55" />
        <template v-if="pic.moon">
          <circle :cx="pic.moon.x" :cy="pic.moon.y" :r="pic.moon.r * 3.2" :fill="`url(#${uid}-moon)`" />
          <circle :cx="pic.moon.x" :cy="pic.moon.y" :r="pic.moon.r" style="fill: var(--art-light)" opacity=".92" />
        </template>
      </template>
      <path v-for="(l, i) in pic.layers" :key="i" :d="l.d" fill-rule="evenodd" :class="`art-${l.fill}`" :opacity="l.opacity ?? 1" />
      <!-- Daylight washes the night-drawn landscape with the sky colour. -->
      <rect v-if="sky && sky.daylight > .01" width="1200" height="400" :fill="sky.bottom" :opacity="sky.daylight" style="mix-blend-mode: screen" />
    </svg>
    <WeatherCanvas v-if="sky && (sky.clouds > .02 || sky.rain > .02 || sky.snow > .02 || sky.storm > .02)" :sky="sky" />
  </div>
</template>
