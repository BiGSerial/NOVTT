import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { CharacterPanel } from '../../master-web/resources/js/character-panel'

type MessageHandler = ((event: { data: unknown }) => void) | null
class FakeChannel {
  static instances: FakeChannel[] = []
  onmessage: MessageHandler = null
  constructor(readonly name: string) { FakeChannel.instances.push(this) }
  postMessage(data: unknown): void {
    for (const other of FakeChannel.instances) {
      if (other !== this && other.name === this.name) other.onmessage?.({ data })
    }
  }
  close(): void { FakeChannel.instances = FakeChannel.instances.filter(item => item !== this) }
}

describe('master workspace selection', () => {
  beforeEach(() => {
    FakeChannel.instances = []
    vi.stubGlobal('BroadcastChannel', FakeChannel)
    vi.stubGlobal('window', { addEventListener: vi.fn(), removeEventListener: vi.fn() })
  })
  afterEach(() => vi.unstubAllGlobals())

  it('keeps latest selection while pinned and restores it on unpin', () => {
    const render = vi.fn()
    const sender = new CharacterPanel('workspace-a', vi.fn())
    const receiver = new CharacterPanel('workspace-a', render)
    receiver.pin('pinned')
    sender.select('new-selection')
    expect(render).toHaveBeenCalledTimes(1)
    expect(render).toHaveBeenLastCalledWith('pinned')
    receiver.unpin()
    expect(render).toHaveBeenLastCalledWith('new-selection')
    sender.dispose()
    receiver.dispose()
  })

  it('rejects blank IDs before rendering', () => {
    const render = vi.fn()
    const panel = new CharacterPanel('workspace-a', render)
    expect(() => panel.select('  ')).toThrow('Invalid character ID')
    expect(render).not.toHaveBeenCalled()
    panel.dispose()
  })

  it('renders exactly once for a local selection', () => {
    const render = vi.fn()
    const panel = new CharacterPanel('workspace-a', render)
    panel.select('character-1')
    expect(render).toHaveBeenCalledTimes(1)
    expect(render).toHaveBeenCalledWith('character-1')
    panel.dispose()
  })

  it('isolates selections by workspace', () => {
    const render = vi.fn()
    const sender = new CharacterPanel('workspace-a', vi.fn())
    const other = new CharacterPanel('workspace-b', render)
    sender.select('character-1')
    expect(render).not.toHaveBeenCalled()
    sender.dispose()
    other.dispose()
  })
})
