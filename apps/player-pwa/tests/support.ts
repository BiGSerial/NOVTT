import axe from 'axe-core'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'

export const FIXTURE = join(__dirname, '../../../presets/fixtures/demo-basic/1.0.0')

export function fixture<T>(file: string): T {
  return JSON.parse(readFileSync(join(FIXTURE, file), 'utf8')) as T
}

/** WCAG 2.x A/AA checks available in jsdom (colour contrast needs a real browser). */
export async function axeViolations(root: Element): Promise<string[]> {
  const result = await axe.run(root, {
    runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] },
    rules: { 'color-contrast': { enabled: false } },
  })
  return result.violations.map(v => `${v.id}: ${v.nodes.map(n => n.target.join(' ')).join(', ')}`)
}
