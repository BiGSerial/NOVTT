<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useStorm } from '../../live/useAtmosphere'

/**
 * Lightning lights up the whole screen, not only the sky: a cool, low-opacity flash that follows
 * each strike's flicker pulses. Pulses are ≥ 350 ms apart (no more than three flashes per second,
 * WCAG 2.3.1) and peak well below full white; the provider schedules no strikes with reduced
 * motion. Never intercepts clicks.
 */
const storm = useStorm()
const veil = ref<HTMLDivElement | null>(null)
let unsubscribe: (() => void) | null = null

onMounted(() => {
  unsubscribe = storm?.subscribe(strike => {
    const el = veil.value
    if (!el || typeof el.animate !== 'function') return
    const total = (strike.pulses.at(-1)?.t ?? 0) + 400
    const peak = 0.16 * (1 - strike.distance * 0.6)
    const frames: Keyframe[] = [{ opacity: 0, offset: 0 }]
    for (const p of strike.pulses) {
      const at = p.t / total
      frames.push({ opacity: peak * p.power, offset: Math.min(0.99, at + 0.01) })
      frames.push({ opacity: 0, offset: Math.min(1, at + 220 / total) })
    }
    el.animate(frames.sort((a, b) => (a.offset as number) - (b.offset as number)), { duration: total, easing: 'linear' })
  }) ?? null
})
onBeforeUnmount(() => unsubscribe?.())
</script>

<template>
  <div ref="veil" class="storm-flash" aria-hidden="true" />
</template>
