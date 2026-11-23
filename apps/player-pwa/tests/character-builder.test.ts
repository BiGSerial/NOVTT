// @vitest-environment jsdom
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { afterEach, describe, expect, it } from 'vitest'
import { createApp, h, type App } from 'vue'
import CharacterDraftView from '../src/views/CharacterDraftView.vue'
import { matchesTarget, overlaps } from '../src/sheets/builder'
import { axeViolations } from './support'

const ID = '01a203a3-0b85-77ca-b6eb-4d3db2082f5c'
const ROOT = join(__dirname, '../../../presets/novtt-demo/demo-linhagens/1.0.0')
const part = (file: string) => JSON.parse(readFileSync(join(ROOT, file), 'utf8'))
let app: App | null = null
afterEach(() => { app?.unmount(); app = null; document.body.replaceChildren() })
const wait = (ms: number) => new Promise(r => setTimeout(r, ms))

const steps = (complete: boolean[]) => ({
  complete: complete.every(Boolean),
  steps: ['identity', 'origin', 'attributes', 'review'].map((id, i) => ({ id, available: complete.slice(0, i).every(Boolean), complete: complete[i], missing: complete[i] ? [] : ['/x'], condition_met: complete[i], issues: 0 })),
  issues: [],
})
const choices = (lineage: string | null) => [
  { id: 'lineage', path: '/origin/lineage', value: lineage, options: ['aurora', 'brasa', 'nevoa'].map(value => ({ value, available: true })) },
  { id: 'gift', path: '/origin/gift', value: null, options: [
    { value: 'voo_curto', available: lineage === 'aurora' },
    { value: 'pele_de_pedra', available: lineage === 'brasa' },
    { value: 'sussurro', available: true },
  ] },
]

function server() {
  const puts: { draft_version: number; change_set: { operations: { path: string; value: unknown }[] } }[] = []
  const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
  const compendium = part('compendium.json').items.map((i: Record<string, unknown>) => ({ canonical_id: i.canonical_id, kind: i.kind, labels: i.labels, summary: i.summary }))
  const fetcher = (async (url: string, init?: RequestInit) => {
    if (url.endsWith(`/campaign-characters/${ID}`)) {
      return json({ id: ID, campaign_id: 'c1', name: 'Iara', status: 'draft', sheet_revision: 0, preset_version_id: 'pv', sheet: { identity: { name: 'Iara' }, derived: { vitality: 4, initiative: 2, load_capacity: 5 } }, decision_trace: [], creation: steps([true, false, false, false]), choices: choices(null) })
    }
    if (url.endsWith('/preset-versions/pv')) return json({ display_name: 'Linhagens', sheet_ui: part('sheet.ui.json'), sheet_schema: part('sheet.schema.json'), rules: part('rules.json'), compendium })
    if (url.endsWith('/draft') && (!init?.method || init.method === 'GET')) return json({ code: 'draft.not_found' }, 404)
    if (url.endsWith('/grants')) return json({ data: [], balances: { physical: 0, social: 0, mental: 0, skill_points: 6, xp: 0 } })
    if (url.endsWith('/change-requests')) return json({ data: [] })
    if (url.endsWith('/draft') && init?.method === 'PUT') {
      const body = JSON.parse(String(init.body))
      puts.push(body)
      const lineage = body.change_set.operations.find((o: { path: string }) => o.path === '/origin/lineage')?.value ?? null
      return json({
        draft_version: body.draft_version + 1, base_sheet_revision: 0, change_set: body.change_set,
        validation: { contract: 'novtt.validation-result/1', valid: true, issues: [] },
        balances: { physical: 0, social: 0, mental: 0, skill_points: 6, xp: 0 },
        preview: { sheet: { identity: { name: 'Iara' }, origin: { lineage }, derived: { vitality: 4, initiative: 2, load_capacity: 5 } }, decision_trace: [], creation: steps([true, false, false, false]), choices: choices(lineage) },
      })
    }
    return json({}, 404)
  }) as typeof fetch
  return { fetcher, puts }
}

async function mount() {
  const { fetcher, puts } = server()
  const root = document.createElement('div')
  document.body.append(root)
  app = createApp({ render: () => h(CharacterDraftView, { characterId: ID, fetcher }) })
  app.mount(root)
  await wait(40)
  return { root, puts }
}

describe('guided character builder', () => {
  it('opens on the first incomplete step of the preset and lists every step', async () => {
    const { root } = await mount()
    expect([...root.querySelectorAll('.stepper .step-label')].map(e => e.textContent)).toEqual(['Identidade', 'Origem', 'Atributos e habilidades', 'Revisão'])
    expect(root.querySelector('#builder-step-title')!.textContent).toBe('Origem')
    expect(root.querySelector('.stepper li.done .step-label')!.textContent).toBe('Identidade')
  })

  it('shows choices as cards with compendium summaries and locks options by prerequisite', async () => {
    const { root, puts } = await mount()
    const cards = () => [...root.querySelectorAll<HTMLButtonElement>('.choice-card')]
    expect(cards().map(c => c.querySelector('.choice-name')!.textContent)).toEqual(['Aurora', 'Brasa', 'Névoa', 'Voo curto', 'Pele de pedra', 'Sussurro'])
    expect(cards()[0].querySelector('.choice-summary')!.textContent).toContain('primeiro raio')
    expect(cards()[3].getAttribute('aria-disabled')).toBe('true')

    cards()[0].click() // Aurora
    await wait(800)
    expect(puts).toHaveLength(1)
    expect(puts[0].change_set.operations).toEqual([expect.objectContaining({ path: '/origin/lineage', value: 'aurora' })])
    expect(cards()[0].getAttribute('aria-checked')).toBe('true')
    expect(cards()[3].getAttribute('aria-disabled')).toBeNull() // Voo curto unlocked by the server preview

    cards()[4].click() // Pele de pedra stays locked: nothing is sent
    await wait(800)
    expect(puts).toHaveLength(1)
  })

  it('shows the step budgets, derived values and only the step fields; the review step submits', async () => {
    const { root } = await mount()
    ;[...root.querySelectorAll<HTMLButtonElement>('.stepper button')][2].click()
    await wait(10)
    expect([...root.querySelectorAll('.budget-bar dt')].map(e => e.textContent)).toEqual([
      'Pontos de atributos físicos', 'Pontos de atributos sociais', 'Pontos de atributos mentais', 'Pontos de habilidade',
    ])
    expect(root.querySelector('[id="f-attributes-physical-forca"]')).not.toBeNull()
    expect(root.querySelector('[id="f-identity-name"]')).toBeNull()
    expect(root.querySelector('.builder-side')!.textContent).toContain('Vitalidade')

    ;[...root.querySelectorAll<HTMLButtonElement>('.stepper button')][3].click()
    await wait(10)
    expect([...root.querySelectorAll('.actions button')].map(b => b.textContent)).toEqual(['Voltar', 'Salvar rascunho', 'Enviar para o mestre'])
    expect(root.querySelector('.review-box')!.textContent).toContain('completar')
    expect(await axeViolations(root)).toEqual([])
  })

  it('matches rules targets the same way the engine does', () => {
    expect(matchesTarget('/attributes/physical/*', '/attributes/physical/forca')).toBe(true)
    expect(matchesTarget('/attributes/*', '/attributes/physical/forca')).toBe(false)
    expect(matchesTarget('/identity/name', '/identity/name')).toBe(true)
    expect(overlaps('/attributes/physical/*', '/attributes/physical/*')).toBe(true)
    expect(overlaps('/skills/*', '/attributes/physical/*')).toBe(false)
  })
})
