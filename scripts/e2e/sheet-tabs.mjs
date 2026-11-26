// The player's sheet in tabs on a phone (Ordem Paranormal internal campaign and the live demo).
import { chromium } from 'playwright'
const BASE = 'http://localhost:5173/'
const out = process.env.E2E_OUT ?? '/out'
const browser = await chromium.launch()
const page = await (await browser.newContext({ viewport: { width: 390, height: 844 } })).newPage()
page.on('pageerror', e => console.log('[pageerror]', e.message.slice(0, 200)))
await page.goto(BASE)
await page.getByRole('button', { name: /^Lyra/ }).first().click()
await page.waitForURL(/#\/(mesa|campanhas)/)
for (const [campaign, tag] of [['Ordem Paranormal (teste interno)', 'op'], ['A Ponte de Vau Cinzento (demo)', 'mesa']]) {
  await page.goto(BASE + '#/campanhas')
  const card = page.locator('.campaign-card', { hasText: campaign })
  await card.waitFor({ timeout: 15000 })
  await card.locator('.card-actions a').first().click()
  await page.locator('.paper-sheet').waitFor({ timeout: 15000 })
  await page.waitForTimeout(800)
  const tabs = await page.locator('.sheet-tabs [role=tab]').allTextContents()
  console.log(tag, 'tabs:', tabs.join(' | '))
  for (const [i, t] of tabs.entries()) {
    await page.locator('.sheet-tabs [role=tab]').nth(i).click()
    await page.waitForTimeout(400)
    await page.locator('.paper-sheet').screenshot({ path: `${out}/tabs-${tag}-${i + 1}-${t.toLowerCase()}.png` })
  }
}
await browser.close()
console.log('OK')
