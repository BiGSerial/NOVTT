// @vitest-environment jsdom
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick } from 'vue'
import { allDecided, labelsOf, optionLabelsOf, reasonError, type ChangeRequestView, type PresetSummary } from '../src/live/campaign'
import { sheetSections } from '../src/live/sheet'
import type { SheetUi } from '../src/sheets/types'
import PresetBadge from '../src/views/campaign/PresetBadge.vue'
import ProposalReview from '../src/views/campaign/ProposalReview.vue'
import { axeViolations } from './support'

const DEMO = join(__dirname, '../../../presets/novtt-demo/demo-linhagens/1.0.0')
const demo = <T>(file: string): T => JSON.parse(readFileSync(join(DEMO, file), 'utf8')) as T
const ui = demo<SheetUi>('sheet.ui.json')
const schema = demo<Record<string, unknown>>('sheet.schema.json')

function mount(component: object, props: Record<string, unknown>): HTMLElement {
  const el = document.createElement('div')
  document.body.append(el)
  createApp({ render: () => h(component as never, props) }).mount(el)
  return el
}
const flush = async () => { for (let i = 0; i < 4; i++) { await Promise.resolve(); await nextTick() } }

function request(overrides: Partial<ChangeRequestView> = {}): ChangeRequestView {
  return {
    id: 'cr-1', campaign_character_id: 'ch-1', status: 'submitted', review_version: 1,
    sections: [
      { section_id: 'attributes-physical', label: 'Atributos físicos', operations: [
        { id: 'op1', type: 'set_field', path: '/attributes/physical/forca', section_id: 'attributes-physical', value: 3, decision: null },
        { id: 'op2', type: 'set_field', path: '/attributes/physical/vigor', section_id: 'attributes-physical', value: 2, decision: { decision: 'rejected', reason: 'Combine com o conceito.', final: false } },
      ] },
      { section_id: 'origin', label: 'Origem', operations: [
        { id: 'op3', type: 'set_field', path: '/origin/lineage', section_id: 'origin', value: 'aurora', decision: null },
      ] },
    ],
    validation: { valid: false, issues: [{ rule_id: 'budget.insufficient', operation_ids: ['op1'], paths: ['/attributes/physical/forca'], params: { budget: 'physical', required: 1, available: 0 } }] },
    ...overrides,
  }
}

