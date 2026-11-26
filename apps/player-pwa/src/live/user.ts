import { ref } from 'vue'
import { ApiError } from '../api/client'
import { auth } from './api'
import type { Me } from './types'

/** The signed-in user for this tab (null = signed out). Never stored: the session cookie is the truth. */
export const me = ref<Me | null>(null)
export const meLoaded = ref(false)

export async function loadMe(): Promise<Me | null> {
  try {
    me.value = await auth.me()
  } catch (e) {
    if (!(e instanceof ApiError) || (e.status !== 401 && e.status !== 419)) throw e
    me.value = null
  } finally {
    meLoaded.value = true
  }
  return me.value
}

export function go(hash: string): void {
  if (location.hash !== hash) location.hash = hash
}
