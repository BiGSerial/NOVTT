<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import type { DiceSkin } from '../dice/skins'
import { textureCanvas } from '../dice/textures'

/** A flat preview of a dice skin: its texture (if any), body colour and a number in its ink. */
const props = defineProps<{ skin: DiceSkin; size?: number; label?: string }>()
const canvas = ref<HTMLCanvasElement | null>(null)
function draw(): void {
  const c = canvas.value
  if (!c) return
  const size = props.size ?? 72
  c.width = c.height = size * 2
  const g = c.getContext('2d')
  if (!g) return
  g.save()
  g.beginPath()
  const r = size * 2
  // A d20-ish hexagon.
  for (let i = 0; i < 6; i++) { const a = Math.PI / 6 + (i * Math.PI) / 3; g.lineTo(r / 2 + Math.cos(a) * r * 0.48, r / 2 + Math.sin(a) * r * 0.48) }
  g.closePath()
  g.clip()
  if (props.skin.texture) g.drawImage(textureCanvas(props.skin.body, props.skin.texture, 128), 0, 0, r, r)
  else { g.fillStyle = props.skin.body; g.fillRect(0, 0, r, r) }
  const shade = g.createLinearGradient(0, 0, r, r)
  shade.addColorStop(0, 'rgba(255,255,255,.18)'); shade.addColorStop(1, 'rgba(0,0,0,.28)')
  g.fillStyle = shade; g.fillRect(0, 0, r, r)
  g.restore()
  g.fillStyle = props.skin.ink
  g.font = `700 ${r * 0.34}px system-ui, sans-serif`
  g.textAlign = 'center'; g.textBaseline = 'middle'
  g.fillText(props.label ?? '20', r / 2, r / 2 + r * 0.02)
}
onMounted(draw)
watch(() => JSON.stringify(props.skin), draw)
</script>

<template>
  <canvas ref="canvas" class="dice-swatch" :style="{ width: `${size ?? 72}px`, height: `${size ?? 72}px` }" role="img" :aria-label="`Dado ${skin.name}`" />
</template>
