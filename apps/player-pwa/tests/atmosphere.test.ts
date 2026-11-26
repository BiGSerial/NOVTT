import { describe, expect, it } from 'vitest'
import { currentHour, describeChange, formatHour, mixHex, phaseOf, skyState, tweenHour, weatherMix, type SkyPalette } from '../src/live/atmosphere'

const P: SkyPalette = {
  nightTop: '#000010', nightBottom: '#101030', dawnTop: '#303060', dawnBottom: '#e09060',
  dayTop: '#4080c0', dayBottom: '#b0d0f0', light: '#ffffff', glow: '#ffb000', fog: '#aaaaaa',
}
const clear = weatherMix('clear', 0.5)

describe('atmosphere engine', () => {
  it('uses the night colours at night and the day colours at noon', () => {
    expect(skyState(1, clear, P).top).toBe('#000010')
    expect(skyState(12, clear, P).top).toBe('#4080c0')
    expect(skyState(12, clear, P).daylight).toBeGreaterThan(0.25)
    expect(skyState(1, clear, P).daylight).toBe(0)
  })

  it('moves the sun from east to west during the day and the moon during the night', () => {
    const morning = skyState(8, clear, P).sun
    const noon = skyState(12, clear, P).sun
    const evening = skyState(16, clear, P).sun
    expect(morning.x).toBeLessThan(noon.x)
    expect(noon.x).toBeLessThan(evening.x)
    expect(noon.y).toBeLessThan(morning.y) // higher in the sky (smaller y)
    expect(skyState(23, clear, P).sun.opacity).toBe(0)
    expect(skyState(0, clear, P).moon.opacity).toBeGreaterThan(0.5)
    expect(skyState(12, clear, P).moon.opacity).toBe(0)
  })

  it('hides stars and greys the sky under heavy weather', () => {
    const storm = skyState(1, weatherMix('storm', 1), P)
    expect(storm.stars).toBeLessThan(skyState(1, clear, P).stars)
    expect(storm.rain).toBe(1)
    expect(skyState(12, weatherMix('fog', 0.8), P).fog).toBe(0.8)
  })

  it('follows the clock when time flows and never rewinds in transitions', () => {
    const setAt = new Date(Date.UTC(2026, 0, 1, 0, 0, 0)).toISOString()
    const a = { hour: 23, weather: 'clear' as const, intensity: 0.5, time_scale: 60, transition_seconds: 4, set_at: setAt }
    expect(currentHour(a, Date.parse(setAt) + 2 * 60000)).toBeCloseTo(1) // 2 real minutes = 2 game hours, wraps
    expect(currentHour({ ...a, time_scale: 0 }, Date.parse(setAt) + 600000)).toBe(23)
    expect(tweenHour(22, 2, 0.5)).toBe(0) // forward through midnight, not back through noon
  })

  it('formats the clock and tells players what changed', () => {
    expect(formatHour(6.25)).toBe('06:15')
    expect(phaseOf(19)).toBe('Entardecer')
    expect(describeChange({ hour: 12, weather: 'clear', intensity: 0.5, time_scale: 0, transition_seconds: 4 }, { hour: 12, weather: 'fog', intensity: 0.5, time_scale: 0, transition_seconds: 4 })).toBe('A névoa avança.')
    expect(describeChange({ hour: 12, weather: 'fog', intensity: 0.5, time_scale: 0, transition_seconds: 4 }, { hour: 21, weather: 'fog', intensity: 0.5, time_scale: 0, transition_seconds: 4 })).toBe('Noite.')
    expect(mixHex('#000000', '#ffffff', 0.5)).toBe('#808080')
  })
})
