<script setup lang="ts">
defineProps<{ items: { id: string; text: string; kind: string }[] }>()
</script>

<template>
  <div class="toasts" role="status" aria-live="polite">
    <p v-for="t in items" :key="t.id" class="toast" :class="t.kind">{{ t.text }}</p>
  </div>
</template>
