<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import type { SheetSection } from '../../live/sheet'

/**
 * A sheet's numbers as a character sheet, not a form: the first numeric section (attributes)
 * as medallions, the others (skills) as rated rows. Selectable: tapping builds the combination
 * for a roll (attribute + skill). Prose sections (identity, gear, secrets) read as text.
 */
const props = defineProps<{ sections: SheetSection[]; selected?: string[]; selectable?: boolean; compact?: boolean; hide?: string[]; asked?: string[] }>()
const emit = defineEmits<{ toggle: [path: string] }>()
const numeric = computed(() => props.sections.filter(s => s.numeric))
const lead = computed(() => numeric.value[0] ?? null)
const rest = computed(() => numeric.value.slice(1))
const prose = computed(() => props.sections.filter(s => !s.numeric && !(props.hide ?? []).includes(s.id)))
const dots = (n: unknown) => (typeof n === 'number' ? Math.max(0, Math.min(10, n)) : 0)
const on = (p: string) => props.selected?.includes(p) ?? false
// Fields a player asked to roll: lit, and the first one scrolled into view when a request arrives.
const isAsked = (p: string) => props.asked?.includes(p) ?? false
const root = ref<HTMLElement | null>(null)
watch(() => `${(props.asked ?? []).join()}|${props.sections.length}`, async () => {
  if (!props.asked?.length) return
  await nextTick()
  const still = typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches
  root.value?.querySelector('.asked')?.scrollIntoView({ block: 'nearest', behavior: still ? 'auto' : 'smooth' })
}, { immediate: true })
</script>

<template>
  <div ref="root" class="stat-board" :class="{ compact, selectable }">
    <div v-if="lead" class="medallions" role="group" :aria-label="lead.label">
      <button
        v-for="f in lead.fields" :key="f.path" type="button" class="medallion" :class="{ on: on(f.path), asked: isAsked(f.path) }"
        :aria-pressed="selectable ? on(f.path) : undefined" :disabled="!selectable" @click="emit('toggle', f.path)"
      >
        <span class="medallion-value">{{ f.value }}</span>
        <span class="medallion-label">{{ f.label }}</span>
        <span class="pips" aria-hidden="true"><i v-for="n in Math.max(5, dots(f.value))" :key="n" :class="{ full: n <= dots(f.value) }" /></span>
      </button>
    </div>
    <section v-for="s in rest" :key="s.id" class="skill-block">
      <h3 class="sheet-heading">{{ s.label }}</h3>
      <div class="skills" role="group" :aria-label="s.label">
        <button
          v-for="f in s.fields" :key="f.path" type="button" class="skill" :class="{ on: on(f.path), zero: dots(f.value) === 0, asked: isAsked(f.path) }"
          :aria-pressed="selectable ? on(f.path) : undefined" :disabled="!selectable" @click="emit('toggle', f.path)"
        >
          <span class="skill-label">{{ f.label }}</span>
          <span class="pips" aria-hidden="true"><i v-for="n in Math.max(3, dots(f.value))" :key="n" :class="{ full: n <= dots(f.value) }" /></span>
          <span class="skill-value">{{ f.value }}</span>
        </button>
      </div>
    </section>
    <section v-for="s in prose" :key="s.id" class="prose-block" :class="s.id">
      <h3 class="sheet-heading">{{ s.label }}</h3>
      <dl>
        <template v-for="f in s.fields" :key="f.path">
          <dt>{{ f.label }}</dt>
          <dd>{{ f.value }}</dd>
        </template>
      </dl>
    </section>
  </div>
</template>
