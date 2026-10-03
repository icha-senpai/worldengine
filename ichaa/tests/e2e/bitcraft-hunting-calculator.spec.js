import { expect, test } from '@playwright/test'
import { login } from './support/auth'

for (const viewport of [
    { name: 'desktop', width: 1440, height: 1000 },
    { name: 'mobile', width: 390, height: 844 },
]) {
    test(`hunting calculator compares processing-only XP on ${viewport.name}`, async ({ page }, testInfo) => {
        await page.setViewportSize(viewport)
        const errors = []
        page.on('pageerror', error => errors.push(error.message))
        await login(page)
        await page.goto('/datacrypt/bitcraft/guides')
        const shell = await page.locator('script[data-page="app"]').evaluate(element => JSON.parse(element.textContent))
        const path = '/datacrypt/bitcraft/hunting-calculator'
        await page.route('**/bitcraft/hunting-calculator*', route => route.fulfill({
            headers: { 'X-Inertia': 'true' },
            json: {
                ...shell, component: 'Bitcraft/HuntingCalculator', url: path,
                props: { ...shell.props, levels: [
                    { level: 37, xp: 227130 }, { level: 38, xp: 253930 },
                    { level: 39, xp: 283840 }, { level: 40, xp: 317220 },
                ], error: null },
            },
        }))
        if (viewport.name === 'mobile') await page.getByRole('button', { name: 'Toggle navigation' }).click()
        const nav = page.getByRole('navigation', { name: viewport.name === 'mobile' ? 'Primary mobile' : 'Primary', exact: true })
        const link = nav.getByRole('link', { name: 'Hunting XP Calculator', exact: true })
        if (!await link.isVisible()) await nav.getByRole('button', { name: 'Bitcraft Tools', exact: true }).click()
        await link.click()
        await expect(page.getByRole('heading', { name: 'Hunting XP Calculator', exact: true })).toBeVisible()
        await expect(page.locator('[data-test=remaining-xp]')).toHaveText('85,010')
        await expect(page.locator('[data-test=animal-count]')).toHaveText('146')
        await page.getByRole('combobox', { name: 'Your work', exact: true }).selectOption('processing')
        await expect(page.locator('[data-test=animal-count]')).toHaveText('430')
        await page.screenshot({ path: testInfo.outputPath(`hunting-calculator-${viewport.name}.png`), fullPage: true })
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true)
        await page.getByLabel('XP bonus (%)', { exact: true }).fill('20')
        await expect(page.locator('[data-test=animal-count]')).toHaveText('358')
        await page.getByRole('combobox', { name: 'XP values', exact: true }).selectOption('observed')
        await expect(page.getByLabel('XP bonus (%)', { exact: true })).toBeDisabled()
        await expect(page.locator('[data-test=animal-count]')).toHaveText('430')
        await page.getByLabel('XP earned toward next level', { exact: true }).fill('26800')
        await expect(page.getByRole('alert')).toContainText('below the next-level requirement')
        expect(errors).toEqual([])
    })
}

