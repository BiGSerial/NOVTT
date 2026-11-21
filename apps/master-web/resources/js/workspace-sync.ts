/**
 * Ephemeral GM workspace synchronization. Not an authorization mechanism.
 * Do not transmit credentials or sensitive game-state payloads.
 */
export type WorkspaceMessage = {
  schema_version: 1
  workspace_id: string
  sender_id: string
  message_id: string
  type: 'character.selected'
  payload: { character_id: string | null }
  timestamp: number
}

export class WorkspaceSync {
  private readonly senderId = crypto.randomUUID()
  private readonly channel: BroadcastChannel | null
  private readonly channelName: string
  private readonly storageKey: string
  private readonly listeners = new Set<(message: WorkspaceMessage) => void>()
  private readonly seen = new Set<string>()

  constructor(private readonly workspaceId: string) {
    if (!workspaceId.trim()) throw new Error('workspaceId required')
    this.channelName = 'novtt-master-workspace-v1:' + workspaceId
    this.storageKey = this.channelName + ':event'
    this.channel = typeof BroadcastChannel === 'undefined'
      ? null
      : new BroadcastChannel(this.channelName)
    if (this.channel) this.channel.onmessage = (event: MessageEvent<unknown>) => this.receive(event.data)
    window.addEventListener('storage', this.onStorage)
  }

  subscribe(listener: (message: WorkspaceMessage) => void): () => void {
    this.listeners.add(listener)
    return () => this.listeners.delete(listener)
  }

  selectCharacter(characterId: string | null): void {
    if (characterId !== null && !characterId.trim()) throw new Error('Invalid character ID')
    const message: WorkspaceMessage = {
      schema_version: 1,
      workspace_id: this.workspaceId,
      sender_id: this.senderId,
      message_id: crypto.randomUUID(),
      type: 'character.selected',
      payload: { character_id: characterId },
      timestamp: Date.now(),
    }
    this.channel?.postMessage(message)
    if (!this.channel) {
      try {
        localStorage.setItem(this.storageKey, JSON.stringify(message))
        localStorage.removeItem(this.storageKey)
      } catch { /* unavailable */ }
    }
    this.dispatch(message)
  }

  private readonly onStorage = (event: StorageEvent): void => {
    if (event.key !== this.storageKey || !event.newValue) return
    try { this.receive(JSON.parse(event.newValue) as unknown) } catch { /* malformed */ }
  }

  private receive(value: unknown): void {
    if (typeof value !== 'object' || value === null) return
    const message = value as Partial<WorkspaceMessage>
    if (message.schema_version !== 1 || message.workspace_id !== this.workspaceId ||
        message.sender_id === this.senderId || typeof message.sender_id !== 'string' ||
        typeof message.message_id !== 'string' || !message.message_id ||
        message.type !== 'character.selected' ||
        !message.payload || typeof message.payload !== 'object' ||
        !('character_id' in message.payload) ||
        !(message.payload.character_id === null || typeof message.payload.character_id === 'string')) return
    if (this.seen.has(message.message_id)) return
    this.seen.add(message.message_id)
    if (this.seen.size > 500) this.seen.clear()
    this.dispatch(message as WorkspaceMessage)
  }

  private dispatch(message: WorkspaceMessage): void {
    for (const listener of this.listeners) listener(message)
  }

  close(): void {
    window.removeEventListener('storage', this.onStorage)
    this.channel?.close()
    this.listeners.clear()
  }
}
