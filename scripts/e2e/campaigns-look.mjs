// Visual pass of the campaigns page for a player and the master (desktop and phone).
import { chromium } from 'playwright'
const BASE = 'http://localhost:5173/'
const out = process.env.E2E_OUT ?? '/out'
const browser = await chromium.launch()
for (const [seat, tag] of [['Lyra', 'player'], ['Mestre', 'master']]) {
  for (const [w, h, size] of [[1440, 900, 'desktop'], [390, 844, 'phone']]) {
    const page = await (await browser.newContext({ viewport: { width: w, height: h } })).newPage()
    await page.goto(BASE)
    await page.getByRole('button', { name: new RegExp(`^${seat}`) }).first().click()
    await page.waitForURL(/#\/(mesa|campanhas)/)
    await page.goto(BASE + '#/campanhas')
    await page.locator('.camp-facts').first().waitFor({ timeout: 15000 })
    await page.waitForTimeout(1500)
    await page.screenshot({ path: `${out}/campaigns-${tag}-${size}.png`, fullPage: true })
    console.log(seat, size, (await page.locator('.camp-facts').first().textContent()).replace(/\s+/g, ' '))
  }
}
await browser.close()
