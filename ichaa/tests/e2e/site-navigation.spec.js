import { expect, test } from '@playwright/test'
import { login } from './support/auth'

test.describe('site navigation and drawer flows', () => {
    test('user can navigate the primary shell and dismiss create drawers cleanly', async ({ page }) => {
        await login(page)

        const navTargets = [
            ['Entities', /\/entities$/],
            ['Connections', /\/relationships$/],
            ['Temporal', /\/timelines$/],
            ['Lore', /\/documents$/],
            ['Intelligence', /\/knowledge-states$/],
            ['World', /\/power-interactions$/],
            ['Organize', /\/collections$/],
            ['Production', /\/meta$/],
        ]

        for (const [label, urlPattern] of navTargets) {
            await page.getByRole('link', { name: label }).click()
            await expect(page).toHaveURL(urlPattern)
        }

        await page.getByRole('link', { name: /Search/ }).click()
        await expect(page).toHaveURL(/\/search$/)

        await page.getByRole('link', { name: /^Trash$/ }).click()
        await expect(page).toHaveURL(/\/trash$/)

        await page.goto('/entities')
        await page.getByRole('link', { name: /\+ New Entity|New Entity|Create the first one/i }).first().click()
        await expect(page).toHaveURL(/\/entities\/create$/)
        await expect(page.getByRole('dialog', { name: 'New Entity' })).toBeVisible()
        await page.getByRole('button', { name: 'Close' }).click()
        await expect(page).toHaveURL(/\/entities$/)

        await page.goto('/pipeline')
        await page.getByRole('link', { name: /New Item|Create your first item/i }).first().click()
        await expect(page).toHaveURL(/\/pipeline\/create$/)
        await expect(page.getByRole('dialog', { name: 'New Pipeline Item' })).toBeVisible()
        await page.getByRole('button', { name: 'Close' }).click()
        await expect(page).toHaveURL(/\/pipeline$/)
    })

    test('user can edit an entity from the drawer and return to the show page cleanly', async ({ page }) => {
        const entityName = `E2E Edit ${Date.now()}`
        const publicTitle = `Edited Title ${Date.now()}`

        await login(page)

        await createEntity(page, entityName)

        await page.getByRole('link', { name: 'Edit' }).click()
        await expect(page).toHaveURL(/\/entities\/\d+\/edit$/)
        await expect(page.getByRole('dialog', { name: 'Edit Entity' })).toBeVisible()

        await page.getByLabel(/^Public Title$/).fill(publicTitle)
        await page.getByRole('button', { name: 'Save Changes' }).click()

        await expect(page).toHaveURL(/\/entities\/\d+$/)
        await expect(page.getByText(`"${publicTitle}"`)).toBeVisible()

        await page.getByRole('link', { name: 'Edit' }).click()
        await expect(page).toHaveURL(/\/entities\/\d+\/edit$/)
        await page.getByRole('button', { name: 'Close' }).click()
        await expect(page).toHaveURL(/\/entities\/\d+$/)
        await expect(page.getByRole('heading', { name: entityName })).toBeVisible()
    })
})

async function createEntity(page, name, typeLabel = 'Character') {
    await page.goto('/entities/create')
    await page.getByLabel(/^Name$/).fill(name)
    await page.getByLabel(/^Type$/).selectOption(entityTypeOptionValue(typeLabel))
    await page.getByLabel(/^Visibility$/).selectOption('public_knowledge')
    await page.getByRole('button', { name: 'Create Entity' }).click()

    await expect(page).toHaveURL(/\/entities\/\d+$/)
}

function entityTypeOptionValue(typeLabel) {
    const normalized = typeLabel.trim().toLowerCase()

    const map = {
        character: 'character',
        event: 'event',
        concept: 'concept',
        location: 'location',
        faction: 'faction',
    }

    return map[normalized] ?? normalized.replace(/\s+/g, '_')
}
