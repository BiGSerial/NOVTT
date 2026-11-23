<script setup lang="ts">
import { computed } from 'vue'
import type { SheetSection } from '../../live/sheet'

/**
 * A character or NPC sheet as cards. Numeric fields (attributes, skills) are toggle buttons when
 * `selectable`: tapping them builds the combination for a roll (attribute + skill, never just one
 * value by design). Text fields read as prose.
 */
const props = defineProps<{ sections: SheetSection[]; selected?: string[]; selectable?: boolean; compact?: boolean }>()
const emit = defineEmits<{ toggle: [path: string] }>()
const numeric = computed(() => props.sections.filter(s => s.numeric))
const prose = computed(() => props.sections.filter(s => !s.numeric))
const dots = (n: unknown) => (typeof n === 'number' ? Math.max(0, Math.min(10, n)) : 0)
</script>

<template>
  <div class="sheet-view" :class="{ compact }">
    <section v-for="s in numeric" :key="s.id" class="sheet-block">
      <h3>{{ s.label }}</h3>
      <div class="stat-grid">
        <button
          v-for="f in s.fields" :key="f.path" type="button" class="stat"
          :class="{ on: selected?.includes(f.path) }" :aria-pressed="selectable ? selected?.includes(f.path) ?? false : undefined"
          :disabled="!selectable" @click="emit('toggle', f.path)"
        >
          <span class="stat-label">{{ f.label }}</span>
          <span class="stat-dots" aria-hidden="true"><i v-for="n in Math.max(5, dots(f.value))" :key="n" :class="{ full: n <= dots(f.value) }" /></span>
          <span class="stat-value">{{ f.value }}</span>
        </button>
      </div>
    </section>
    <section v-for="s in prose" :key="s.id" class="sheet-block prose" :class="s.id">
      <h3>{{ s.label }}</h3>
      <dl>
        <template v-for="f in s.fields" :key="f.path">
          <dt>{{ f.label }}</dt>
          <dd>{{ f.value }}</dd>
        </template>
      </dl>
    </section>
  </div>
</template>
