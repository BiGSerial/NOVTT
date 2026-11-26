// Visual pass of the guided builder on "Heroico": a player creates a character and walks every
// step; screenshots at desktop and phone widths go to ./e2e-output/look-*.png.
import { chromium } from 'playwright'
const BASE = 'http://localhost:5173/'
const out = process.env.E2E_OUT ?? '/out'
const CAMPAIGN = process.env.CAMPAIGN ?? 'Heroico: a Estrada de Sal (demo)'
const browser = await chromium.launch()
const log = (...a) => console.log(new Date().toISOString().slice(11, 19), ...a)
const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } })
const page = await ctx.newPage()
page.on('pageerror', e => log('[pageerror]', e.message.slice(0, 200)))
await page.goto(BASE)
await page.getByRole('button', { name: /^Bram/ }).first().click()
await page.waitForURL(/#\/(mesa|campanhas)/)
await page.goto(BASE + '#/campanhas')
const card = page.locator('.campaign-card', { hasText: CAMPAIGN })
await card.waitFor({ timeout: 15000 })
await card.locator('.my-characters').waitFor({ timeout: 15000 })
const create = card.getByRole('button', { name: 'Criar personagem' })
if (await create.isVisible()) {
  await create.click()
  await card.getByLabel('Nome do personagem').fill('Teste Visual')
  await card.getByRole('button', { name: 'Começar a ficha' }).click()
} else {
  await card.locator('a[href*="#/personagens/"]').first().click()
}
await page.waitForURL(/#\/personagens\//)
await page.locator('.stepper').waitFor({ timeout: 15000 })
const url = page.url()
const steps = await page.locator('.stepper button').count()
const phone = await (await browser.newContext({ viewport: { width: 390, height: 844 }, storageState: await ctx.storageState() })).newPage()
for (let i = 0; i < steps; i++) {
  await page.locator('.stepper button').nth(i).click()
  await page.waitForTimeout(500)
  if (i === 0) { const name = page.getByLabel('Nome', { exact: true }); if (await name.isVisible()) { await name.fill('Tauã'); await name.blur(); await page.waitForTimeout(1200) } }
  if (i === 1) { const lamina = page.getByRole('radio', { name: /Lâmina/ }); if ((await lamina.getAttribute('aria-checked')) !== 'true') { await lamina.click(); await page.waitForTimeout(1200) } }
  if (i === 2) { for (const n of ['Força', 'Força', 'Agilidade']) { await page.getByRole('button', { name: `Aumentar ${n}` }).click(); await page.waitForTimeout(150) } await page.waitForTimeout(1300) }
  await page.screenshot({ path: `${out}/look-${i + 1}-desktop.png`, fullPage: true })
  await phone.goto(url)
  await phone.locator('.stepper').waitFor()
  await phone.locator('.stepper button').nth(i).click()
  await phone.waitForTimeout(600)
  await phone.screenshot({ path: `${out}/look-${i + 1}-phone.png`, fullPage: true })
  log('step', i + 1, await page.locator('#builder-step-title').textContent())
}
log('LOOK OK')
await browser.close()
