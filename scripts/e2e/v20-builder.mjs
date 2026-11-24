// Vampire V20 (internal): a player picks Nosferatu and Appearance arrives locked at 0.
import { chromium } from 'playwright'
const BASE = 'http://localhost:5173/'
const out = process.env.E2E_OUT ?? '/out'
const CAMPAIGN = 'Vampiro V20 (teste interno)'
const browser = await chromium.launch()
const log = (...a) => console.log(new Date().toISOString().slice(11, 19), ...a)
const fail = msg => { console.error('FAIL:', msg); process.exit(1) }
const page = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage()
page.on('pageerror', e => log('[pageerror]', e.message.slice(0, 200)))
await page.goto(BASE)
await page.getByRole('button', { name: /^Lyra/ }).first().click()
await page.waitForURL(/#\/(mesa|campanhas)/)
await page.goto(BASE + '#/campanhas')
const card = page.locator('.campaign-card', { hasText: CAMPAIGN })
await card.waitFor({ timeout: 15000 })
await card.locator('.my-characters').waitFor({ timeout: 15000 })
const create = card.getByRole('button', { name: 'Criar personagem' })
if (await create.isVisible()) {
  await create.click()
  await card.getByLabel('Nome do personagem').fill('Mateus')
  await card.getByRole('button', { name: 'Começar a ficha' }).click()
} else await card.locator('a[href*="#/personagens/"]').first().click()
await page.waitForURL(/#\/personagens\//)
await page.locator('.stepper').waitFor({ timeout: 15000 })
await page.locator('.stepper button', { hasText: 'Clã' }).click()
await page.waitForTimeout(600)
const nos = page.getByRole('radio', { name: /^Nosferatu/ })
if ((await nos.getAttribute('aria-checked')) !== 'true') await nos.click()
await page.waitForTimeout(1500)
await page.screenshot({ path: `${out}/v20-1-clan.png`, fullPage: true })
await page.locator('.stepper button', { hasText: 'Atributos' }).click()
await page.waitForTimeout(1200)
const row = page.locator('.stat-row', { hasText: /Aparência/ })
const lock = (await row.locator('.stat-locked').textContent().catch(() => '')) ?? ''
log('Aparência:', (await row.locator('.stat-value').textContent()).trim(), '|', lock.trim())
if (!/Fixado/.test(lock)) fail('Appearance not locked by the clan')
if (!(await row.getByRole('button', { name: /Aumentar/ }).isDisabled())) fail('Appearance can still be raised')
await row.scrollIntoViewIfNeeded()
await page.screenshot({ path: `${out}/v20-2-attributes.png`, fullPage: true })
await browser.close()
log('OK')
