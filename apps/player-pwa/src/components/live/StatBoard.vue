<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import type { SheetSection } from '../../live/sheet'

/**
 * A sheet's numbers as a character sheet, not a form: the first numeric section (attributes)
 * as medallions, the others (skills) as rated rows. Selectable: tapping builds the combination
 * for a roll (attribute + skill). Prose sections (identity, gear, secrets) read as text.
 */
const props = withDefaults(defineProps<{ sections: SheetSection[]; selected?: string[]; selectable?: boolean; compact?: boolean; hide?: string[]; asked?: string[]
  /** First numeric section as medallions (attributes). Off for computed values: all as rows. */
  lead?: boolean }>(), { lead: true, selected: undefined, hide: undefined, asked: undefined })
const emit = defineEmits<{ toggle: [path: string] }>()
const numeric = computed(() => props.sections.filter(s => s.numeric))
const leadSection = computed(() => (props.lead ? numeric.value[0] ?? null : null))
const rest = computed(() => (props.lead ? numeric.value.slice(1) : numeric.value))
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
    <div v-if="leadSection" class="medallions" role="group" :aria-label="leadSection.label">
      <button
        v-for="f in leadSection.fields" :key="f.path" type="button" class="medallion" :class="{ on: on(f.path), asked: isAsked(f.path) }"
        :aria-pressed="selectable ? on(f.path) : undefined" :disabled="!selectable" @click="emit('toggle', f.path)"
      >
        <span class="medallion-value">{{ f.value }}</span>
        <span class="medallion-label">{{ f.label }}</span>
        <span v-if="f.widget === 'dot_rating'" class="pips" aria-hidden="true"><i v-for="n in Math.max(5, dots(f.value))" :key="n" :class="{ full: n <= dots(f.value) }" /></span>
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
          <span v-if="f.widget === 'dot_rating'" class="pips" aria-hidden="true"><i v-for="n in Math.max(3, dots(f.value))" :key="n" :class="{ full: n <= dots(f.value) }" /></span>
          <span class="skill-value">{{ f.value }}</span>
        </button>
      </div>
    </section>
    <section v-for="s in prose" :key="s.id" class="prose-block" :class="s.id">
      <h3 class="sheet-heading">{{ s.label }}</h3>
      <dl v-if="s.fields.length">
        <template v-for="f in s.fields" :key="f.path">
          <dt>{{ f.label }}</dt>
          <dd>{{ f.value === true ? 'Sim' : f.value === false ? 'Não' : f.value }}</dd>
        </template>
      </dl>
      <div v-for="c in s.collections ?? []" :key="c.path" class="sheet-list">
        <p class="sheet-list-title">{{ c.label }}</p>
        <div class="sheet-table" role="table" :aria-label="c.label">
          <div class="sheet-row head" role="row"><span v-for="col in c.columns" :key="col.field" role="columnheader">{{ col.label }}</span></div>
          <div v-for="(r, i) in c.rows" :key="i" class="sheet-row" role="row"><span v-for="col in c.columns" :key="col.field" role="cell">{{ r[col.field] ?? '—' }}</span></div>
        </div>
      </div>
    </section>
  </div>
</template>
