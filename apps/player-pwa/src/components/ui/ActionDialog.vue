<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { pushBack } from '../../live/platform'

/**
 * A modal for master/player actions that need more than one control. Focus goes to the first
 * field and returns to whatever opened it; Escape or the veil closes; Tab stays inside.
 */
const props = defineProps<{ title: string; wide?: boolean }>()
const emit = defineEmits<{ close: []; submit: [] }>()
const box = ref<HTMLFormElement | null>(null)
const titleId = `dlg-${Math.random().toString(36).slice(2, 8)}`
let opener: HTMLElement | null = null

const focusables = () => [...(box.value?.querySelectorAll<HTMLElement>('input, select, textarea, button, [tabindex]:not([tabindex="-1"])') ?? [])].filter(e => !e.hasAttribute('disabled'))
function trap(ev: KeyboardEvent): void {
  if (ev.key === 'Escape') { ev.preventDefault(); emit('close'); return }
  if (ev.key !== 'Tab') return
  const list = focusables()
  if (!list.length) return
  const first = list[0]!
  const last = list[list.length - 1]!
  if (ev.shiftKey && document.activeElement === first) { ev.preventDefault(); last.focus() }
  else if (!ev.shiftKey && document.activeElement === last) { ev.preventDefault(); first.focus() }
}
// The system/browser Back closes the dialog (Android convention) instead of leaving the screen.
let releaseBack: () => void = () => undefined
onMounted(async () => {
  releaseBack = pushBack(() => emit('close'))
  opener = document.activeElement as HTMLElement | null
  await nextTick()
  const first = box.value?.querySelector<HTMLElement>('[autofocus], input, select, textarea') ?? focusables()[0]
  first?.focus()
})
onBeforeUnmount(() => { releaseBack(); opener?.focus?.() })
void props
</script>

<template>
  <Teleport to="body">
    <div class="drawer-veil action-veil" @click.self="emit('close')">
      <form
        ref="box" class="dialog action-dialog" :class="{ wide }" role="dialog" aria-modal="true" :aria-labelledby="titleId"
        @submit.prevent="emit('submit')" @keydown="trap"
      >
        <header class="action-head">
          <h2 :id="titleId">{{ title }}</h2>
          <button type="button" class="action-x" aria-label="Fechar" @click="emit('close')">×</button>
        </header>
        <div class="action-body"><slot /></div>
        <footer class="action-foot"><slot name="actions" /></footer>
      </form>
    </div>
  </Teleport>
</template>
