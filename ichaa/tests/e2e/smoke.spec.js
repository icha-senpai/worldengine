import { expect, test } from '@playwright/test'
import { login } from './support/auth'

test.describe('smoke flows', () => {
    test('user can create a smart collection and see matching members', async ({ page }) => {
        const entityName = `E2E Collection Member ${Date.now()}`
        const collectionName = `E2E Collection ${Date.now()}`

        await login(page)

        await createEntity(page, entityName)

        await page.goto('/collections/create')
        await page.getByPlaceholder('Collection name').fill(collectionName)

        const collectionSelects = page.locator('select')
        await collectionSelects.nth(0).selectOption('character_roster')
        await collectionSelects.nth(1).selectOption('smart')
        await page.locator('textarea').fill('entity_type = character')
        await page.getByRole('button', { name: 'Create Collection' }).click()

        await expect(page).toHaveURL(/\/collections\/\d+$/)
        await expect(page.getByRole('heading', { name: collectionName })).toBeVisible()
        await expect(page.getByText(entityName)).toBeVisible()
    })

    test('user can create a relationship between two entities', async ({ page }) => {
        const fromName = `E2E From ${Date.now()}`
        const toName = `E2E To ${Date.now()}`

        await login(page)

        const fromId = await createEntity(page, fromName)
        const toId = await createEntity(page, toName)

        await page.goto('/relationships/create')
        const relationshipSelects = page.locator('select')
        await relationshipSelects.nth(0).selectOption(String(fromId))
        await relationshipSelects.nth(1).selectOption(String(toId))
        await relationshipSelects.nth(2).selectOption('power')
        await page.getByRole('button', { name: 'Create Relationship' }).click()

        await expect(page).toHaveURL(/\/relationships\/\d+$/)
        await expect(page.getByRole('heading', { name: `${fromName} -> ${toName}` })).toBeVisible()
        await expect(page.locator('.chip', { hasText: 'Power' })).toBeVisible()
    })

    test('user can create a lore document and find it in search', async ({ page }) => {
        const title = `E2E Document ${Date.now()}`

        await login(page)

        await page.goto('/documents/create')
        await page.locator('input').first().fill(title)
        const documentSelects = page.locator('select')
        await documentSelects.nth(0).selectOption('research_notes')
        await documentSelects.nth(1).selectOption('authentic')
        await documentSelects.nth(2).selectOption('extant')
        await page.getByRole('button', { name: 'Create Document' }).click()

        await expect(page).toHaveURL(/\/documents\/\d+$/)
        await expect(page.getByRole('heading', { name: title })).toBeVisible()

        await page.goto('/search')
        await page.getByPlaceholder('Search the archive...').fill(title)
        await page.getByRole('button', { name: 'Search' }).click()

        const documentResult = page.locator('li').filter({
            has: page.getByRole('link', { name: title }),
        })

        await expect(documentResult).toBeVisible()
        await expect(documentResult).toContainText('Research Notes')
    })

    test('user can place an event onto a timeline from the timeline page', async ({ page }) => {
        const stamp = Date.now()
        const timelineName = `E2E Timeline ${stamp}`
        const eventName = `E2E Timeline Event ${stamp}`
        const groupName = `E2E Concurrency ${stamp}`

        await login(page)

        const eventId = await createEntity(page, eventName, 'Event')
        await createConcurrencyGroup(page, groupName)
        await createTimeline(page, timelineName)

        await page.getByLabel('Event Entity').selectOption(`${eventName} (#${eventId} · Event)`)
        await page.getByLabel('Entry Label').fill('First Fracture')
        await page.getByLabel('AU Date').fill('Year 0')
        await page.getByLabel('Concurrency Group').selectOption(`${groupName} · Year 0`)
        await page.getByRole('button', { name: 'Place Event' }).click()

        const placedEntry = page.locator('.entry-card').filter({ hasText: 'First Fracture' })

        await expect(placedEntry).toBeVisible()
        await expect(placedEntry).toContainText('Year 0')
        await expect(placedEntry).toContainText(groupName)
    })

    test('user can update profile information', async ({ page }) => {
        const nextName = `E2E User ${Date.now()}`

        await login(page)

        await page.goto('/profile')
        await page.getByLabel('Name').fill(nextName)
        await page.getByRole('button', { name: 'Save' }).first().click()

        await expect(page.getByText('Saved.')).toBeVisible()
        await expect(page.getByLabel('Name')).toHaveValue(nextName)
    })

    test('user can log in, create an entity, and find it in search', async ({ page }) => {
        const entityName = `E2E Entity ${Date.now()}`

        await login(page)

        await createEntity(page, entityName)

        await expect(page.getByRole('heading', { name: entityName })).toBeVisible()
        await page.goto('/search')
        await page.getByPlaceholder('Search the archive...').fill(entityName)
        await page.getByRole('button', { name: 'Search' }).click()

        const entityResult = page.locator('li').filter({
            has: page.getByRole('link', { name: entityName }),
        })

        await expect(entityResult).toBeVisible()
        await expect(entityResult).toContainText('Character')
        await expect(entityResult).toContainText('Concept')
    })

    test('user can move an entity to trash and restore it', async ({ page }) => {
        const entityName = `E2E Trash ${Date.now()}`

        await login(page)

        await createEntity(page, entityName)
        page.once('dialog', (dialog) => dialog.accept())
        await page.getByRole('button', { name: 'Move to Trash' }).click()

        await expect(page).toHaveURL(/\/entities$/)

        await page.goto('/trash')

        const trashRow = page.locator('.record-card').filter({
            has: page.getByRole('heading', { name: entityName }),
        })

        await expect(trashRow).toBeVisible()
        page.once('dialog', (dialog) => dialog.accept())
        await trashRow.getByRole('button', { name: 'Restore' }).click()

        await expect(trashRow).toHaveCount(0)

        await page.goto('/search')
        await page.getByPlaceholder('Search the archive...').fill(entityName)
        await page.getByRole('button', { name: 'Search' }).click()
        await expect(page.getByRole('link', { name: entityName })).toBeVisible()
    })

    test('user can create a pipeline item from the UI', async ({ page }) => {
        const title = `E2E Scene ${Date.now()}`

        await login(page)

        await page.goto('/pipeline/create')
        await page.getByLabel(/^Title$/).fill(title)
        await page.getByLabel(/^Type$/).selectOption('scene')
        await page.getByRole('button', { name: 'Create Item' }).click()

        await expect(page).toHaveURL(/\/pipeline\/\d+$/)
        await expect(page.getByRole('heading', { name: title })).toBeVisible()
        await expect(page.getByText('Concept')).toBeVisible()
    })
})

