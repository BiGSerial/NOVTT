// SPEC-MVP-002 end-to-end: master, player and paired tablet in three browsers. Checks that
// every screen shows the same official result, that secrets stay secret and that pairing works.
import { chromium } from 'playwright'
const BASE = 'http://localhost:5173/'
// Run with: sh scripts/dev.sh e2e (needs: sh scripts/dev.sh demo). Screenshots go to ./e2e-output.
const out = process.env.E2E_OUT ?? '/out'
const browser = await chromium.launch({ args: ['--use-gl=swiftshader', '--enable-unsafe-swiftshader'] })
const log = (...a) => console.log(new Date().toISOString().slice(11, 19), ...a)
async function seat(name, viewport) {
  const ctx = await browser.newContext({ viewport })
  const page = await ctx.newPage()
  page.on('console', m => { if (m.type() === 'error') log(`[${name} console]`, m.text().slice(0, 200)) })
  page.on('pageerror', e => log(`[${name} pageerror]`, e.message.slice(0, 200)))
  await page.goto(BASE)
  await page.getByRole('button', { name: new RegExp(name) }).first().click()
  return page
}
const master = await seat('Mestre', { width: 1440, height: 900 })
await master.waitForURL(/#\/mesa\//)
const start = master.getByRole('button', { name: 'Começar a sessão' })
await Promise.race([start.waitFor({ timeout: 15000 }), master.getByText('Pedidos abertos').waitFor({ timeout: 15000 })]).catch(() => {})
if (await start.isVisible()) { await start.click(); log('session started') }
await master.getByText('Pedidos abertos').waitFor({ timeout: 15000 })
await master.screenshot({ path: `${out}/1-master.png` })
log('master link:', await master.locator('.link-badge').textContent())

const player = await seat('Lyra', { width: 390, height: 844 })
await player.waitForURL(/#\/mesa\//)
await player.getByRole('navigation', { name: 'Seções' }).waitFor({ timeout: 15000 })
await player.waitForTimeout(1500)
log('player link:', await player.locator('.link-badge').textContent())
await player.screenshot({ path: `${out}/2-player.png` })

// Master requests Agilidade + Furtividade for Lyra.
await master.locator('.cast-head', { hasText: 'Lyra' }).click()
await master.waitForTimeout(800)
const composer = master.locator('form.composer')
await composer.getByRole('button', { name: /^Agilidade/ }).click()
await composer.getByRole('button', { name: /^Furtividade/ }).click()
log('formula:', await composer.locator('.formula').textContent())
await composer.getByRole('button', { name: /Pedir a Lyra/ }).click()
await player.locator('.request-card.mine').waitFor({ timeout: 10000 })
log('player sees request:', (await player.locator('.request-card.mine').textContent()).replace(/\s+/g, ' '))
await player.screenshot({ path: `${out}/3-player-request.png` })

await player.locator('.request-card.mine').getByRole('button', { name: /Rolar/ }).click()
await player.waitForTimeout(700)
await player.screenshot({ path: `${out}/4-player-dice.png` })
await master.screenshot({ path: `${out}/4-master-dice.png` })
await player.locator('.result-card').waitFor({ timeout: 20000 })
await master.locator('.result-card').waitFor({ timeout: 20000 })
const pf = await player.locator('.result-faces li').allTextContents()
const mf = await master.locator('.result-faces li').allTextContents()
log('player faces', pf.join(','), '|', await player.locator('.result-outcome').textContent())
log('master faces', mf.join(','), '|', await master.locator('.result-outcome').textContent())
log('SAME RESULT:', JSON.stringify(pf) === JSON.stringify(mf))
if (JSON.stringify(pf) !== JSON.stringify(mf) || pf.length !== 7) { console.error('FAIL: screens disagree'); process.exit(1) }
await player.screenshot({ path: `${out}/5-player-result.png` })
await master.screenshot({ path: `${out}/5-master-result.png` })
await master.locator('.result-card').getByRole('button', { name: 'Fechar' }).click()
await player.locator('.result-card').getByRole('button', { name: 'Fechar' }).click()

// Whisper to Ana only.
await master.getByRole('tab', { name: 'Sussurros' }).click()
await master.locator('.whispers select').selectOption({ label: 'Ana (demo)' })
await master.getByLabel('Sussurro').fill('O barqueiro está mentindo.')
await master.getByRole('button', { name: 'Sussurrar' }).click()
await player.locator('.toast').first().waitFor({ timeout: 8000 })
log('player toast:', await player.locator('.toast').first().textContent())
await player.getByRole('button', { name: /Diário/ }).click()
log('player diary:', (await player.locator('.thread').textContent()).replace(/\s+/g, ' '))
await player.screenshot({ path: `${out}/6-player-diary.png` })

// Secret NPC roll: only the master sees it; then reveal it to the table.
await master.locator('.cast-head', { hasText: 'Guardião' }).click()
await master.waitForTimeout(800)
const npcComposer = master.locator('form.composer')
await npcComposer.getByRole('button', { name: /^Vigor/ }).click()
await npcComposer.getByRole('button', { name: /^Luta/ }).click()
await npcComposer.getByRole('button', { name: 'Rolar agora' }).click()
await master.locator('.result-card').waitFor({ timeout: 20000 })
log('master npc roll:', (await master.locator('.result-card').textContent()).replace(/\s+/g, ' '))
await master.locator('.result-card').getByRole('button', { name: 'Fechar' }).click()
await player.waitForTimeout(2500)
const leaked = await player.locator('.result-card').isVisible()
log('player saw secret card?', leaked)
if (leaked) { console.error('FAIL: secret NPC roll reached a player'); process.exit(1) }
await master.getByRole('tab', { name: 'Rolagens' }).click()
await master.getByRole('button', { name: 'Revelar à mesa' }).first().click()
await player.locator('.result-card').waitFor({ timeout: 20000 })
log('player sees revealed:', (await player.locator('.result-card').textContent()).replace(/\s+/g, ' '))
await player.screenshot({ path: out + '/7-player-revealed.png' })
await player.locator('.result-card').getByRole('button', { name: 'Fechar' }).click()

// Tablet pairing: code on the tablet, confirmed by the master.
const tctx = await browser.newContext({ viewport: { width: 1180, height: 820 } })
const tablet = await tctx.newPage()
await tablet.goto(BASE + '#/tablet')
await tablet.getByRole('button', { name: 'Mostrar código' }).click()
const code = (await tablet.locator('.pair-code').textContent()).replace(/\D/g, '')
log('tablet code', code)
await master.getByRole('button', { name: 'Parear tablet' }).click()
await master.getByLabel('Código do tablet').fill(code)
await master.getByRole('button', { name: 'Confirmar' }).click()
await tablet.getByText('Fichário do mestre').waitFor({ timeout: 15000 })
log('tablet paired:', (await tablet.locator('main').textContent()).replace(/\s+/g, ' ').slice(0, 120))
await tablet.getByRole('link', { name: /Ponte/ }).click()
await tablet.locator('.binder').waitFor({ timeout: 15000 })
log('tablet binder open, link:', await tablet.locator('.link-badge').textContent())
await master.locator('.dialog').getByRole('button', { name: 'Fechar' }).click()
await master.locator('.cast-head', { hasText: 'Nix' }).click()
await tablet.waitForTimeout(2000)
const followed = await tablet.locator('.binder-sheet h2').textContent()
log('tablet follows master selection:', followed)
if (!followed.includes('Nix')) { console.error('FAIL: tablet did not follow'); process.exit(1) }
log('E2E OK')
await tablet.screenshot({ path: out + '/8-tablet.png' })
await browser.close()
