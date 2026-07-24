import { expect, test } from '@playwright/test'
import { login } from './support/auth'

test.describe('site navigation and drawer flows', () => {
    test('user can navigate the primary shell and dismiss create drawers cleanly', async ({ page }) => {
        await login(page)

        const primaryNav = page.getByRole('banner').getByRole('navigation', { name: 'Primary' })
        await page.goto('/datacrypt')
        await expect(page).toHaveURL(/\/datacrypt\/worldengine$/)
        await expect(primaryNav.getByRole('button', { name: 'World Engine', exact: true })).toBeVisible()
        await expect(primaryNav.getByRole('link', { name: 'Overview', exact: true })).toBeHidden()

        const worldEngineUrl = page.url()
        await primaryNav.getByRole('button', { name: 'World Engine', exact: true }).click()
        await expect(page).toHaveURL(worldEngineUrl)
        await expect(primaryNav.getByRole('link', { name: 'Overview', exact: true })).toBeVisible()
        await primaryNav.getByRole('button', { name: 'World Engine', exact: true }).click()
        await expect(primaryNav.getByRole('link', { name: 'Overview', exact: true })).toBeHidden()
        await primaryNav.getByRole('button', { name: 'World Engine', exact: true }).click()

        const navTargets = [
            ['Entities', 'All Entities', /\/entities$/],
            ['Connections', 'Relationships', /\/relationships$/],
            ['Temporal', 'Timelines', /\/timelines$/],
            ['Lore', 'Documents', /\/documents$/],
            ['Intelligence', 'Knowledge States', /\/knowledge-states$/],
            ['World', 'Power Interactions', /\/power-interactions$/],
            ['Organize', 'Collections', /\/collections$/],
            ['Production', 'Meta', /\/meta$/],
        ]

        for (const [label, childLabel, urlPattern] of navTargets) {
            const currentUrl = page.url()

            await openDomain(primaryNav, label, childLabel)
            await expect(page).toHaveURL(currentUrl)

            await primaryNav.getByRole('link', { name: childLabel, exact: true }).click()
            await expect(page).toHaveURL(urlPattern)
            await expect(primaryNav.getByRole('link', { name: childLabel, exact: true })).toBeVisible()
        }

        const productionUrl = page.url()
        await expect(page).toHaveURL(productionUrl)
        await expect(primaryNav.getByRole('link', { name: 'All Entities', exact: true })).toBeVisible()
        await expect(primaryNav.getByRole('link', { name: 'Relationships', exact: true })).toBeVisible()

        await primaryNav.getByRole('button', { name: 'Entities', exact: true }).click()
        await expect(page).toHaveURL(productionUrl)
        await expect(primaryNav.getByRole('link', { name: 'All Entities', exact: true })).toBeHidden()

        const beforeBitcraftUrl = page.url()
        await primaryNav.getByRole('button', { name: 'Bitcraft Tools', exact: true }).click()
        await expect(page).toHaveURL(beforeBitcraftUrl)
        await primaryNav.getByRole('link', { name: 'Market Finder', exact: true }).click()
        await expect(page).toHaveURL(/\/datacrypt\/bitcraft\/market$/)
        await primaryNav.getByRole('link', { name: 'Barter Stalls', exact: true }).click()
        await expect(page).toHaveURL(/\/datacrypt\/bitcraft\/barter-stalls$/)
        await primaryNav.getByRole('link', { name: 'Crafting Calculator', exact: true }).click()
        await expect(page).toHaveURL(/\/datacrypt\/bitcraft\/crafting$/)

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

        await page.getByLabel(/^Public-Facing Title$/).fill(publicTitle)
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

async function openDomain(primaryNav, label, childLabel) {
    const child = primaryNav.getByRole('link', { name: childLabel, exact: true })

    if (!await child.isVisible()) {
        await primaryNav.getByRole('button', { name: label, exact: true }).click()
    }
}

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
