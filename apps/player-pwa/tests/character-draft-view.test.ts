// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest'
import { createApp, h, type App } from 'vue'
import CharacterDraftView from '../src/views/CharacterDraftView.vue'
import { fixture } from './support'

const ID = '01a203a3-0b85-77ca-b6eb-4d3db2082f5b'
let app: App | null = null
afterEach(() => { app?.unmount(); app = null; document.body.replaceChildren() })
const wait = (ms: number) => new Promise(r => setTimeout(r, ms))

function server() {
  const puts: unknown[] = []
  const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
  const fetcher = (async (url: string, init?: RequestInit) => {
    if (url.endsWith(`/campaign-characters/${ID}`)) return json({ id: ID, name: 'Aria', status: 'creation', sheet_revision: 0, preset_version_id: 'pv', sheet: { attributes: { might: 1 } } })
    if (url.endsWith('/preset-versions/pv')) return json({ sheet_ui: fixture('sheet.ui.json'), sheet_schema: fixture('sheet.schema.json'), rules: fixture('rules.json') })
    if (url.endsWith('/draft') && (!init?.method || init.method === 'GET')) return json({ type: 'x', title: 'draft.not_found', status: 404, code: 'draft.not_found' }, 404)
    if (url.endsWith('/grants')) return json({ data: [], balances: { attribute_points: 4, skill_points: 3, bonus: 5, xp: 0 } })
    if (url.endsWith('/draft') && init?.method === 'PUT') {
      const body = JSON.parse(String(init.body)) as { draft_version: number; change_set: unknown }
      puts.push(body)
      return json({ draft_version: body.draft_version + 1, base_sheet_revision: 0, change_set: body.change_set, validation: { contract: 'novtt.validation-result/1', valid: true, issues: [] }, balances: { attribute_points: 2, skill_points: 3, bonus: 5, xp: 0 } })
    }
    return json({}, 404)
  }) as typeof fetch
  return { fetcher, puts }
}

describe('CharacterDraftView', () => {
  it('shows the points left with preset labels and updates them after the autosave', async () => {
    const { fetcher, puts } = server()
    const root = document.createElement('div')
    document.body.append(root)
    app = createApp({ render: () => h(CharacterDraftView, { characterId: ID, fetcher }) })
    app.mount(root)
    await wait(30)

    const bar = () => [...root.querySelectorAll('.budget-bar dl > div')].map(d => `${d.querySelector('dt')!.textContent} ${d.querySelector('dd')!.textContent}`)
    expect(bar()).toEqual(['Pontos de atributo 4', 'Pontos de perícia 3', 'Pontos bônus 5', 'Experiência 0'])
    expect(root.textContent).not.toContain('attribute_points')

    root.querySelectorAll<HTMLButtonElement>('[id="f-attributes-might"] [role=radio]')[2].click()
    await wait(800) // autosave after 600 ms
    expect(puts).toHaveLength(1)
    expect(bar()[0]).toBe('Pontos de atributo 2')
  })
})