afterEach(() => { vi.unstubAllGlobals(); document.body.replaceChildren(); document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT' })

describe('campaign set-up helpers', () => {
  it('validates rejection reasons with the same bounds as the server', () => {
    expect(reasonError('curto')).toMatch(/pelo menos 10/)
    expect(reasonError('x'.repeat(1001))).toMatch(/no máximo 1000/)
    expect(reasonError('Motivo suficiente.')).toBeNull()
    expect(allDecided(request())).toBe(false)
  })

  it('labels nested preset paths and collection item fields from the layout', () => {
    const labels = labelsOf(ui)
    expect(labels.get('/attributes/physical/forca')).toBe('Força')
    expect(labels.get('/inventory#weight')).toBe('Peso')
  })

  it('shows unwritten numbers at the preset default, but never hidden fields', () => {
    const sections = sheetSections(ui as never, { attributes: { physical: { forca: 3 } } }, schema)
    const physical = sections.find(s => s.id === 'attributes-physical')!
    expect(physical.fields.map(f => [f.path, f.value])).toEqual([['/attributes/physical/forca', 3], ['/attributes/physical/destreza', 1], ['/attributes/physical/vigor', 1]])
    expect(sections.find(s => s.id === 'notes')).toBeUndefined()
    const origin = sheetSections(ui as never, { origin: { lineage: 'aurora' } }, schema, { '/origin/lineage': { aurora: 'Aurora' } }).find(s => s.id === 'origin')!
    expect(origin.fields[0].value).toBe('Aurora')
    // Without a schema nothing is inferred (older callers).
    expect(sheetSections(ui as never, {}).find(s => s.id === 'attributes-physical')).toBeUndefined()
  })
})

describe('PresetBadge', () => {
  it('shows revision, coverage, taxonomy, licence and limitations', () => {
    const preset: PresetSummary = {
      id: 'p', family_id: 'novtt-demo', variant_id: 'demo-linhagens', version: '1.0.0', display_name: 'Linhagens', status: 'published', canonical: true,
      rules_revision: 'original-2026', source_version: 'demo-1', locale: 'pt-BR', license: { identifier: 'NOVTT-original', attribution: 'Conteúdo original.', source_url: null },
      rights_status: 'approved', source_authenticity: 'original', coverage: 'playable_core', limitations: ['Poucos atributos.'],
      taxonomy: [{ kind: 'lineage', labels: { 'pt-BR': 'Linhagem' }, parent_kind: null }, { kind: 'gift', labels: { 'pt-BR': 'Dom' }, parent_kind: null }],
    }
    const text = mount(PresetBadge, { preset }).textContent ?? ''
    for (const part of ['Linhagens', 'Revisão original-2026', 'Núcleo jogável', 'Linhagem, Dom', 'Licença NOVTT-original', 'Conteúdo original', 'Poucos atributos.']) expect(text).toContain(part)
  })
})

describe('ProposalReview', () => {
  const props = (view: ChangeRequestView) => ({ request: view, sheet: { attributes: { physical: { forca: 1 } } }, labels: labelsOf(ui), budgetLabels: { physical: 'Pontos de atributos físicos' } })

  it('uses choice labels from the rules or the compendium instead of raw values', () => {
    const labels = optionLabelsOf({ locale: 'pt-BR', rules: demo('rules.json'), compendium: demo<{ items: { canonical_id: string; labels: Record<string, string> }[] }>('compendium.json').items })
    expect(labels['/origin/lineage'].aurora).toBe('Aurora')
    expect(labels['/origin/gift'].voo_curto).toBe('Voo curto')
    const el = mount(ProposalReview, { ...props(request()), optionLabels: labels })
    expect([...el.querySelectorAll('.after')].map(a => a.textContent)).toContain('Aurora')
  })

  it('shows labelled before → after values and the engine issue in pt-BR', async () => {
    const el = mount(ProposalReview, props(request()))
    const first = el.querySelector('.op-row')!
    expect(first.querySelector('.op-label')?.textContent).toBe('Força')
    expect(first.querySelector('.before')?.textContent).toBe('1')
    expect(first.querySelector('.after')?.textContent).toBe('3')
    expect(first.textContent).toContain('Saldo insuficiente em Pontos de atributos físicos')
    expect(el.querySelector('.op-row.rejected')?.textContent).toContain('Motivo: Combine com o conceito.')
    expect((el.querySelector('button.primary') as HTMLButtonElement).disabled).toBe(true)
    expect(await axeViolations(el)).toEqual([])
  })

  it('approves a section without overriding a rejection and requires a reason to reject', async () => {
    const fetcher = vi.fn(async (_url: string, init: RequestInit) => new Response(JSON.stringify(request({ review_version: 2 })), { status: 200, headers: { 'Content-Type': 'application/json' } }) as Response & { init?: RequestInit } )
    document.cookie = 'XSRF-TOKEN=t'
    vi.stubGlobal('fetch', fetcher)
    const el = mount(ProposalReview, props(request()))
    const sectionButton = [...el.querySelectorAll('fieldset')][0].querySelector(':scope > button') as HTMLButtonElement
    sectionButton.click()
    await flush()
    const body = JSON.parse(String((fetcher.mock.calls[0][1] as RequestInit).body))
    expect(body).toEqual({ review_version: 1, decisions: [{ operation_ids: ['op1'], decision: 'approved' }] })

    const rejectToggle = [...el.querySelectorAll('.op-row')][2].querySelectorAll('.op-actions button')[1] as HTMLButtonElement
    rejectToggle.click()
    await nextTick()
    const confirm = el.querySelector('.reject-form button') as HTMLButtonElement
    confirm.click()
    await nextTick()
    expect(el.querySelector('.reject-form [role="alert"]')?.textContent).toMatch(/pelo menos 10/)
    expect(fetcher).toHaveBeenCalledTimes(1)
  })

  it('finalizes only when every operation is decided, with an idempotency key', async () => {
    const decided = request({ sections: request().sections.map(s => ({ ...s, operations: s.operations.map(o => ({ ...o, decision: { decision: 'approved' as const, reason: null, final: false } })) })) })
    const fetcher = vi.fn(async () => new Response(JSON.stringify({ ...decided, status: 'applied' }), { status: 200, headers: { 'Content-Type': 'application/json' } }))
    document.cookie = 'XSRF-TOKEN=t'
    vi.stubGlobal('fetch', fetcher)
    const el = mount(ProposalReview, props(decided))
    const finalize = el.querySelector('button.primary') as HTMLButtonElement
    expect(finalize.disabled).toBe(false)
    finalize.click()
    await flush()
    const [url, init] = fetcher.mock.calls[0] as unknown as [string, RequestInit]
    expect(url).toBe('/api/v1/change-requests/cr-1/finalize')
    expect((init.headers as Record<string, string>)['Idempotency-Key']).toMatch(/[0-9a-f-]{36}/)
    expect(el.querySelector('[role="status"]')?.textContent).toBe('Proposta aplicada à ficha.')
  })
})
