import { WorkspaceSync } from './workspace-sync'

/** Controller for a character detail panel that may follow or pin selection. */
export class CharacterPanel {
  private readonly sync: WorkspaceSync
  private unsubscribe: () => void
  private pinnedCharacterId: string | null = null
  private selectedCharacterId: string | null = null

  constructor(
    workspaceId: string,
    private readonly render: (characterId: string | null) => void,
  ) {
    this.sync = new WorkspaceSync(workspaceId)
    this.unsubscribe = this.sync.subscribe(message => {
      if (message.type === 'character.selected' && this.pinnedCharacterId === null) {
        this.selectedCharacterId = message.payload.character_id
        this.render(this.selectedCharacterId)
      }
    })
  }

  select(characterId: string | null): void {
    this.selectedCharacterId = characterId
    if (this.pinnedCharacterId === null) this.render(characterId)
    this.sync.selectCharacter(characterId)
  }

  pin(characterId: string): void {
    if (!characterId.trim()) throw new Error('Invalid character ID')
    this.pinnedCharacterId = characterId
    this.render(characterId)
  }

  unpin(): void {
    this.pinnedCharacterId = null
    this.render(this.selectedCharacterId)
  }

  dispose(): void {
    this.unsubscribe()
    this.sync.close()
  }
}
