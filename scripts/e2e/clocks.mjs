// NT-11: the master creates a clock in the dialog (tone, who sees it); only the chosen player
// sees it on the scene tab; making it secret again removes it from that player's screen.
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
const LABEL = `Portão cedendo ${Date.now() % 1000}`
const master = await seat('Mestre', { width: 1440, height: 900 })
const start = master.getByRole('button', { name: 'Começar a sessão' })
await Promise.race([start.waitFor({ timeout: 15000 }), master.locator('.scene-banner').waitFor({ timeout: 15000 })]).catch(() => {})
if (await start.isVisible()) await start.click()
await master.locator('.scene-banner').waitFor({ timeout: 15000 })

const lyra = await seat('Lyra', { width: 390, height: 844 })
const bram = await seat('Bram', { width: 390, height: 844 })
for (const p of [lyra, bram]) await p.getByRole('navigation', { name: 'Seções' }).getByRole('button', { name: /Cena/ }).click()

await master.getByRole('button', { name: '+ relógio' }).click()
const dlg = master.getByRole('dialog', { name: 'Novo relógio' })
await dlg.waitFor()
await dlg.getByLabel('Nome').fill(LABEL)
await dlg.getByRole('button', { name: 'Mais partes' }).click()
await dlg.getByRole('button', { name: 'Mais partes' }).click()
await dlg.getByRole('radio', { name: /Mistério/ }).click()
await dlg.getByRole('radio', { name: /Jogadores escolhidos/ }).click()
await dlg.getByLabel('Ana (demo)').check()
await dlg.locator('.clock-preview .dial-seg').nth(1).click()
await master.screenshot({ path: `${out}/clocks-1-dialog.png` })
await dlg.getByRole('button', { name: 'Criar relógio' }).click()
await dlg.waitFor({ state: 'detached' })
const wrap = master.locator('.clock-wrap', { hasText: LABEL })
await wrap.waitFor()
log('master:', (await wrap.textContent()).replace(/\s+/g, ' '))
await master.locator('.scene-banner').screenshot({ path: `${out}/clocks-2-banner.png` })

const lyraClock = lyra.locator('.player-clocks .clock-dial', { hasText: LABEL })
await lyraClock.waitFor({ timeout: 10000 })
log('lyra sees:', (await lyraClock.textContent()).replace(/\s+/g, ' '))
if (!(await lyraClock.getAttribute('class')).includes('tone-mystery')) fail('tone not applied')
await lyra.screenshot({ path: `${out}/clocks-3-player.png`, fullPage: true })
await bram.waitForTimeout(800)
if (await bram.locator('.clock-dial', { hasText: LABEL }).count()) fail('Bram sees a clock meant for Ana')
log('bram does not see it')

await wrap.getByRole('button', { name: /Editar relógio/ }).click()
const edit = master.getByRole('dialog', { name: 'Editar relógio' })
await edit.getByRole('radio', { name: /Só eu/ }).click()
await edit.getByRole('button', { name: 'Salvar' }).click()
await lyraClock.waitFor({ state: 'detached', timeout: 10000 })
log('made secret: gone from Lyra')
await master.locator('.clock-wrap', { hasText: LABEL }).getByRole('button', { name: /Editar relógio/ }).click()
await master.getByRole('dialog', { name: 'Editar relógio' }).getByRole('button', { name: 'Remover relógio' }).click()
await master.locator('.clock-wrap', { hasText: LABEL }).waitFor({ state: 'detached' })
await browser.close()
log('OK')
