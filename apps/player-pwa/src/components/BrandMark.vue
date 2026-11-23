<script setup lang="ts">
/**
 * NOVTT lockup: hexagon symbol + wordmark (+ optional tagline). The symbol image is decorative;
 * the accessible name comes from the visible wordmark. `tone` picks the symbol drawn for a dark
 * or a light background (docs/brand/README.md).
 */
withDefaults(defineProps<{ tagline?: boolean; tone?: 'dark' | 'light' }>(), { tagline: false, tone: 'dark' })
</script>

<template>
  <span class="brand">
    <img :src="tone === 'dark' ? '/brand/novtt-symbol-on-dark.svg' : '/brand/novtt-symbol-on-light.svg'" alt="" width="40" height="40" />
    <span>
      <span class="brand-word">NOVTT</span>
      <span v-if="tagline" class="brand-tagline">NOT OTHER VIRTUAL TABLE TOP</span>
    </span>
  </span>
</template>
