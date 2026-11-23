import type { ValidationResult } from '../sheets/types'

/** RFC 9457 problem with the stable NOVTT code. */
export class ApiError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    readonly validation?: ValidationResult,
    readonly currentRevision?: number,
  ) {
    super(code)
  }
}

function xsrfToken(): string | null {
  const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1]) : null
}

/**
 * First-party API client (ADR 0009): session cookie + CSRF header, no token in storage.
 * Mutations that need idempotency pass a key generated once per user intent.
 */
export async function api<T>(
  method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE',
  path: string,
  body?: unknown,
  options: { idempotencyKey?: string; fetcher?: typeof fetch } = {},
): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  const token = xsrfToken()
  if (token) headers['X-XSRF-TOKEN'] = token
  if (options.idempotencyKey) headers['Idempotency-Key'] = options.idempotencyKey
  let response: Response
  try {
    response = await (options.fetcher ?? fetch)(`/api/v1${path}`, {
      method,
      headers,
      credentials: 'include',
      body: body === undefined ? undefined : JSON.stringify(body),
    })
  } catch {
    throw new ApiError(0, 'network.error')
  }
  if (response.status === 204) return undefined as T
  const data = await response.json().catch(() => ({}))
  if (!response.ok) {
    throw new ApiError(response.status, String(data.code ?? `http.${response.status}`), data.validation, data.current_revision)
  }
  return data as T
}
