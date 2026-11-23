import { parseRecord, type RollAnimationRecord } from './record'

/**
 * Same-browser mirror of local rolls (e.g. the master view in another window of the demo).
 * Records stay LOCAL / UNOFFICIAL: nothing is sent to the server. Official rolls will carry the
 * same record shape from the server event instead (see docs/development/dice-3d.md).
 */
const CHANNEL = 'novtt.dice.local.v1'

export function publishRoll(record: RollAnimationRecord): void {
  if (typeof BroadcastChannel === 'undefined') return
  const channel = new BroadcastChannel(CHANNEL)
  try {
    channel.postMessage(record)
  } finally {
    channel.close()
  }
}

export function subscribeRolls(handler: (record: RollAnimationRecord) => void): () => void {
  if (typeof BroadcastChannel === 'undefined') return () => {}
  const channel = new BroadcastChannel(CHANNEL)
  channel.onmessage = event => {
    const record = parseRecord(event.data)
    if (record) handler(record)
  }
  return () => channel.close()
}
