import { expect, test } from '@playwright/test'
import { login } from './support/auth'

test.describe('ui polish surfaces', () => {
    test('shared surfaces render cleanly across dashboard, list, show, and drawer flows', async ({ page }, testInfo) => {
        const entityName = `UI Polish ${Date.now()}`

        await login(page)

        await page.goto('/')
        await expect(page.getByRole('heading', { name: 'Overview' })).toBeVisible()
        await attachScreenshot(page, testInfo, 'dashboard-overview')

        await page.goto('/entities')
        await expect(page.getByRole('heading', { name: 'Entities' })).toBeVisible()
        await attachScreenshot(page, testInfo, 'entities-index')

        await createEntity(page, entityName)
        await expect(page.getByRole('heading', { name: entityName })).toBeVisible()
        await attachScreenshot(page, testInfo, 'entity-show')

        await page.getByRole('link', { name: 'Edit' }).click()
        await expect(page.getByRole('dialog', { name: 'Edit Entity' })).toBeVisible()
        await attachScreenshot(page, testInfo, 'entity-edit-drawer')
        await page.getByRole('button', { name: 'Close' }).click()
        await expect(page.getByRole('heading', { name: entityName })).toBeVisible()

        await page.goto('/faction-memberships/create')
        await expect(page.getByRole('dialog', { name: 'New Faction Membership' })).toBeVisible()
        await attachScreenshot(page, testInfo, 'faction-membership-create-drawer')
    })

    test('mobile navigation remains usable and on-theme', async ({ page }, testInfo) => {
        await page.setViewportSize({ width: 390, height: 844 })
        await login(page)

        await page.goto('/entities')
        await page.getByRole('button', { name: 'Toggle navigation' }).click()
        await expect(page.getByText('Workspace')).toBeVisible()
        await attachScreenshot(page, testInfo, 'mobile-navigation-open')
    })
})

async function createEntity(page, name) {
    await page.goto('/entities/create')
    await page.getByLabel(/^Name$/).fill(name)
    await page.getByLabel(/^Type$/).selectOption('character')
    await page.getByLabel(/^Visibility$/).selectOption('public_knowledge')
    await page.getByRole('button', { name: 'Create Entity' }).click()
    await expect(page).toHaveURL(/\/entities\/\d+$/)
}

async function attachScreenshot(page, testInfo, name) {
    await testInfo.attach(name, {
        body: await page.screenshot({ fullPage: true }),
        contentType: 'image/png',
    })
}
