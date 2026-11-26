import { ensureCsrfCookie } from '../api/client'
import type { SessionEvent } from './types'

/**
 * Realtime connection to Reverb (Pusher protocol) through the same origin (`/app`), with private
 * channel authorization by the first-party session (POST /api/broadcasting/auth). Holds no token;
 * the XSRF header is read from the cookie at authorization time.
 */
export type LinkState = 'connecting' | 'live' | 'reconnecting' | 'offline'

export interface Realtime {
  /** Subscribes to a private channel; returns an unsubscribe function. */
  subscribe(channel: string, onEvent: (e: SessionEvent) => void): () => void
  /** Ephemeral client events on a private channel (workspace follow, ADR 0011). */
  whisper(channel: string, event: string, data: Record<string, unknown>): void
  listenWhisper(channel: string, event: string, handler: (data: Record<string, unknown>) => void): () => void
  onState(handler: (state: LinkState) => void): () => void
  disconnect(): void
}

function xsrf(): string {
  const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)
  return m ? decodeURIComponent(m[1]) : ''
}

export async function connectRealtime(): Promise<Realtime> {
  const key = import.meta.env.VITE_REVERB_APP_KEY as string | undefined
  if (!key) throw new Error('realtime.unconfigured')
  await ensureCsrfCookie()
  const [{ default: Echo }, { default: Pusher }] = await Promise.all([import('laravel-echo'), import('pusher-js')])
  const secure = location.protocol === 'https:'
  const port = Number(location.port || (secure ? 443 : 80))
  const echo = new Echo({
    broadcaster: 'reverb',
    key,
    Pusher,
    wsHost: location.hostname,
    wsPort: port,
    wssPort: port,
    forceTLS: secure,
    enabledTransports: ['ws', 'wss'],
    disableStats: true,
    authorizer: (channel: { name: string }) => ({
      authorize: (socketId: string, callback: (error: Error | null, data: { auth: string } | null) => void) => {
        fetch('/api/broadcasting/auth', {
          method: 'POST',
          credentials: 'include',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
          body: JSON.stringify({ socket_id: socketId, channel_name: channel.name }),
        })
          .then(async r => (r.ok ? callback(null, await r.json()) : callback(new Error(`auth ${r.status}`), null)))
          .catch(e => callback(e instanceof Error ? e : new Error('auth'), null))
      },
    }),
  } as never)

  const handlers = new Set<(s: LinkState) => void>()
  const emit = (s: LinkState) => handlers.forEach(h => h(s))
  const conn = (echo.connector as unknown as { pusher: { connection: { bind: (e: string, cb: (s: { current: string }) => void) => void; state: string } } }).pusher.connection
  conn.bind('state_change', ({ current }) => {
    emit(current === 'connected' ? 'live' : current === 'connecting' ? 'reconnecting' : current === 'initialized' ? 'connecting' : 'offline')
  })

  return {
    subscribe(channel, onEvent) {
      const ch = echo.private(channel)
      ch.listen('.session.event', (e: SessionEvent) => onEvent(e))
      return () => echo.leave(channel)
    },
    whisper(channel, event, data) {
      ;(echo.private(channel) as unknown as { whisper: (e: string, d: unknown) => void }).whisper(event, data)
    },
    listenWhisper(channel, event, handler) {
      const ch = echo.private(channel) as unknown as { listenForWhisper: (e: string, cb: (d: Record<string, unknown>) => void) => void; stopListeningForWhisper: (e: string) => void }
      ch.listenForWhisper(event, handler)
      return () => ch.stopListeningForWhisper(event)
    },
    onState(handler) {
      handlers.add(handler)
      handler(conn.state === 'connected' ? 'live' : 'connecting')
      return () => handlers.delete(handler)
    },
    disconnect() {
      echo.disconnect()
    },
  }
}
