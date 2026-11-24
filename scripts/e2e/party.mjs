// NT-13/14: the master splits the party into places; far members are greyed for the player;
// a nearby player offers an item, the receiver accepts; the master undoes it and rejoins all.
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
await master.locator('.cc-places').waitFor({ timeout: 15000 })
await master.locator('.cc-places').getByRole('button', { name: 'Separar o grupo' }).click()
const dlg = master.getByRole('dialog', { name: 'Onde cada um está' })
for (const place of ['Na ponte', 'Na margem']) { await dlg.getByLabel('Novo local').fill(place); await dlg.getByRole('button', { name: 'Adicionar' }).click() }
const pick = async (who, place) => { const sel = dlg.getByLabel(`Onde está ${who}`); await sel.selectOption({ label: place }) }
await pick('Lyra', 'Na ponte'); await pick('Bram', 'Na ponte'); await pick('Nix', 'Na margem')
await master.screenshot({ path: `${out}/party-1-places.png` })
await dlg.getByRole('button', { name: 'Salvar' }).click()
await dlg.waitFor({ state: 'detached' })
log('places:', (await master.locator('.place-groups').textContent()).replace(/\s+/g, ' ').trim())

const lyra = await seat('Lyra', { width: 390, height: 844 })
const bram = await seat('Bram', { width: 390, height: 844 })
await lyra.getByRole('navigation', { name: 'Seções' }).getByRole('button', { name: /Cena/ }).click()
const nix = lyra.locator('.party-member', { hasText: 'Nix' })
await nix.waitFor({ timeout: 10000 })
if (!(await nix.getAttribute('class')).includes('far')) fail('Nix should be far from Lyra')
log('Lyra sees Nix:', (await nix.locator('.reach').textContent()).trim(), '| Bram:', (await lyra.locator('.party-member', { hasText: 'Bram' }).locator('.reach').textContent()).trim())
await lyra.screenshot({ path: `${out}/party-2-player-scene.png`, fullPage: true })

await lyra.getByRole('navigation', { name: 'Seções' }).getByRole('button', { name: /Ficha/ }).click()
await lyra.locator('.sheet-tabs [role=tab]', { hasText: 'Itens' }).click()
await lyra.getByRole('button', { name: /Dar Flechas/ }).click()
const give = lyra.getByRole('dialog', { name: 'Dar Flechas' })
const options = await give.locator('select option').allTextContents()
log('can give to:', options.join(', '))
if (options.includes('Nix')) fail('far Nix offered as a receiver')
await give.getByLabel(/Para quem/).selectOption({ label: 'Bram' })
await give.getByRole('button', { name: 'Oferecer' }).click()
const card = bram.locator('.offer-card', { hasText: 'Flechas' })
await card.waitFor({ timeout: 10000 })
await bram.screenshot({ path: `${out}/party-3-offer.png` })
await card.getByRole('button', { name: 'Aceitar' }).click()
await card.waitFor({ state: 'detached', timeout: 10000 })
log('Bram accepted')

const trade = master.locator('.cc-places li', { hasText: 'Flechas' })
await trade.getByRole('button', { name: 'desfazer' }).waitFor({ timeout: 10000 })
await trade.getByRole('button', { name: 'desfazer' }).click()
await trade.waitFor({ state: 'detached', timeout: 10000 })
log('master undid the trade')
await master.getByRole('tab', { name: 'Histórico' }).click()
await master.locator('.history-list li', { hasText: 'desfez' }).first().waitFor({ timeout: 10000 })
log('history:', (await master.locator('.history-list li').allTextContents()).slice(0, 4).map(t => t.replace(/\s+/g, ' ').trim()).join(' || '))
await master.screenshot({ path: `${out}/party-4-master.png` })
await master.locator('.cc-places').getByRole('button', { name: 'Separar o grupo' }).click()
await master.getByRole('dialog', { name: 'Onde cada um está' }).getByRole('button', { name: 'Juntar todos' }).click()
await master.getByRole('dialog').waitFor({ state: 'detached' })
await browser.close()
log('OK')
