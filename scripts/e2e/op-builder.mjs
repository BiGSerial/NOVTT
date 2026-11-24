// Ordem Paranormal (internal test campaign): a player creates an agent step by step; screenshots
// of each step to ./e2e-output/op-*.png. Reuses the player's draft on reruns.
import { chromium } from 'playwright'
const BASE = 'http://localhost:5173/'
const out = process.env.E2E_OUT ?? '/out'
const SEAT = process.env.SEAT ?? 'Lyra'
const CAMPAIGN = process.env.CAMPAIGN ?? 'Ordem Paranormal (teste interno)'
const browser = await chromium.launch()
const log = (...a) => console.log(new Date().toISOString().slice(11, 19), ...a)
const fail = msg => { console.error('FAIL:', msg); process.exit(1) }
const page = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage()
page.on('pageerror', e => log('[pageerror]', e.message.slice(0, 200)))
await page.goto(BASE)
await page.getByRole('button', { name: new RegExp(`^${SEAT}`) }).first().click()
await page.waitForURL(/#\/(mesa|campanhas)/)
await page.goto(BASE + '#/campanhas')
const card = page.locator('.campaign-card', { hasText: CAMPAIGN })
await card.waitFor({ timeout: 15000 })
await card.locator('.my-characters').waitFor({ timeout: 15000 })
const create = card.getByRole('button', { name: 'Criar personagem' })
if (await create.isVisible()) {
  await create.click()
  await card.getByLabel('Nome do personagem').fill('Agente Rook')
  await card.getByRole('button', { name: 'Começar a ficha' }).click()
} else {
  await card.locator('a[href*="#/personagens/"]').first().click()
}
await page.waitForURL(/#\/personagens\//)
await page.locator('.stepper').waitFor({ timeout: 15000 })
const shot = async n => { await page.waitForTimeout(900); await page.screenshot({ path: `${out}/op-${n}.png`, fullPage: true }) }
const go = async label => { await page.locator('.stepper button', { hasText: label }).click(); await page.waitForTimeout(500) }

await go('Identidade')
const name = page.getByLabel('Nome do agente', { exact: true })
await name.fill('Rook'); await name.blur()
await shot('1-identidade')

await go('Atributos')
for (const [attr, n] of [['Vigor (VIG)', -1], ['Intelecto (INT)', 2], ['Presença (PRE)', 2], ['Agilidade (AGI)', 1]]) {
  const label = n < 0 ? `Diminuir ${attr}` : `Aumentar ${attr}`
  for (let i = 0; i < Math.abs(n); i++) {
    const btn = page.getByRole('button', { name: label })
    if (!(await btn.isEnabled())) break // a rerun reuses the draft
    await btn.click(); await page.waitForTimeout(700)
  }
}
await shot('2-atributos')
const left = (await page.locator('.step-points').textContent().catch(() => '')).replace(/\s+/g, ' ')
log('atributos:', left)

await go('Origem')
const pick = async re => { const r = page.getByRole('radio', { name: re }); if ((await r.getAttribute('aria-checked')) !== 'true') await r.click() }
await pick(/^Acadêmico/)
await shot('3-origem')

await go('Classe')
await pick(/^Ocultista/)
await shot('4-classe')

await go('Perícias')
await page.waitForTimeout(800)
const locked = await page.locator('.stat-row', { hasText: 'Ciências' }).locator('.stat-locked').textContent().catch(() => '')
log('Ciências:', locked)
if (!/Mínimo 1/.test(locked)) fail('origin skill not shown as a held minimum')
for (const skill of ['Percepção (PRE)', 'Intuição (PRE)', 'Atualidades (INT)', 'Medicina (INT)']) {
  const raise = page.getByRole('button', { name: `Aumentar ${skill}` })
  if (await raise.isEnabled()) { await raise.click(); await page.waitForTimeout(700) }
}
await shot('5-pericias')
await go('Revisão')
await shot('6-revisao')
log('steps:', (await page.locator('.stepper li').evaluateAll(l => l.map(x => `${x.textContent.trim()}${x.classList.contains('done') ? '✓' : ''}`))).join(' | '))
await browser.close()
log('OK')
