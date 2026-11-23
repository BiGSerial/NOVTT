// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, type App } from 'vue'
import DemoHub from '../src/demo/DemoHub.vue'
import { axeViolations } from './support'

let app: App | null = null
const flush = () => new Promise(resolve => setTimeout(resolve, 0))
const json = (body: unknown, status = 200) => new Response(body === null ? null : JSON.stringify(body), { status })
const state = (user: unknown, extra: Record<string, unknown> = {}) => ({
  seeded: true, user, campaign_id: 'c1', campaign_character_id: 'cc1', active_change_request_id: null, ...extra,
})

async function mount(fetcher: typeof fetch): Promise<HTMLElement> {
  const root = document.createElement('div')
  document.body.append(root)
  app = createApp({ render: () => h(DemoHub, { fetcher }) })
  app.mount(root)
  for (let i = 0; i < 5; i++) await flush()
  return root
}

afterEach(() => {
  app?.unmount()
  app = null
  document.body.replaceChildren()
})

describe('DemoHub', () => {
  it('explains how to enable demo mode when the server hides it (404)', async () => {
    const root = await mount(vi.fn(async () => json({ code: 'resource.not_found' }, 404)) as unknown as typeof fetch)
    expect(root.querySelector('[role=alert]')?.textContent).toContain('sh scripts/dev.sh demo')
  })

  it('signs in as player after obtaining the CSRF cookie and links to the sheet editor', async () => {
    let signedIn = false
    const calls: string[] = []
    const fetcher = vi.fn(async (url: string, init?: RequestInit) => {
      calls.push(`${init?.method ?? 'GET'} ${url}`)
      if (url === '/sanctum/csrf-cookie') return json(null, 204)
      if (url === '/api/dev/demo/session') { signedIn = true; return json(state({ id: 'u2', name: 'Jogador (demo)', role: 'player' })) }
      if (url === '/api/dev/demo') return json(state(signedIn ? { id: 'u2', name: 'Jogador (demo)', role: 'player' } : null))
      if (url === '/api/v1/campaign-characters/cc1') return json({ id: 'cc1', name: 'Aria', status: 'draft', sheet_revision: 0, sheet: {} })
      return json({ code: 'resource.not_found' }, 404)
    }) as unknown as typeof fetch
    const root = await mount(fetcher)
    expect(await axeViolations(root)).toEqual([])

    const player = [...root.querySelectorAll('button')].find(b => b.textContent === 'Jogador')!
    player.click()
    for (let i = 0; i < 8; i++) await flush()

    expect(calls.indexOf('GET /sanctum/csrf-cookie')).toBeLessThan(calls.indexOf('POST /api/dev/demo/session'))
    expect(root.querySelector<HTMLAnchorElement>('a.cta')?.getAttribute('href')).toBe('/#/personagens/cc1')
    expect(root.textContent).toContain('Aria')
    expect(root.querySelector('[role=status].status')?.textContent).toBe('Você entrou como jogador.')
    expect(await axeViolations(root)).toEqual([])
  })

  it('shows the real review panel to the master for the active proposal', async () => {
    const view = {
      id: 'cr1', status: 'submitted', review_version: 1,
      sections: [{ section_id: 'attributes', label: 'Atributos', operations: [{ id: 'op1', type: 'set_field', path: '/attributes/might', section_id: 'attributes', value: 3, decision: null }] }],
    }
    const fetcher = vi.fn(async (url: string) => {
      if (url === '/api/dev/demo') return json(state({ id: 'u1', name: 'Mestre (demo)', role: 'master' }, { active_change_request_id: 'cr1' }))
      if (url === '/api/v1/campaign-characters/cc1') return json({ id: 'cc1', name: 'Aria', status: 'draft', sheet_revision: 0, sheet: { attributes: { might: 1 } } })
      if (url === '/api/v1/change-requests/cr1') return json(view)
      return json({ code: 'resource.not_found' }, 404)
    }) as unknown as typeof fetch
    const root = await mount(fetcher)

    expect(root.querySelector('fieldset[data-section=attributes] legend')?.textContent).toBe('Atributos')
    expect(root.textContent).toContain('Alterar: /attributes/might → 3')
    expect(root.textContent).toContain('Aprovar personagem')
    expect(await axeViolations(root)).toEqual([])
  })
})
