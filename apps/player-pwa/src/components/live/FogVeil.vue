<script setup lang="ts">
import { computed } from 'vue'
import { useSky } from '../../live/useAtmosphere'

/**
 * Fog crossing the whole screen (owner request: "a névoa fica passando por toda a tela").
 * Translucent bands in the theme's fog colour drift across the viewport above the content,
 * never intercepting clicks, with opacity capped so text stays readable. Reduced motion keeps
 * a still, fainter veil.
 */
const sky = useSky()
const style = computed(() => {
  const s = sky.value
  if (!s || s.fog < 0.02) return null
  return { '--fog': s.fogColor, '--fog-amount': String(Math.min(0.62, 0.25 + s.fog * 0.4)) }
})
</script>

<template>
  <div v-if="style" class="fog-veil" :style="style" aria-hidden="true">
    <span class="fog-band b1" />
    <span class="fog-band b2" />
    <span class="fog-band b3" />
  </div>
</template>
