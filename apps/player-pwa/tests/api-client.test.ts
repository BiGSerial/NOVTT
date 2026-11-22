// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest'
import { api, ApiError } from '../src/api/client'

describe('api client', () => {
  it('sends credentials, CSRF and idempotency headers, never a stored token', async () => {
    document.cookie = 'XSRF-TOKEN=abc%3D'
    const fetcher = vi.fn(async () => new Response(JSON.stringify({ ok: true }), { status: 200 }))
    await api('POST', '/campaigns', { name: 'X' }, { idempotencyKey: 'k1', fetcher })

    const [url, init] = fetcher.mock.calls[0] as unknown as [string, RequestInit]
    expect(url).toBe('/api/v1/campaigns')
    expect(init.credentials).toBe('include')
    expect(init.headers).toMatchObject({ 'X-XSRF-TOKEN': 'abc=', 'Idempotency-Key': 'k1', 'Content-Type': 'application/json' })
    expect(JSON.stringify(init.headers)).not.toMatch(/Authorization/)
  })

  it('maps RFC 9457 problems to stable codes', async () => {
    const fetcher = vi.fn(async () => new Response(JSON.stringify({ code: 'draft.stale', current_revision: 4, validation: { valid: false, issues: [] } }), { status: 409, headers: { 'Content-Type': 'application/problem+json' } }))
    await expect(api('POST', '/x', {}, { fetcher })).rejects.toMatchObject({ status: 409, code: 'draft.stale', currentRevision: 4 })
  })

  it('reports network failures as network.error', async () => {
    const fetcher = vi.fn(async () => { throw new TypeError('offline') })
    await expect(api('GET', '/x', undefined, { fetcher })).rejects.toBeInstanceOf(ApiError)
    await expect(api('GET', '/x', undefined, { fetcher })).rejects.toMatchObject({ code: 'network.error' })
  })
})
