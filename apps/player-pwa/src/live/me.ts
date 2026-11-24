// The signed-in person's own space (GET/PUT /me/*): dice sets synced across devices, characters in
// every campaign, and companions with coarse presence.
import { reactive } from 'vue'
import { api, ensureCsrfCookie } from '../api/client'
import { DEFAULT_SKIN, parseSkin, saveSkin, saveSound, type DiceSkin } from '../dice/skins'

export interface Preferences { dice_sets: DiceSkin[]; active_dice_set: string | null; dice_sound: boolean }
export interface SheetVersionLabel { id: string; version: string; name: string; definition_id: string }
export interface MyCharacter {
  id: string; name: string; status: 'draft' | 'pending_approval' | 'approved'; portrait_image_id: string | null
  campaign: { id: string; name: string }; sheet: SheetVersionLabel | null; campaign_sheet: SheetVersionLabel | null; outdated: boolean
}
export interface Companion { user_id: string; name: string; online: boolean; last_seen_on: string | null; campaigns: { id: string; name: string; role: 'master' | 'player' }[] }

async function put<T>(path: string, body: unknown): Promise<T> { await ensureCsrfCookie(); return api<T>('PUT', path, body) }

export const meApi = {
  preferences: () => api<Preferences>('GET', '/me/preferences'),
  savePreferences: (p: Preferences) => put<Preferences>('/me/preferences', p),
  characters: () => api<{ data: MyCharacter[] }>('GET', '/me/characters'),
  companions: () => api<{ data: Companion[] }>('GET', '/me/companions'),
}

export const prefs = reactive<Preferences & { loaded: boolean }>({ dice_sets: [], active_dice_set: null, dice_sound: true, loaded: false })

/** The active set (or the device's / default skin) — the dice tray reads it through loadSkin(). */
export function activeSkin(): DiceSkin {
  return prefs.dice_sets.find(s => s.id === prefs.active_dice_set) ?? DEFAULT_SKIN
}

export async function loadPreferences(): Promise<void> {
  try {
    const p = await meApi.preferences()
    prefs.dice_sets = p.dice_sets.map(s => parseSkin(s)).filter((s): s is DiceSkin => s !== null)
    prefs.active_dice_set = p.active_dice_set
    prefs.dice_sound = p.dice_sound
    prefs.loaded = true
    if (prefs.active_dice_set) saveSkin(activeSkin()) // this device rolls with the synced set
    saveSound(prefs.dice_sound)
  } catch { /* offline or signed out: the device's own skin stays */ }
}

export async function savePreferences(): Promise<void> {
  const saved = await meApi.savePreferences({ dice_sets: prefs.dice_sets, active_dice_set: prefs.active_dice_set, dice_sound: prefs.dice_sound })
  prefs.active_dice_set = saved.active_dice_set
  saveSkin(activeSkin())
  saveSound(prefs.dice_sound)
}
