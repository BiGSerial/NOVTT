// SPEC-PRESET-ENGINE-001 end to end on the authorial demo preset "Linhagens": a player builds a
// character step by step (identity → origin → attributes → review) and sends it; the master
// approves every operation, finalizes, activates it, and the character appears at the table.
// Run with: sh scripts/dev.sh e2e-presets (needs: sh scripts/dev.sh demo-reset). Screenshots in ./e2e-output.
import { chromium } from 'playwright'
const BASE = 'http://localhost:5173/'
const out = process.env.E2E_OUT ?? '/out'
const CAMPAIGN = 'Linhagens da Névoa (demo)'
// Seat and name are configurable so the run does not collide with characters made by hand.
const SEAT = process.env.SEAT ?? 'Tomé'
const NAME = process.env.NAME ?? 'Ayla'
const browser = await chromium.launch({ args: ['--use-gl=swiftshader', '--enable-unsafe-swiftshader'] })
const log = (...a) => console.log(new Date().toISOString().slice(11, 19), ...a)
const fail = msg => { console.error('FAIL:', msg); process.exit(1) }
const shot = (page, name, fullPage = true) => page.screenshot({ path: `${out}/presets-${name}.png`, fullPage })

async function seat(name, viewport) {
  const ctx = await browser.newContext({ viewport })
  const page = await ctx.newPage()
  page.on('pageerror', e => log(`[${name} pageerror]`, e.message.slice(0, 200)))
  page.on('console', m => { if (m.type() === 'error') log(`[${name} console]`, m.text().slice(0, 200)) })
  await page.goto(BASE)
  await page.getByRole('button', { name: new RegExp(`^${name}`) }).first().click()
  await page.waitForURL(/#\/(mesa|campanhas)/)
  await page.goto(BASE + '#/campanhas')
  await page.locator('.campaign-card', { hasText: CAMPAIGN }).waitFor({ timeout: 15000 })
  return page
}
const saved = async page => {
  await page.waitForTimeout(900)
  await page.locator('.builder .status').filter({ hasText: /Rascunho salvo/ }).waitFor({ timeout: 10000 }).catch(() => {})
}
/** Uses the − / + steppers until the field shows the wanted value. */
const rate = async (page, label, value) => {
  const row = page.locator('.stat-row').filter({ has: page.locator('.stat-name span', { hasText: new RegExp(`^${label}$`) }) })
  for (let guard = 0; guard < 12; guard++) {
    const now = Number((await row.locator('output').textContent()).trim().split(/\s/)[0])
    if (now === value) return
    await row.getByRole('button', { name: `${now < value ? 'Aumentar' : 'Diminuir'} ${label}` }).click()
    await page.waitForTimeout(120)
  }
  fail(`could not set ${label} to ${value}`)
}
const next = async page => { await saved(page); await page.getByRole('button', { name: /^Continuar/ }).click(); await page.waitForTimeout(400) }

// Player: create the character from the campaign list.
const player = await seat(SEAT, { width: 390, height: 844 })
await shot(player, '1-player-campaigns')
const card = player.locator('.campaign-card', { hasText: CAMPAIGN })
await card.getByRole('button', { name: 'Criar personagem' }).click()
await card.getByLabel('Nome do personagem').fill(NAME)
await card.getByRole('button', { name: 'Começar a ficha' }).click()
await player.waitForURL(/#\/personagens\//)
await player.locator('.stepper').waitFor({ timeout: 15000 })
const characterUrl = player.url()
log('builder open:', characterUrl.split('#')[1])

// Step 1: identity.
await player.getByLabel('Nome', { exact: true }).fill(NAME)
await player.getByLabel('Conceito').fill('Mensageira das cristas')
await saved(player)
await shot(player, '2-step-identity')
await next(player)

// Step 2: origin, with a gift that depends on the lineage.
await player.getByRole('radio', { name: /^Névoa/ }).click()
await saved(player)
const locked = await player.locator('.choice-card.locked', { hasText: 'Voo curto' }).count()
log('Voo curto locked while lineage is Névoa:', locked > 0)
if (!locked) fail('prerequisite not reflected in the builder')
await player.getByRole('radio', { name: /^Aurora/ }).click()
await saved(player)
await player.getByRole('radio', { name: /^Voo curto/ }).click()
await saved(player)
await shot(player, '3-step-origin')
await next(player)

// Step 3: priorities 7/5/3, attributes and skills.
await player.getByLabel('Atributos físicos', { exact: true }).selectOption('primary')
await player.getByLabel('Atributos sociais', { exact: true }).selectOption('secondary')
await player.getByLabel('Atributos mentais', { exact: true }).selectOption('tertiary')
await saved(player)
await saved(player)
for (const [label, value] of [['Força', 3], ['Destreza', 4], ['Vigor', 3], ['Carisma', 3], ['Manipulação', 2], ['Aparência', 3], ['Percepção', 2], ['Inteligência', 2], ['Raciocínio', 2], ['Luta', 3], ['Furtividade', 2], ['Ofício', 1]]) {
  await rate(player, label, value)
}
await saved(player)
const vitality = await player.locator('.builder-side').textContent()
log('derived panel:', vitality.replace(/\s+/g, ' ').trim().slice(0, 120))
await shot(player, '4-step-attributes')
await next(player)

// Step 4: review and send.
await player.locator('.review-box').waitFor({ timeout: 10000 })
await shot(player, '5-step-review')
await player.getByRole('button', { name: 'Enviar para o mestre' }).click()
await player.locator('.builder .status').filter({ hasText: /enviada/i }).waitFor({ timeout: 10000 })
log('player sent the proposal')

// Master: review, approve every section, finalize, activate.
const master = await seat('Mestre', { width: 1280, height: 900 })
await shot(master, '6-master-campaigns', false)
await master.locator('.campaign-card', { hasText: CAMPAIGN }).getByRole('link', { name: 'Personagens e propostas' }).click()
await master.locator('.character-review', { hasText: NAME }).waitFor({ timeout: 15000 })
const review = master.locator('.character-review', { hasText: NAME })
await review.locator('.proposal-review').waitFor({ timeout: 10000 })
await shot(master, '7-master-proposal')
const sections = await review.locator('.review-section').count()
for (let i = 0; i < sections; i++) {
  await review.locator('.review-section').nth(i).getByRole('button', { name: /^Aprovar pendentes/ }).click()
  await master.waitForTimeout(500)
}
await review.getByRole('button', { name: 'Finalizar revisão' }).click()
await review.locator('[role="status"]').filter({ hasText: /aplicada|Personagem/ }).first().waitFor({ timeout: 10000 }).catch(() => {})
await master.waitForTimeout(800)
const activate = master.locator('.character-review', { hasText: NAME }).getByRole('button', { name: 'Ativar personagem' })
await activate.click()
await master.locator('.character-review', { hasText: NAME }).locator('.status-pill.approved').waitFor({ timeout: 10000 })
log('master approved and activated', NAME)
await shot(master, '8-master-activated')

// The character is at the table for both.
await master.getByRole('link', { name: 'Abrir a mesa' }).click()
const start = master.getByRole('button', { name: 'Começar a sessão' })
await Promise.race([start.waitFor({ timeout: 15000 }), master.locator('.scene-banner').waitFor({ timeout: 15000 })]).catch(() => {})
if (await start.isVisible()) await start.click()
await master.locator('.token', { hasText: NAME }).waitFor({ timeout: 15000 })
await master.locator('.token', { hasText: NAME }).click()
await master.locator('.inspector .medallion').first().waitFor({ timeout: 10000 })
const medallions = await master.locator('.inspector .medallion').allTextContents()
log('master inspector medallions:', medallions.map(t => t.replace(/\s+/g, ' ').trim()).join(' | '))
const inspector = (await master.locator('.inspector').textContent()).replace(/\s+/g, ' ')
for (const label of ['Força', 'Carisma', 'Raciocínio', 'Luta']) if (!inspector.includes(label)) fail(`${label} missing at the table`)
await shot(master, '9-master-table', false)

await player.goto(characterUrl)
await player.locator('.builder-head').waitFor({ timeout: 10000 })
await shot(player, '10-player-sheet-approved')
await player.goto(master.url())
await player.getByRole('navigation', { name: 'Seções' }).waitFor({ timeout: 15000 })
await player.waitForTimeout(1200)
await shot(player, '11-player-table')
log('E2E presets OK')
await browser.close()
