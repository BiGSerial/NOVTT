<script setup lang="ts">
/**
 * The character as it is being built: name and choices as tags, numbers as tiles (with the
 * values they drive), computed values and the points still to distribute. Same component for the
 * side panel and, larger, for the review step.
 */
export interface SummaryGroup { id: string; label: string; tiles: { path: string; label: string; value: unknown; sub?: string | null }[] }
defineProps<{
  name: string
  tags: { label: string; value: string }[]
  groups: SummaryGroup[]
  derived: { label: string; value: unknown; explain?: string | null }[]
  points: { label: string; left: number }[]
  large?: boolean
}>()
const shown = (v: unknown) => (v === undefined || v === null || v === '' ? '—' : String(v))
</script>

<template>
  <div class="sheet-summary" :class="{ large }">
    <header>
      <p class="summary-name">{{ name || 'Sem nome' }}</p>
      <ul v-if="tags.length" class="summary-tags">
        <li v-for="t in tags" :key="t.label" :title="t.label"><span class="sr-only">{{ t.label }}: </span>{{ t.value }}</li>
      </ul>
    </header>
    <section v-for="g in groups" :key="g.id" class="summary-group">
      <h4>{{ g.label }}</h4>
      <ul class="summary-tiles">
        <li v-for="t in g.tiles" :key="t.path">
          <span class="tile-value">{{ shown(t.value) }}</span>
          <span class="tile-label">{{ t.label }}</span>
          <span v-if="t.sub" class="tile-sub">{{ t.sub }}</span>
        </li>
      </ul>
    </section>
    <section v-if="derived.length" class="summary-group">
      <h4>Calculados</h4>
      <ul class="summary-tiles derived">
        <li v-for="d in derived" :key="d.label" :title="d.explain ?? undefined">
          <span class="tile-value">{{ shown(d.value) }}</span>
          <span class="tile-label">{{ d.label }}</span>
        </li>
      </ul>
    </section>
    <section v-if="points.length" class="summary-group">
      <h4>Pontos para distribuir</h4>
      <ul class="summary-points">
        <li v-for="p in points" :key="p.label" :class="{ done: p.left === 0, over: p.left < 0 }"><span>{{ p.label }}</span><b>{{ p.left }}</b></li>
      </ul>
    </section>
  </div>
</template>
