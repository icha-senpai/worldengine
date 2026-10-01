import { expect, test } from '@playwright/test'
import { login } from './support/auth'

const guidesPath = '/datacrypt/bitcraft/guides'

for (const viewport of [
    { name: 'desktop', width: 1440, height: 1000 },
    { name: 'mobile', width: 390, height: 844 },
]) {
    test(`admin can write, preview, publish, and edit guides on ${viewport.name}`, async ({ page }, testInfo) => {
        await page.setViewportSize(viewport)
        const errors = []
        page.on('pageerror', (error) => errors.push(error.message))
        await login(page)
        await page.goto(guidesPath)
        await expect(page.getByRole('heading', { name: 'BitCraft Guides', exact: true })).toBeVisible()

        if (viewport.name === 'mobile') {
            await page.getByRole('button', { name: 'Toggle navigation' }).click()
        }
        const nav = page.getByRole('navigation', { name: viewport.name === 'mobile' ? 'Primary mobile' : 'Primary', exact: true })
        const guidesLink = nav.getByRole('link', { name: 'Guides', exact: true })
        if (!await guidesLink.isVisible()) {
            await nav.getByRole('button', { name: 'Bitcraft Tools', exact: true }).click()
        }
        await expect(guidesLink).toBeVisible()
        await guidesLink.click()

        await page.getByRole('link', { name: 'New guide', exact: true }).first().click()
        const title = `First crafting project ${viewport.name}`
        await page.getByLabel('Title', { exact: true }).fill(title)
        await page.getByLabel('Summary', { exact: true }).fill('Materials and crafting steps for a new settlement.')
        await page.getByLabel('Category', { exact: true }).fill('Crafting')
        const content = page.getByLabel('Guide content', { exact: true })
        await expect(content).toBeVisible()
        await content.fill('Gather your materials, then start crafting at the workshop.')
        await page.getByRole('tab', { name: 'Preview', exact: true }).click()
        await expect(page.getByRole('tabpanel', { name: 'Preview' })).toContainText('Gather your materials')
        await page.getByRole('tab', { name: 'Write', exact: true }).click()
        await assertNoPageOverflow(page)
        if (viewport.name === 'mobile') {
            expect((await page.locator('.editor-toolbar').boundingBox()).height).toBeLessThan(240)
        }
        await page.evaluate(() => window.scrollTo(0, 0))
        await page.screenshot({ path: testInfo.outputPath(`guide-editor-${viewport.name}.png`), fullPage: true })

        await page.getByRole('button', { name: 'Save draft', exact: true }).click()
        await expect(page).toHaveURL(/\/guides\/\d+$/)
        await expect(page.getByRole('heading', { name: title, exact: true })).toBeVisible()
        await expect(page.getByText('Draft', { exact: true })).toBeVisible()
        await expect(page.locator('.rich-document-value')).toContainText('Gather your materials')

        await page.getByRole('link', { name: 'Edit guide', exact: true }).click()
        await expect(content).toContainText('Gather your materials')
        await page.getByRole('checkbox', { name: 'Published', exact: true }).check()
        await page.getByRole('button', { name: 'Publish guide', exact: true }).click()
        await expect(page).toHaveURL(/\/guides\/\d+$/)
        await expect(page.getByText('Draft', { exact: true })).toHaveCount(0)

        await page.getByRole('link', { name: 'Edit guide', exact: true }).click()
        const editedTitle = `${title} updated`
        await page.getByLabel('Title', { exact: true }).fill(editedTitle)
        await content.fill('Updated steps: gather materials and craft your first tool.')
        await page.getByRole('button', { name: 'Save changes', exact: true }).click()
        await expect(page.getByRole('heading', { name: editedTitle, exact: true })).toBeVisible()
        await expect(page.locator('.rich-document-value')).toContainText('Updated steps')
        await assertNoPageOverflow(page)
        await page.screenshot({ path: testInfo.outputPath(`guide-reader-${viewport.name}.png`), fullPage: true })

        await page.getByRole('link', { name: 'Back to guides', exact: true }).click()
        await page.getByLabel('Search guides', { exact: true }).fill(editedTitle)
        await page.getByRole('button', { name: 'Search', exact: true }).click()
        await expect(page.locator('.index-record')).toHaveCount(1)
        await expect(page.locator('.index-record')).toContainText(editedTitle)
        await page.screenshot({ path: testInfo.outputPath(`guide-list-${viewport.name}.png`), fullPage: true })
        expect(errors).toEqual([])
    })
}

async function assertNoPageOverflow(page) {
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
}
