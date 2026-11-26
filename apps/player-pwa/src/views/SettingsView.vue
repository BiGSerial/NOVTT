<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import DiceSwatch from '../components/DiceSwatch.vue'
import AppShell from '../components/shell/AppShell.vue'
import ActionDialog from '../components/ui/ActionDialog.vue'
import { contrast, DEFAULT_SKIN, FINISH_LABELS, FINISHES, MIN_CONTRAST, parseSkin, PRESET_SKINS, type DiceSkin, type Finish } from '../dice/skins'
import { TEXTURE_LABELS, TEXTURE_PATTERNS, type TexturePattern } from '../dice/textures'
import { loadPreferences, prefs, savePreferences } from '../live/me'
import { usePlatform } from '../live/platform'
import { go, me } from '../live/user'

/**
 * Personal settings. Dice sets ("modelos de dados"): colours, finish and a procedural texture,
 * saved to the account (every device) — the active one rolls everywhere. Textures are drawn by
 * each client from parameters, so the master sees the same dice as the player.
 */
const { platform, form } = usePlatform()
const status = ref('')
const error = ref('')
onMounted(() => { if (!me.value) return go('#/'); if (!prefs.loaded) void loadPreferences() })

const sets = computed(() => prefs.dice_sets)
const activeId = computed(() => prefs.active_dice_set)
async function persist(message: string): Promise<void> {
  error.value = ''
  try { await savePreferences(); status.value = message } catch { error.value = 'Não foi possível salvar. Tente de novo.' }
}
async function activate(id: string | null): Promise<void> { prefs.active_dice_set = id; await persist(id ? 'Agora você rola com esse modelo.' : 'Voltou aos dados padrão.') }
async function remove(id: string): Promise<void> {
  prefs.dice_sets = prefs.dice_sets.filter(s => s.id !== id)
  if (prefs.active_dice_set === id) prefs.active_dice_set = null
  await persist('Modelo removido.')
}
async function toggleSound(): Promise<void> { prefs.dice_sound = !prefs.dice_sound; await persist(prefs.dice_sound ? 'Som dos dados ligado.' : 'Som dos dados desligado.') }

// ---- Editor ----
const editing = ref<boolean>(false)
const draft = reactive({ id: '', name: '', body: '#7a1f12', ink: '#fde68a', finish: 'gloss' as Finish, pattern: 'marble' as TexturePattern, accent: '#f59e0b', scale: 1 })
const editingExisting = ref(false)
function open(skin?: DiceSkin): void {
  const s = skin ?? { ...PRESET_SKINS[1]!, id: '', name: '' }
  Object.assign(draft, { id: s.id, name: s.name, body: s.body, ink: s.ink, finish: s.finish, pattern: s.texture?.pattern ?? 'none', accent: s.texture?.accent ?? '#f59e0b', scale: s.texture?.scale ?? 1 })
  editingExisting.value = !!skin
  editing.value = true
}
const slug = (name: string) => name.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 24) || 'dados'
const candidate = computed<DiceSkin | null>(() => parseSkin({
  id: draft.id || `${slug(draft.name)}-${Math.random().toString(36).slice(2, 6)}`, name: draft.name.trim() || 'Meu modelo', body: draft.body, ink: draft.ink, finish: draft.finish,
  ...(draft.pattern !== 'none' ? { texture: { pattern: draft.pattern, accent: draft.accent, scale: Number(draft.scale) } } : {}),
}))
const ratio = computed(() => contrast(draft.body, draft.ink))
async function saveSet(): Promise<void> {
  const skin = candidate.value
  if (!skin) return
  draft.id = skin.id
  const exists = prefs.dice_sets.some(s => s.id === skin.id)
  if (!exists && prefs.dice_sets.length >= 12) { error.value = 'Você já tem 12 modelos. Remova um antes.'; return }
  prefs.dice_sets = exists ? prefs.dice_sets.map(s => (s.id === skin.id ? skin : s)) : [...prefs.dice_sets, skin]
  if (!exists) prefs.active_dice_set = skin.id
  editing.value = false
  await persist(exists ? 'Modelo atualizado.' : 'Modelo criado e escolhido para as suas rolagens.')
}
const previewPattern = (p: TexturePattern): DiceSkin => ({ ...DEFAULT_SKIN, id: 'p', name: TEXTURE_LABELS[p], body: draft.body, ink: draft.ink, ...(p !== 'none' ? { texture: { pattern: p, accent: draft.accent, scale: Number(draft.scale) } } : {}) })
</script>

