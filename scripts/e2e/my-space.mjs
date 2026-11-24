// The person's own space on three platforms: web desktop (top nav), Android installed (bottom
// navigation, dialogs as bottom sheets, Back closes them), plus sheet versions end to end:
// the master upgrades the old Ordem Paranormal campaign and the player adapts her character.
import { chromium } from 'playwright'
const BASE = 'http://localhost:5173/'
const out = process.env.E2E_OUT ?? '/out'
const browser = await chromium.launch()
const log = (...a) => console.log(new Date().toISOString().slice(11, 19), ...a)
const fail = msg => { console.error('FAIL:', msg); process.exit(1) }
async function seat(name, options) {
  const ctx = await browser.newContext(options)
  if (options.standalone) await ctx.addInitScript(() => {
    const real = window.matchMedia.bind(window)
    window.matchMedia = q => (q.includes('display-mode: standalone') ? { matches: true, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {}, onchange: null, dispatchEvent: () => false } : real(q))
  })
  const page = await ctx.newPage()
  page.on('pageerror', e => log(`[${name} pageerror]`, e.message.slice(0, 200)))
  await page.goto(BASE)
  await page.getByRole('button', { name: new RegExp(`^${name}`) }).first().click()
  await page.waitForURL(/#\/(mesa|campanhas)/)
  return page
}

// 1. Web desktop.
const ana = await seat('Lyra', { viewport: { width: 1440, height: 900 } })
await ana.goto(BASE + '#/campanhas')
await ana.locator('.shell-nav').waitFor()
log('web platform:', await ana.evaluate(() => document.documentElement.dataset.platform), '| bottom nav:', await ana.locator('.bottom-nav').count())
await ana.locator('.shell-nav a', { hasText: 'Personagens' }).click()
await ana.locator('.mine-card').first().waitFor({ timeout: 15000 })
log('characters:', await ana.locator('.mine-card').count(), '| outdated:', await ana.locator('.mine-card.outdated').count())
await ana.screenshot({ path: `${out}/me-1-characters.png`, fullPage: true })
await ana.locator('.shell-nav a', { hasText: 'Amigos' }).click()
await ana.locator('.friend').first().waitFor({ timeout: 15000 })
log('companions:', (await ana.locator('.friend .friend-text').allTextContents()).map(t => t.replace(/\s+/g, ' ').trim()).slice(0, 3).join(' | '))
await ana.screenshot({ path: `${out}/me-2-friends.png`, fullPage: true })
await ana.locator('.shell-nav a', { hasText: 'Ajustes' }).click()
await ana.getByRole('button', { name: 'Novo modelo' }).click()
const dlg = ana.getByRole('dialog', { name: 'Novo modelo de dados' })
await dlg.getByLabel('Nome').fill(`Brasa ${Date.now() % 1000}`)
await dlg.getByRole('radio', { name: /Mármore/ }).click()
await ana.waitForTimeout(400)
await ana.screenshot({ path: `${out}/me-3-dice-editor.png` })
await dlg.getByRole('button', { name: 'Salvar modelo' }).click()
await ana.locator('.status-banner').waitFor()
log('settings:', (await ana.locator('.status-banner').textContent()).trim())
await ana.screenshot({ path: `${out}/me-4-settings.png`, fullPage: true })

// 2. Android installed, phone.
const phone = await seat('Lyra', { viewport: { width: 412, height: 915 }, userAgent: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/131 Mobile Safari/537.36', standalone: true, isMobile: true, hasTouch: true })
await phone.goto(BASE + '#/ajustes')
await phone.locator('.bottom-nav').waitFor()
log('android platform:', await phone.evaluate(() => document.documentElement.dataset.platform), '| top nav:', await phone.locator('.shell-nav').count())
await phone.screenshot({ path: `${out}/me-5-android-settings.png` })
await phone.getByRole('button', { name: 'Novo modelo' }).click()
await phone.getByRole('dialog', { name: 'Novo modelo de dados' }).waitFor()
await phone.waitForTimeout(400)
await phone.screenshot({ path: `${out}/me-6-android-sheet.png` })
await phone.goBack()
await phone.getByRole('dialog').waitFor({ state: 'detached', timeout: 5000 })
if (!phone.url().includes('#/ajustes')) fail('Back left the screen instead of closing the sheet')
log('Back closed the sheet and stayed on', phone.url().split('#')[1])
await phone.locator('.bottom-nav a', { hasText: 'Campanhas' }).click()
await phone.locator('.camp').first().waitFor({ timeout: 15000 })
await phone.screenshot({ path: `${out}/me-7-android-campaigns.png` })

// 3. Sheet versions: the master upgrades the old campaign; the player adapts.
const master = await seat('Mestre', { viewport: { width: 1440, height: 900 } })
await master.goto(BASE + '#/campanhas')
const old = master.locator('.campaign-card', { hasText: 'Ordem Paranormal 0.1.0 (antiga)' })
await old.waitFor({ timeout: 15000 })
const up = old.getByRole('button', { name: /Atualizar sistema/ })
if (await up.waitFor({ timeout: 8000 }).then(() => true, () => false)) {
  await up.click()
  await master.getByRole('dialog', { name: 'Atualizar o sistema da campanha' }).getByRole('button', { name: 'Atualizar' }).click()
  await master.getByRole('dialog').waitFor({ state: 'detached' })
  log('master upgraded the old campaign')
} else log('no upgrade offered (already upgraded on a previous run)')
await ana.goto(BASE + '#/personagens')
await ana.reload()
const outdated = ana.locator('.mine-card.outdated').first()
if (await outdated.waitFor({ timeout: 8000 }).then(() => true, () => false)) {
  await outdated.getByRole('button', { name: /Adaptar/ }).click()
  const adapt = ana.getByRole('dialog', { name: /Adaptar/ })
  await adapt.getByText(/valores continuam valendo/).waitFor({ timeout: 10000 })
  await ana.screenshot({ path: `${out}/me-8-adapt.png` })
  log('adaptation preview:', (await adapt.textContent()).replace(/\s+/g, ' ').slice(0, 160))
  await adapt.getByRole('button', { name: 'Adaptar', exact: true }).click()
  await ana.locator('.status-banner').waitFor()
  log('adapted:', (await ana.locator('.status-banner').textContent()).trim())
} else log('nothing outdated to adapt')
await browser.close()
log('OK')
