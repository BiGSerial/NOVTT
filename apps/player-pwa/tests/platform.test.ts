import { describe, expect, it } from 'vitest'
import { detectPlatform, formFactorOf } from '../src/live/platform'

describe('platform shell', () => {
  it('a browser tab is web; an installed app is pwa, or android on Android', () => {
    expect(detectPlatform('Mozilla/5.0 (Linux; Android 14)', false)).toBe('web')
    expect(detectPlatform('Mozilla/5.0 (Linux; Android 14)', true)).toBe('android')
    expect(detectPlatform('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0)', true)).toBe('pwa')
    expect(detectPlatform('Mozilla/5.0 (Windows NT 10.0)', true)).toBe('pwa')
  })
  it('form factor follows the viewport width', () => {
    expect(formFactorOf(390)).toBe('phone')
    expect(formFactorOf(820)).toBe('tablet')
    expect(formFactorOf(1440)).toBe('desktop')
  })
})