<template>
  <AppShell title="Ajustes">
    <section class="settings">
      <header class="section-head"><h1>Ajustes</h1></header>
      <p v-if="status" class="status-banner" role="status">{{ status }}</p>
      <p v-if="error" class="error" role="alert">{{ error }}</p>

      <section class="settings-card" aria-labelledby="dice-title">
        <div class="settings-card-head">
          <div>
            <h2 id="dice-title">Seus dados</h2>
            <p class="muted small">Modelos salvos na sua conta, em todos os aparelhos. O escolhido vale nas suas rolagens, e o mestre vê os mesmos dados.</p>
          </div>
          <button type="button" class="primary" @click="open()">Novo modelo</button>
        </div>
        <ul class="dice-sets" role="radiogroup" aria-label="Modelo de dados ativo">
          <li class="dice-set" :class="{ on: !activeId }">
            <button type="button" role="radio" :aria-checked="!activeId" class="dice-set-pick" @click="activate(null)">
              <DiceSwatch :skin="DEFAULT_SKIN" :size="64" /><span><b>Padrão</b><small>Marfim</small></span>
            </button>
          </li>
          <li v-for="s in sets" :key="s.id" class="dice-set" :class="{ on: activeId === s.id }">
            <button type="button" role="radio" :aria-checked="activeId === s.id" class="dice-set-pick" @click="activate(s.id)">
              <DiceSwatch :skin="s" :size="64" /><span><b>{{ s.name }}</b><small>{{ FINISH_LABELS[s.finish] }}<template v-if="s.texture"> · {{ TEXTURE_LABELS[s.texture.pattern] }}</template></small></span>
            </button>
            <span class="dice-set-actions">
              <button type="button" class="link" @click="open(s)">Editar</button>
              <button type="button" class="link danger" @click="remove(s.id)">Remover</button>
            </span>
          </li>
        </ul>
        <label class="check"><input type="checkbox" :checked="prefs.dice_sound" @change="toggleSound" /> Som dos dados</label>
      </section>

      <section class="settings-card" aria-labelledby="device-title">
        <h2 id="device-title">Este aparelho</h2>
        <p class="muted small">Você está usando o NOVTT {{ platform === 'web' ? 'no navegador' : platform === 'android' ? 'instalado no Android' : 'instalado como app' }} ({{ form === 'phone' ? 'celular' : form === 'tablet' ? 'tablet' : 'computador' }}). A navegação e o tamanho das telas se ajustam a isso.</p>
      </section>
    </section>

    <ActionDialog v-if="editing" :title="editingExisting ? 'Editar modelo de dados' : 'Novo modelo de dados'" wide @close="editing = false" @submit="saveSet">
      <div class="dice-editor">
        <div class="dice-editor-preview">
          <DiceSwatch v-if="candidate" :skin="candidate" :size="140" />
          <p v-else class="error small">As cores ficaram parecidas demais: o número não seria lido.</p>
          <p class="muted small">Contraste {{ ratio.toFixed(1) }} : 1 (mínimo {{ MIN_CONTRAST }})</p>
        </div>
        <div class="dice-editor-fields">
          <label>Nome <input v-model="draft.name" maxlength="40" placeholder="Ex.: Brasa do dragão" autofocus /></label>
          <div class="color-row">
            <label>Corpo <input v-model="draft.body" type="color" /></label>
            <label>Números <input v-model="draft.ink" type="color" /></label>
            <label v-if="draft.pattern !== 'none'">Destaque <input v-model="draft.accent" type="color" /></label>
          </div>
          <fieldset class="choice-set">
            <legend>Acabamento</legend>
            <div class="seg-tabs small" role="radiogroup" aria-label="Acabamento">
              <button v-for="f in FINISHES" :key="f" type="button" role="radio" :aria-checked="draft.finish === f" :class="{ on: draft.finish === f }" @click="draft.finish = f">{{ FINISH_LABELS[f] }}</button>
            </div>
          </fieldset>
        </div>
      </div>
      <fieldset class="choice-set">
        <legend>Textura</legend>
        <div class="texture-tiles" role="radiogroup" aria-label="Textura">
          <button v-for="p in TEXTURE_PATTERNS" :key="p" type="button" role="radio" class="texture-tile" :class="{ on: draft.pattern === p }" :aria-checked="draft.pattern === p" @click="draft.pattern = p">
            <DiceSwatch :skin="previewPattern(p)" :size="52" label="" /><span>{{ TEXTURE_LABELS[p] }}</span>
          </button>
        </div>
        <label v-if="draft.pattern !== 'none'" class="scale-row">Escala do desenho <input v-model.number="draft.scale" type="range" min="0.5" max="3" step="0.25" /> <output>{{ Number(draft.scale).toFixed(2) }}×</output></label>
      </fieldset>
      <template #actions>
        <span class="grow" />
        <button type="button" class="ghost" @click="editing = false">Cancelar</button>
        <button type="submit" class="primary" :disabled="!candidate">Salvar modelo</button>
      </template>
    </ActionDialog>
  </AppShell>
</template>
