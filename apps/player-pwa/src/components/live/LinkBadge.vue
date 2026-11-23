<script setup lang="ts">
import type { LinkState } from '../../live/realtime'

/** Live / reconnecting / offline indicator (SPEC-002 MW-09). */
defineProps<{ state: LinkState }>()
const text: Record<LinkState, string> = { connecting: 'Conectando…', live: 'Ao vivo', reconnecting: 'Reconectando…', offline: 'Sem tempo real' }
</script>

<template>
  <span class="link-badge" :class="state" role="status"><span class="dot-live" aria-hidden="true" />{{ text[state] }}</span>
</template>
