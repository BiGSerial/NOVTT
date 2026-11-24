// NT-15: the master uploads a map, pins two characters, drags one and shows it; the player sees
// it with the pins; then the master hides it again.
import { chromium } from 'playwright'
const BASE = 'http://localhost:5173/'
const out = process.env.E2E_OUT ?? '/out'
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
const back = master.locator('.back-to-center')
if (await back.isVisible().catch(() => false)) await back.click()
await master.locator('.cc-places').getByRole('button', { name: /Mapa da cena/ }).click()
const dlg = master.getByRole('dialog', { name: 'Mapa da cena' })
await dlg.waitFor()
const png = await master.evaluate(() => {
  const c = document.createElement('canvas'); c.width = 960; c.height = 540
  const g = c.getContext('2d'); g.fillStyle = '#3b5d3a'; g.fillRect(0, 0, 960, 540)
  g.fillStyle = '#6b8fb3'; g.fillRect(0, 300, 960, 90); g.fillStyle = '#8a6a3a'; g.fillRect(420, 280, 120, 130)
  return c.toDataURL('image/png').split(',')[1]
})
await dlg.locator('input[type=file]').setInputFiles({ name: 'mapa.png', mimeType: 'image/png', buffer: Buffer.from(png, 'base64') })
await dlg.locator('.scene-map img').waitFor({ timeout: 15000 })
for (const who of ['Lyra', 'Bram']) {
  const chip = dlg.locator('.map-roster button', { hasText: who })
  if ((await chip.getAttribute('aria-pressed')) !== 'true') { await chip.click(); await master.waitForTimeout(600) }
}
const pin = dlg.locator('.map-pin', { hasText: 'Lyra' })
await pin.waitFor()
const mapBox = await dlg.locator('.scene-map').boundingBox()
const pinBox = await pin.boundingBox()
await master.mouse.move(pinBox.x + pinBox.width / 2, pinBox.y + 15)
await master.mouse.down()
await master.mouse.move(mapBox.x + mapBox.width * 0.5, mapBox.y + mapBox.height * 0.62, { steps: 8 })
await master.mouse.up()
await master.waitForTimeout(800)
const shown = dlg.getByLabel('Mostrar aos jogadores')
if (!(await shown.isChecked())) await shown.check()
await master.waitForTimeout(800)
await master.screenshot({ path: `${out}/map-1-master.png` })
const left = await pin.evaluate(e => e.style.left)
log('Lyra pin at', left, await pin.evaluate(e => e.style.top))

const lyra = await seat('Lyra', { width: 390, height: 844 })
await lyra.getByRole('navigation', { name: 'Seções' }).getByRole('button', { name: /Cena/ }).click()
const pmap = lyra.locator('.player-map')
await pmap.waitFor({ timeout: 10000 })
await pmap.locator('.scene-map img').waitFor({ timeout: 10000 })
const pins = await pmap.locator('.map-pin-label').allTextContents()
log('player sees pins:', pins.join(', '))
if (!pins.includes('Lyra') || !pins.includes('Bram')) fail('pins missing for the player')
await pmap.scrollIntoViewIfNeeded()
await lyra.screenshot({ path: `${out}/map-2-player.png` })

await shown.uncheck()
await pmap.waitFor({ state: 'detached', timeout: 10000 })
log('hidden again: gone from the player')
await browser.close()
log('OK')
