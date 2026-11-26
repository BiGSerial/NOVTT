<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted } from 'vue'
import type { useLiveTable } from '../../live/useLiveTable'
import type { Campaign } from '../../live/types'
import CastRail from './CastRail.vue'
import InitiativeStrip from './InitiativeStrip.vue'
import Inspector from './Inspector.vue'
import PartyBoard from './PartyBoard.vue'
import { createMasterContext, provideMaster } from './context'

/**
 * The paired tablet's binder (ADR 0011): the same cast and inspector as the console, big touch
 * targets. "Seguir a mesa" opens whatever the master selects on the main screen.
 */
const props = defineProps<{ table: ReturnType<typeof useLiveTable>; campaign: Campaign; follow: boolean; meId: string }>()
const m = createMasterContext(props.table, props.campaign)
provideMaster(m)
const many = computed(() => m.selection.value.length > 1)
let stop: (() => void) | null = null
onMounted(() => {
  const first = m.s.value.characters[0]
  if (first) m.select('character', first.id)
  stop = props.table.realtime()?.listenWhisper(`workspace.${props.meId}`, 'select', data => {
    if (props.follow && (data.kind === 'character' || data.kind === 'npc') && typeof data.id === 'string') {
      m.selection.value = [{ kind: data.kind, id: data.id }]
      const e = m.byId(data.id)
      if (e) void (e.kind === 'character' ? m.sheets.character(e.id, e.preset) : m.sheets.npc(e.id, e.preset))
    }
  }) ?? null
})
onBeforeUnmount(() => stop?.())
</script>

<template>
  <div class="tablet-binder">
    <InitiativeStrip />
    <div class="binder">
      <CastRail />
      <main class="workbench">
        <PartyBoard v-if="many" />
        <Inspector v-else-if="m.focus.value" :key="m.focus.value.id" :entry="m.focus.value" />
      </main>
    </div>
  </div>
</template>
