<script setup lang="ts">
import { ref } from 'vue'
import Portrait from '../live/Portrait.vue'
import { images } from '../../live/api'
import { ApiError } from '../../api/client'

/**
 * The character's picture, with an obvious way to add or change it. The server re-encodes the
 * upload (WebP, metadata stripped) and only lets the owner or the master set it.
 */
const props = defineProps<{ campaignId: string; characterId: string; name: string; imageId?: string | null; size?: number; editable?: boolean }>()
const emit = defineEmits<{ change: [imageId: string | null] }>()
const file = ref<HTMLInputElement | null>(null)
const busy = ref(false)
const error = ref('')

async function pick(ev: Event): Promise<void> {
  const f = (ev.target as HTMLInputElement).files?.[0]
  if (!f) return
  if (f.size > 8 * 1024 * 1024) { error.value = 'Imagem grande demais (máx. 8 MB).'; return }
  busy.value = true
  error.value = ''
  try {
    const img = await images.upload(props.campaignId, f, 'portrait')
    await images.setPortrait(props.campaignId, 'character', props.characterId, img.id)
    emit('change', img.id)
  } catch (e) {
    error.value = e instanceof ApiError && e.status === 413 ? 'Imagem grande demais.' : 'Não foi possível enviar a imagem.'
  } finally {
    busy.value = false
    if (file.value) file.value.value = ''
  }
}
async function clear(): Promise<void> {
  busy.value = true
  try { await images.setPortrait(props.campaignId, 'character', props.characterId, null); emit('change', null) } catch { error.value = 'Não foi possível remover.' } finally { busy.value = false }
}
</script>

<template>
  <div class="portrait-picker" :class="{ empty: !imageId }">
    <button v-if="editable" type="button" class="portrait-frame" :disabled="busy" :aria-label="imageId ? 'Trocar retrato' : 'Adicionar retrato'" @click="file?.click()">
      <Portrait :name="name || '?'" :image-id="imageId" :size="size ?? 96" :ring="false" />
      <span class="portrait-cta">{{ busy ? 'Enviando…' : imageId ? 'Trocar' : '+ Retrato' }}</span>
    </button>
    <span v-else class="portrait-frame"><Portrait :name="name || '?'" :image-id="imageId" :size="size ?? 96" :ring="false" /></span>
    <input v-if="editable" ref="file" type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" :aria-label="`Retrato de ${name}`" @change="pick" />
    <button v-if="editable && imageId" type="button" class="link small" :disabled="busy" @click="clear">remover</button>
    <p v-if="error" class="portrait-error" role="alert">{{ error }}</p>
  </div>
</template>
