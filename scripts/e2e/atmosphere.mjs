// Atmosphere (SPEC-MVP-003 NT-10): the master sets the hour and the weather on the live table;
// the player's screen follows. Screenshots of each look go to ./e2e-output/atmo-*.png.
// Run with: sh scripts/dev.sh e2e-atmosphere (needs: sh scripts/dev.sh demo).
import { chromium } from 'playwright'
const BASE = 'http://localhost:5173/'
const out = process.env.E2E_OUT ?? '/out'
const browser = await chromium.launch({ args: ['--use-gl=swiftshader', '--enable-unsafe-swiftshader'] })
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
await master.locator('.scene-banner').waitFor({ timeout: 15000 })
const ana = await seat('Lyra', { width: 390, height: 844 })
await ana.getByRole('navigation', { name: 'Seções' }).waitFor({ timeout: 15000 })

await master.locator('.weather-btn').click()
const panel = master.locator('.weather-panel')
await panel.getByRole('combobox').nth(1).selectOption('0') // instant transitions for screenshots
const looks = [
  ['noite-neblina', 'Noite', 'Neblina'],
  ['meio-dia-chuva', 'Meio-dia', 'Chuva'],
  ['entardecer-limpo', 'Entardecer', 'Limpo'],
  ['madrugada-tempestade', 'Madrugada', 'Tempestade'],
  ['amanhecer-neve', 'Amanhecer', 'Neve'],
]
for (const [name, time, weather] of looks) {
  await panel.getByRole('button', { name: time, exact: true }).click()
  await panel.getByRole('radio', { name: new RegExp(weather) }).click()
  await master.waitForTimeout(1800)
  const label = await master.locator('.weather-btn').textContent()
  log(name, '→', label.trim())
  if (!label.includes(weather)) fail(`master control did not switch to ${weather}`)
  await ana.waitForTimeout(600)
  if (weather === 'Neblina' && !(await ana.locator('.fog-veil').count())) fail('fog did not reach the player')
  await master.screenshot({ path: `${out}/atmo-${name}-master.png` })
  await ana.screenshot({ path: `${out}/atmo-${name}-player.png` })
}
log('player toasts:', (await ana.locator('.toast').allTextContents()).join(' | '))
log('ATMOSPHERE E2E OK')
await browser.close()
