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
const fog = v => panel.getByRole('slider', { name: /Neblina/ }).fill(String(v))
const looks = [
  ['noite-chuva-neblina', 'Noite', 'Chuva', 0.6],
  ['meio-dia-nublado', 'Meio-dia', 'Nublado', 0],
  ['madrugada-tempestade-nevoa', 'Madrugada', 'Tempestade', 0.4],
  ['amanhecer-neve', 'Amanhecer', 'Neve', 0],
  ['entardecer-limpo', 'Entardecer', 'Limpo', 0],
]
for (const [name, time, weather, fogLevel] of looks) {
  await panel.getByRole('button', { name: time, exact: true }).click()
  await panel.getByRole('radio', { name: new RegExp(weather) }).click()
  if (weather !== 'Limpo') await panel.getByRole('slider', { name: /Intensidade/ }).fill('0.9')
  await fog(fogLevel)
  await master.waitForTimeout(1800)
  const label = await master.locator('.weather-btn').textContent()
  log(name, '→', label.trim())
  if (!label.includes(weather)) fail(`master control did not switch to ${weather}`)
  if (fogLevel > 0 && !label.includes('névoa')) fail('fog layer missing next to the weather')
  await ana.waitForTimeout(600)
  if (fogLevel > 0 && !(await ana.locator('canvas.fog-veil').isVisible())) fail('fog did not reach the player')
  await master.screenshot({ path: `${out}/atmo-${name}-master.png` })
  await ana.screenshot({ path: `${out}/atmo-${name}-player.png` })
  if (weather === 'Tempestade') {
    // Wait for a strike: sample the sky canvas brightness in the page and capture the flash.
    const brightness = () => master.evaluate(() => {
      const c = document.querySelector('.scene-banner canvas.weather-canvas')
      const g = c?.getContext('2d')
      if (!g) return 0
      const d = g.getImageData(0, 0, c.width, Math.round(c.height / 2)).data
      let sum = 0
      for (let i = 0; i < d.length; i += 64) sum += (d[i] + d[i + 1] + d[i + 2]) * d[i + 3] / 255
      return sum / (d.length / 64) / 3
    })
    let seen = 0
    let max = 0
    let min = 999
    for (let i = 0; i < 400 && seen < 2; i++) {
      const b = await brightness()
      max = Math.max(max, b); min = Math.min(min, b)
      if (b > min + 25) {
        seen++
        await master.screenshot({ path: `${out}/atmo-${name}-strike-${seen}.png` })
        await master.waitForTimeout(500)
      } else await master.waitForTimeout(40)
    }
    log('lightning frames captured:', seen, 'brightness min/max', min.toFixed(1), max.toFixed(1))
    if (!seen) fail('no lightning strike within the wait')
  }
}
log('player toasts:', (await ana.locator('.toast').allTextContents()).join(' | '))
log('ATMOSPHERE E2E OK')
await browser.close()
