// A player's roll request reaches the master on the player's token: a blinking "✋ pedido" flag;
// opening the token shows the request on the sheet (banner + the asked fields lit and pre-picked).
// Run with: sh scripts/dev.sh e2e-request (needs the demo data). Screenshots go to ./e2e-output.
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
await master.locator('.cast-rail').waitFor({ timeout: 15000 })
// Start from another token so the request arrives while the master looks elsewhere.
await master.locator('.token').filter({ hasNotText: PLAYER }).first().click()

const player = await seat(PLAYER, { width: 390, height: 844 })
await player.getByRole('navigation', { name: 'Seções' }).waitFor({ timeout: 15000 })
await player.locator('.medallion').first().waitFor({ timeout: 15000 })
const attr = (await player.locator('.medallion .medallion-label').nth(1).textContent()).trim()
await player.locator('.medallion').nth(1).click()
await player.getByLabel('Intenção').fill('Escalar o muro sem ser vista.')
await player.getByRole('button', { name: 'Sugerir ao mestre' }).click()
log('player asked', attr)

const token = master.locator('.token', { hasText: PLAYER })
await token.locator('.flag.ask').waitFor({ timeout: 10000 })
if (!(await master.locator('.tab-ask').isVisible())) fail('no dot on the party tab')
log('flag:', (await token.locator('.flag.ask').textContent()).trim(), '| title:', await token.locator('.flag.ask').getAttribute('title'))
await master.locator('.cast-rail').screenshot({ path: `${out}/request-1-rail.png` })

await token.click()
const banner = master.locator('.ask-banner')
await banner.waitFor({ timeout: 10000 })
log('banner:', (await banner.textContent()).replace(/\s+/g, ' ').trim())
const lit = master.locator('.inspector .medallion.asked, .inspector .skill.asked')
await lit.first().waitFor({ timeout: 10000 })
if (!(await lit.first().textContent()).toUpperCase().includes(attr.toUpperCase())) fail('asked field not lit')
if (!(await master.locator('.inspector .medallion.asked.on, .inspector .skill.asked.on').count())) fail('asked field not pre-picked')
if (!(await master.locator('.roll-tray').textContent()).includes('sugestão do jogador')) fail('tray does not show the suggestion')
await master.waitForTimeout(1200)
await master.screenshot({ path: `${out}/request-2-inspector.png` })
await master.locator('.inspector').screenshot({ path: `${out}/request-3-sheet.png` })

await banner.getByRole('button', { name: 'Dispensar' }).click()
await banner.waitFor({ state: 'detached', timeout: 10000 })
await token.locator('.flag.ask').waitFor({ state: 'detached', timeout: 10000 })
log('dismissed: banner and flag gone')
await browser.close()
log('OK')
