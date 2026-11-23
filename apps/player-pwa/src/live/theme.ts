/**
 * Campaign themes as data (ADR 0011): only CSS tokens change, never layout or behaviour. The
 * choice is a per-browser preference for now (a campaign-level theme needs a contract change).
 */
export const THEMES = [
  { id: 'novtt', name: 'NOVTT' },
  { id: 'fantasia', name: 'Fantasia' },
  { id: 'horror', name: 'Horror' },
  { id: 'scifi', name: 'Sci-fi' },
] as const
export type ThemeId = (typeof THEMES)[number]['id']

export function loadTheme(campaignId: string): ThemeId {
  try {
    const v = localStorage.getItem(`novtt.theme.${campaignId}`)
    if (THEMES.some(t => t.id === v)) return v as ThemeId
  } catch { /* preference only */ }
  return 'fantasia'
}

export function saveTheme(campaignId: string, theme: ThemeId): void {
  try { localStorage.setItem(`novtt.theme.${campaignId}`, theme) } catch { /* preference only */ }
  applyTheme(theme)
}

export function applyTheme(theme: ThemeId): void {
  document.documentElement.dataset.campaignTheme = theme
}
