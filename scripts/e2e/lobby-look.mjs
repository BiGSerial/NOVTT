// The session lobby (no open session): master and player, desktop and phone.
import { chromium } from 'playwright'
const BASE = 'http://localhost:5173/'
const out = process.env.E2E_OUT ?? '/out'
const CAMPAIGN = process.env.CAMPAIGN ?? 'Campanha de demonstração'
const browser = await chromium.launch()
for (const [seat, tag, w, h] of [['Mestre', 'master', 1440, 900], ['Lyra', 'player', 390, 844]]) {
  const page = await (await browser.newContext({ viewport: { width: w, height: h } })).newPage()
  await page.goto(BASE)
  await page.getByRole('button', { name: new RegExp(`^${seat}`) }).first().click()
  await page.waitForURL(/#\/(mesa|campanhas)/)
  await page.goto(BASE + '#/campanhas')
  const card = page.locator('.campaign-card', { hasText: CAMPAIGN })
  await card.waitFor({ timeout: 15000 })
  await card.locator('.card-actions a').first().click()
  await page.locator('.lobby').waitFor({ timeout: 15000 })
  await page.waitForTimeout(1500)
  await page.screenshot({ path: `${out}/lobby-${tag}.png`, fullPage: true })
  console.log(tag, (await page.locator('.lobby-card').first().textContent()).replace(/\s+/g, ' ').slice(0, 120))
}
await browser.close()
console.log('OK')
