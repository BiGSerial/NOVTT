// SPEC-MVP-002/003 end-to-end: master, two players and a paired tablet in separate browsers.
// Checks that rolls are private by default (master + roller), that every screen that may see a
// result shows the same faces, that whispers and secret NPC rolls stay secret, that the plot
// walks scene by scene to the players' screens, and that pairing + follow works.
// Run with: sh scripts/dev.sh e2e (needs: sh scripts/dev.sh demo). Screenshots go to ./e2e-output.
import { chromium } from 'playwright'
const BASE = 'http://localhost:5173/'
const out = process.env.E2E_OUT ?? '/out'
const browser = await chromium.launch({ args: ['--use-gl=swiftshader', '--enable-unsafe-swiftshader'] })
const log = (...a) => console.log(new Date().toISOString().slice(11, 19), ...a)
const fail = msg => { console.error('FAIL:', msg); process.exit(1) }
async function seat(name, viewport) {
  const ctx = await browser.newContext({ viewport })
  const page = await ctx.newPage()
  page.on('console', m => { if (m.type() === 'error') log(`[${name} console]`, m.text().slice(0, 200)) })
  page.on('pageerror', e => log(`[${name} pageerror]`, e.message.slice(0, 200)))
  await page.goto(BASE)
  await page.getByRole('button', { name: new RegExp(`^${name}`) }).first().click()
  await page.waitForURL(/#\/mesa\//)
  return page
}
const closeCard = async p => { if (await p.locator('.result-card').isVisible()) await p.locator('.result-card').click() }

const master = await seat('Mestre', { width: 1440, height: 900 })
const start = master.getByRole('button', { name: 'Começar a sessão' })
await Promise.race([start.waitFor({ timeout: 15000 }), master.locator('.scene-banner').waitFor({ timeout: 15000 })]).catch(() => {})
if (await start.isVisible()) { await start.click(); log('session started') }
await master.locator('.scene-banner').waitFor({ timeout: 15000 })
await master.waitForTimeout(1200)
log('scene:', await master.locator('.scene-title').textContent())
await master.screenshot({ path: `${out}/1-master.png` })

const ana = await seat('Lyra', { width: 390, height: 844 })
const bruno = await seat('Bram', { width: 390, height: 844 })
await ana.getByRole('navigation', { name: 'Seções' }).waitFor({ timeout: 15000 })
await bruno.getByRole('navigation', { name: 'Seções' }).waitFor({ timeout: 15000 })
await ana.waitForTimeout(1500)
await ana.screenshot({ path: `${out}/2-player.png`, fullPage: true })

// Private by default: the master asks Lyra for Agilidade + Furtividade.
await master.locator('.token', { hasText: 'Lyra' }).click()
await master.locator('.inspector .medallion', { hasText: 'Agilidade' }).waitFor({ timeout: 10000 })
await master.locator('.inspector .medallion', { hasText: 'Agilidade' }).click()
await master.locator('.inspector .skill', { hasText: 'Furtividade' }).click()
log('tray:', (await master.locator('.tray-formula').textContent()).replace(/\s+/g, ' '))
await master.screenshot({ path: `${out}/3-master-inspector.png` })
await master.locator('.roll-tray').getByRole('button', { name: 'Pedir rolagem' }).click()
await ana.locator('.request-card.mine').waitFor({ timeout: 10000 })
log('ana sees request:', (await ana.locator('.request-card.mine').textContent()).replace(/\s+/g, ' '))
await ana.screenshot({ path: `${out}/4-player-request.png` })
await ana.locator('.request-card.mine').getByRole('button', { name: /Rolar/ }).click()
await ana.waitForTimeout(900)
await ana.screenshot({ path: `${out}/5-player-dice.png` })
await ana.locator('.result-card').waitFor({ timeout: 20000 })
await master.locator('.result-card').waitFor({ timeout: 20000 })
const af = await ana.locator('.result-faces li').allTextContents()
const mf = await master.locator('.result-faces li').allTextContents()
log('ana faces', af.join(','), '|', (await ana.locator('.result-card').textContent()).replace(/\s+/g, ' '))
log('master faces', mf.join(','))
if (JSON.stringify(af) !== JSON.stringify(mf) || af.length !== 7) fail('screens disagree')
await ana.waitForTimeout(2500)
const diceStill = await ana.locator('.dice-overlay.covering').count()
log('dice still on the table with the card:', diceStill > 0)
if (!diceStill) fail('dice vanished before the card was dismissed')
await ana.screenshot({ path: `${out}/6-player-result.png` })
await master.screenshot({ path: `${out}/6-master-result.png` })
await bruno.waitForTimeout(1000)
if (await bruno.locator('.result-card').isVisible()) fail('a private roll reached another player')
log('bruno did not see the private roll: OK')
await closeCard(ana); await closeCard(master)

// Whisper to Ana only (inline on her sheet).
await master.getByLabel(/Sussurro para Ana/).fill('O barqueiro está mentindo.')
await master.locator('.inline-whisper').getByRole('button', { name: 'Enviar' }).click()
await ana.locator('.toast').first().waitFor({ timeout: 8000 })
await ana.getByRole('button', { name: /Diário/ }).click()
log('ana diary:', (await ana.locator('.thread').textContent()).replace(/\s+/g, ' '))
await bruno.getByRole('button', { name: /Diário/ }).click()
if ((await bruno.locator('.thread').textContent()).includes('barqueiro está mentindo')) fail('whisper leaked')
await ana.screenshot({ path: `${out}/7-player-diary.png`, fullPage: true })

// Secret NPC roll, then reveal from the chronicle.
await master.locator('.token', { hasText: 'Guardião' }).click()
await master.locator('.inspector .medallion', { hasText: 'Vigor' }).waitFor({ timeout: 10000 })
await master.locator('.inspector .medallion', { hasText: 'Vigor' }).click()
await master.locator('.inspector .skill', { hasText: 'Luta' }).click()
await master.locator('.roll-tray').getByRole('button', { name: 'Rolar agora' }).click()
await master.locator('.result-card').waitFor({ timeout: 20000 })
await closeCard(master)
await bruno.waitForTimeout(2500)
if (await bruno.locator('.result-card').isVisible()) fail('secret NPC roll reached a player')
await master.getByRole('tab', { name: 'Histórico' }).click()
await master.locator('.history').getByRole('button', { name: 'Revelar à mesa' }).first().click()
await bruno.locator('.result-card').waitFor({ timeout: 20000 })
log('bruno sees revealed:', (await bruno.locator('.result-card').textContent()).replace(/\s+/g, ' '))
await closeCard(bruno)

// Walk the plot: next scene, shown to the players (start from the first scene: reruns leave the demo at the end).
await master.locator('.scene-steps button').first().click()
await master.waitForFunction(() => !!document.querySelector('.scene-actions')?.textContent?.includes('Próxima cena'), null, { timeout: 8000 })
const before = await master.locator('.scene-title').textContent()
await master.locator('.scene-actions').getByRole('button', { name: /Próxima cena/ }).click()
await master.waitForFunction(t => document.querySelector('.scene-title')?.textContent !== t, before, { timeout: 8000 })
const now = await master.locator('.scene-title').textContent()
log('scene advanced:', before, '→', now)
await master.locator('.scene-actions').getByRole('button', { name: 'Mostrar aos jogadores' }).click()
await ana.getByRole('button', { name: /Cena/ }).click()
await ana.locator('.stage-card h2', { hasText: now.trim() }).waitFor({ timeout: 8000 })
log('ana sees the scene:', await ana.locator('.stage-card h2').textContent())
await master.waitForTimeout(800)
await master.screenshot({ path: `${out}/8-master-scene2.png` })
await ana.screenshot({ path: `${out}/9-player-scene.png`, fullPage: true })

// Group: select the party and look at the board.
await master.locator('.rail-group').first().getByRole('button', { name: 'selecionar todos' }).click()
await master.locator('.party-board').waitFor({ timeout: 5000 })
await master.waitForTimeout(700)
await master.screenshot({ path: `${out}/10-master-party.png` })
await master.keyboard.press('Escape')

// Tablet pairing: code on the tablet, confirmed by the master; the binder follows the master.
const tctx = await browser.newContext({ viewport: { width: 1180, height: 820 } })
const tablet = await tctx.newPage()
await tablet.goto(BASE + '#/tablet')
await tablet.getByRole('button', { name: 'Mostrar código' }).click()
const code = (await tablet.locator('.pair-code').textContent()).replace(/\D/g, '')
await master.getByRole('button', { name: 'Tablet' }).click()
await master.getByLabel('Código do tablet').fill(code)
await master.getByRole('button', { name: 'Confirmar' }).click()
await tablet.getByText('Fichário do mestre').waitFor({ timeout: 15000 })
await tablet.getByRole('link', { name: /Ponte/ }).click()
await tablet.locator('.tablet-binder').waitFor({ timeout: 15000 })
await master.locator('.dialog').getByRole('button', { name: 'Fechar' }).click()
await master.locator('.token', { hasText: 'Nix' }).click()
await tablet.waitForTimeout(2000)
const followed = await tablet.locator('.inspector .char-name').textContent()
log('tablet follows master selection:', followed)
if (!followed.includes('Nix')) fail('tablet did not follow')
await tablet.screenshot({ path: `${out}/11-tablet.png` })

const prep = await master.context().newPage()
await prep.setViewportSize({ width: 1440, height: 900 })
await prep.goto(master.url().replace('#/mesa/', '#/roteiro/'))
await prep.locator('.storyboard').waitFor({ timeout: 10000 })
await prep.waitForTimeout(600)
await prep.screenshot({ path: `${out}/12-roteiro.png`, fullPage: true })
log('E2E OK')
await browser.close()