async function createEntity(page, name, typeLabel = 'Character') {
    await page.goto('/entities/create')
    await page.getByLabel(/^Name$/).fill(name)
    await page.getByLabel(/^Type$/).selectOption(entityTypeOptionValue(typeLabel))
    await page.getByLabel(/^Visibility$/).selectOption('public_knowledge')
    await page.getByRole('button', { name: 'Create Entity' }).click()

    await expect(page).toHaveURL(/\/entities\/\d+$/)

    const match = page.url().match(/\/entities\/(\d+)$/)

    return Number(match?.[1])
}

async function createTimeline(page, name) {
    await page.goto('/timelines/create')
    await page.getByLabel(/^Name$/).fill(name)
    await page.getByRole('button', { name: 'Create Timeline' }).click()

    await expect(page).toHaveURL(/\/timelines\/\d+$/)
    await expect(page.getByRole('heading', { name })).toBeVisible()
}

async function createConcurrencyGroup(page, name) {
    await page.goto('/concurrency-groups/create')
    await page.getByLabel(/^Name$/).fill(name)
    await page.getByLabel(/^AU Date$/).fill('Year 0')
    await page.getByLabel(/^Narrative Significance$/).selectOption('pivotal')
    await page.getByRole('button', { name: 'Create Group' }).click()

    await expect(page).toHaveURL(/\/concurrency-groups\/\d+$/)
    await expect(page.getByRole('heading', { name })).toBeVisible()
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
