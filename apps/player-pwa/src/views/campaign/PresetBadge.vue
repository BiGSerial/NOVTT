<script setup lang="ts">
import { computed } from 'vue'
import { COVERAGE_LABEL, taxonomyLabel, type PresetSummary } from '../../live/campaign'

/** What a preset is, where it comes from and what it does not cover (ADR-002 criterion 5). */
const props = defineProps<{ preset: PresetSummary; compact?: boolean }>()
const taxonomy = computed(() => taxonomyLabel(props.preset))
const origin = computed(() => ({ original: 'Conteúdo original', official: 'Fonte oficial', licensed: 'Conteúdo licenciado', community: 'Comunidade' })[props.preset.source_authenticity ?? ''] ?? null)
</script>

<template>
  <div class="preset-badge" :class="{ compact }">
    <p class="preset-name">
      <span class="preset-title">{{ preset.display_name }}</span>
      <span class="chip">v{{ preset.version }}</span>
      <span v-if="!preset.canonical" class="chip muted-chip">Biblioteca pessoal</span>
    </p>
    <p class="preset-meta small">
      <span v-if="preset.rules_revision">Revisão {{ preset.rules_revision }}</span>
      <span v-if="preset.coverage">{{ COVERAGE_LABEL[preset.coverage] ?? preset.coverage }}</span>
      <span v-if="taxonomy">{{ taxonomy }}</span>
    </p>
    <template v-if="!compact">
      <p class="preset-meta small">
        <span v-if="preset.license.identifier">Licença {{ preset.license.identifier }}</span>
        <span v-if="origin">{{ origin }}</span>
      </p>
      <p v-if="preset.license.attribution" class="attribution small">{{ preset.license.attribution }}</p>
      <ul v-if="preset.limitations.length" class="limitations small" aria-label="Limitações do preset">
        <li v-for="l in preset.limitations" :key="l">{{ l }}</li>
      </ul>
    </template>
  </div>
</template>
