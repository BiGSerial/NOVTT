<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { loadDiceStage } from '../dice/loader'
import type { RollAnimationRecord } from '../dice/record'
import type { AnimationMode, DiceAnimator } from '../dice/stage'

/**
 * 3D dice tray (fixed top-down view). The text result is always present and is the source of
 * truth for assistive technology; the canvas is decorative (aria-hidden). Falls back to text
 * when WebGL or the 3D bundle is unavailable (e.g. offline before the first 3D use).
 */
const props = defineProps<{
  record: RollAnimationRecord | null
  label?: string
  loader?: typeof loadDiceStage
  instant?: boolean
}>()
const emit = defineEmits<{ settled: [record: RollAnimationRecord] }>()

const host = ref<HTMLElement | null>(null)
const canvas = ref<HTMLCanvasElement | null>(null)
const phase = ref<'idle' | 'loading' | 'rolling' | 'done'>('idle')
const unavailable = ref(false)
const order = ref<number[]>([])
const announced = ref('')
const stats = ref('')
let animator: DiceAnimator | null = null
let observer: ResizeObserver | null = null
let token = 0

const reducedMotion = (): boolean => typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches

const ordered = computed(() => {
  const r = props.record
  if (!r) return []
  const idx = order.value.length === r.dice.length ? order.value : r.dice.map((_, i) => i)
  return idx.map(i => r.dice[i])
})
const total = computed(() => ordered.value.reduce((s, d) => s + d.result, 0))

function summary(r: RollAnimationRecord): string {
  const values = ordered.value.map(d => d.result).join(', ')
  return `${r.dice.length}${r.dice[0].type}: ${values}${r.dice.length > 1 ? ` (soma ${total.value})` : ''}`
}

async function ensureAnimator(): Promise<DiceAnimator | null> {
  if (animator || unavailable.value) return animator
  if (!canvas.value || !host.value) return null
  try {
    const stage = await (props.loader ?? loadDiceStage)()
    animator = stage.create(canvas.value)
    const resize = () => animator?.resize(host.value!.clientWidth, host.value!.clientHeight)
    resize()
    if (typeof ResizeObserver !== 'undefined') { observer = new ResizeObserver(resize); observer.observe(host.value) }
  } catch {
    unavailable.value = true
    animator = null
  }
  return animator
}

async function show(record: RollAnimationRecord | null): Promise<void> {
  const mine = ++token
  order.value = []
  announced.value = ''
  if (!record) { phase.value = 'idle'; return }
  phase.value = animator ? 'rolling' : 'loading'
  // Bring the tray into view on small screens (the roll button sits above it).
  host.value?.scrollIntoView?.({ block: 'nearest', behavior: reducedMotion() ? 'auto' : 'smooth' })
  const a = await ensureAnimator()
  if (mine !== token) return
  if (a) {
    phase.value = 'rolling'
    const mode: AnimationMode = props.instant ? 'instant' : reducedMotion() ? 'reduced' : 'animated'
    try {
      const s = await a.play(record, mode)
      if (mine !== token) return
      order.value = s.order
      stats.value = `cálculo ${s.simulationMs.toFixed(0)} ms · animação ${s.animationS.toFixed(1)} s`
    } catch {
      unavailable.value = true
    }
  }
  if (mine !== token) return
  phase.value = 'done'
  announced.value = `Resultado: ${summary(record)}`
  emit('settled', record)
}

watch(() => props.record, r => { void show(r) })
onMounted(() => { if (props.record) void show(props.record) })
onBeforeUnmount(() => { token++; observer?.disconnect(); animator?.dispose(); animator = null })
</script>

<template>
  <section class="dice-tray" :aria-label="label ?? 'Mesa de dados'" :aria-busy="phase === 'loading' || phase === 'rolling'">
    <div v-show="!unavailable" ref="host" class="dice-stage">
      <canvas ref="canvas" aria-hidden="true"></canvas>
      <button v-if="phase === 'rolling'" type="button" class="skip" @click="animator?.skip()">Pular animação</button>
      <p v-if="phase === 'loading'" class="stage-note">Preparando a mesa…</p>
    </div>
    <p v-if="unavailable" class="help">Dados 3D indisponíveis neste navegador; o resultado é exibido em texto.</p>
    <ol v-if="record && phase === 'done'" class="dice-results" aria-label="Valores">
      <li v-for="(d, i) in ordered" :key="i">{{ d.result }}<span class="sr-only"> em {{ d.type }}</span></li>
    </ol>
    <p v-if="record && phase === 'done' && record.dice.length > 1" class="dice-total">Soma: <strong>{{ total }}</strong></p>
    <p class="sr-only" role="status" aria-live="polite">{{ announced }}</p>
    <p v-if="stats && phase === 'done'" class="help stats">{{ stats }}</p>
  </section>
</template>
