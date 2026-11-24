// The player's sheet as a paper sheet; the portrait slot uploads a picture that the master's
// cast shows too. Screenshots: ./e2e-output/sheet-*.png
import { chromium } from 'playwright'
const BASE = 'http://localhost:5173/'
const out = process.env.E2E_OUT ?? '/out'
const PLAYER = process.env.SEAT ?? 'Lyra'
const browser = await chromium.launch()
const log = (...a) => console.log(new Date().toISOString().slice(11, 19), ...a)
const fail = msg => { console.error('FAIL:', msg); process.exit(1) }
async function seat(name, viewport) {
  const page = await (await browser.newContext({ viewport })).newPage()
  page.on('pageerror', e => log(`[${name} pageerror]`, e.message.slice(0, 200)))
  await page.goto(BASE)
  await page.getByRole('button', { name: new RegExp(`^${name}`) }).first().click()
  await page.waitForURL(/#\/mesa\//)
  return page
}
const master = await seat('Mestre', { width: 1440, height: 900 })
const start = master.getByRole('button', { name: 'Começar a sessão' })
await Promise.race([start.waitFor({ timeout: 15000 }), master.locator('.scene-banner').waitFor({ timeout: 15000 })]).catch(() => {})
if (await start.isVisible()) await start.click()
const player = await seat(PLAYER, { width: 390, height: 844 })
await player.locator('.paper-sheet').waitFor({ timeout: 15000 })
await player.locator('.paper-stats .medallion').first().waitFor()
await player.screenshot({ path: `${out}/sheet-1-phone.png`, fullPage: true })

// A generated picture (no external files): draw on a canvas in the page, upload it.
const png = await player.evaluate(() => {
  const c = document.createElement('canvas'); c.width = c.height = 256
  const g = c.getContext('2d'); const grad = g.createLinearGradient(0, 0, 256, 256)
  grad.addColorStop(0, '#2b5876'); grad.addColorStop(1, '#4e4376'); g.fillStyle = grad; g.fillRect(0, 0, 256, 256)
  g.fillStyle = '#f5d76e'; g.beginPath(); g.arc(128, 110, 60, 0, Math.PI * 2); g.fill()
  return c.toDataURL('image/png').split(',')[1]
})
await player.locator('.paper-portrait input[type=file]').setInputFiles({ name: 'retrato.png', mimeType: 'image/png', buffer: Buffer.from(png, 'base64') })
await player.locator('.paper-portrait img').waitFor({ timeout: 15000 })
log('portrait uploaded from the sheet')
await master.locator('.token', { hasText: PLAYER }).locator('img').waitFor({ timeout: 10000 })
log('master cast shows it')
await player.screenshot({ path: `${out}/sheet-2-portrait.png` })
await player.locator('.paper-stats .medallion').nth(1).click()
await player.locator('.paper-stats .skill').nth(1).click()
await player.screenshot({ path: `${out}/sheet-3-picked.png` })
const wide = await (await browser.newContext({ viewport: { width: 1024, height: 900 }, storageState: await player.context().storageState() })).newPage()
await wide.goto(player.url()); await wide.locator('.paper-sheet').waitFor({ timeout: 15000 }); await wide.waitForTimeout(800)
await wide.screenshot({ path: `${out}/sheet-4-tablet.png`, fullPage: true })
// Leave the demo as it was.
await player.locator('.paper-portrait').getByRole('button', { name: 'remover' }).click()
await player.locator('.paper-portrait img').waitFor({ state: 'detached', timeout: 10000 })
await browser.close()
log('OK')
