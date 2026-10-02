import { expect, test } from '@playwright/test'
import { login } from './support/auth'

const path = '/datacrypt/bitcraft/open-crafts'

for (const viewport of [
    { name: 'desktop', width: 1440, height: 1000 },
    { name: 'mobile', width: 390, height: 844 },
]) {
    test(`open crafts and player picker render and respond on ${viewport.name}`, async ({ page }, testInfo) => {
        await page.setViewportSize(viewport)
        const errors = []
        page.on('pageerror', (error) => errors.push(error.message))
        await login(page)
        await page.goto('/datacrypt/bitcraft/guides')
        const shell = await page.locator('script[data-page="app"]').evaluate((element) => JSON.parse(element.textContent))
        let selected = null
        await page.route('**/bitcraft/players/search*', (route) => route.fulfill({ json: { players: [{ entityId: '123', username: 'Juice' }] } }))
        await page.route('**/bitcraft/player', async (route) => {
            const input = route.request().postDataJSON()
            selected = input.entityId ? { entityId: '123', username: 'Juice' } : null
            await respond(route)
        })
        const respond = (route) => route.fulfill({
            headers: { 'X-Inertia': 'true' },
            json: {
                ...shell, component: 'Bitcraft/OpenCrafts', url: path,
                props: {
                    ...shell.props, bitcraft: { player: selected },
                    filters: { q: '', skill: '', region: '', sort: 'xp', levelUps: false, meetsLevel: false, mine: false, page: 1 },
                    crafts: [{ id: '100', name: 'Rough Plank', outputs: [{ iconAssetName: 'Items/CrushedCopperOre' }], claim: 'Juice Town', owner: 'Juice', region: 8, count: 10, skill: 'Carpentry', progressPercent: 40, remainingXp: 120, fullXp: 200, currentXp: selected ? 90 : null, currentLevel: selected ? 1 : null, afterLevel: selected ? 3 : null, fullLevel: selected ? 4 : null, levelsGained: selected ? 2 : null, requirements: [{ skill: 'Carpentry', level: 2 }], meetsLevel: selected ? false : null, toolRequirements: [[1, 1, 1]], building: 'Carpentry Station', x: 1200, z: 1400 }],
                    pagination: { page: 1, lastPage: 1, total: 1, all: 1 }, skillOptions: [{ id: 2, name: 'Carpentry' }], regionOptions: [8],
                    refresh: { provider: 'bitjuice', updatedAt: '2026-10-02T12:00:00Z' }, error: null, playerError: null, playerRefresh: {},
                },
            },
        })
        await page.route('**/bitcraft/open-crafts*', respond)
        if (viewport.name === 'mobile') await page.getByRole('button', { name: 'Toggle navigation' }).click()
        const nav = page.getByRole('navigation', { name: viewport.name === 'mobile' ? 'Primary mobile' : 'Primary', exact: true })
        const link = nav.getByRole('link', { name: 'Open Crafts', exact: true })
        if (!await link.isVisible()) await nav.getByRole('button', { name: 'Bitcraft Tools', exact: true }).click()
        await link.click()
        await expect(page.getByRole('heading', { name: 'Open Crafts', exact: true })).toBeVisible()
        await page.getByRole('button', { name: 'Select player', exact: true }).click()
        await page.getByLabel('Player name', { exact: true }).fill('Juice')
        await page.locator('dialog[open]').getByRole('button', { name: 'Search', exact: true }).click()
        await expect(page.locator('.player-result')).toContainText('Juice')
        await page.screenshot({ path: testInfo.outputPath(`player-picker-${viewport.name}.png`) })
        await page.locator('.player-result').click()
        await expect(page.locator('.site-player-bar')).toContainText('Juice')
        await expect(page.locator('dialog[open]')).toHaveCount(0)
        await expect(page.locator('.craft-table')).toContainText('+2 levels')
        await expect(page.locator('.tracker-refresh-status')).toContainText('Updated')
        await expect(page.locator('body')).not.toContainText(/bitjuice|bitjita/i)
        const icon = page.locator('.craft-item img').first()
        await expect.poll(() => icon.evaluate((image) => image.complete && image.naturalWidth > 0)).toBe(true)
        await page.getByRole('button', { name: 'Details for Rough Plank' }).click()
        await expect(page.locator('.craft-details')).toContainText('Level 1 to 4 (+3)')
        await page.screenshot({ path: testInfo.outputPath(`open-crafts-${viewport.name}.png`) })
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true)
        await page.getByRole('button', { name: 'Change player', exact: true }).click()
        await page.getByRole('button', { name: 'Clear player', exact: true }).click()
        await expect(page.locator('.site-player-bar')).toContainText('No player selected')
        expect(errors).toEqual([])
    })
}
