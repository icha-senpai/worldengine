import { expect, test } from '@playwright/test'
import { login } from './support/auth'
import { confirmAppDialog } from './support/dialogs'

test.describe('smoke flows', () => {
    test('user can create a smart collection and see matching members', async ({ page }) => {
        const entityName = `E2E Collection Member ${Date.now()}`
        const collectionName = `E2E Collection ${Date.now()}`

        await login(page)

        await createEntity(page, entityName)

        await page.goto('/collections/create')
        const drawer = page.getByRole('dialog', { name: 'New Collection' })
        await drawer.getByPlaceholder('Collection name').fill(collectionName)
        await drawer.getByLabel(/^Collection Type$/).selectOption('character_roster')
        await drawer.getByLabel(/^Collection Mode$/).selectOption('smart')
        await drawer.locator('textarea').fill('entity_type = character')
        await drawer.getByRole('button', { name: 'Create Collection' }).click()

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
        const drawer = page.getByRole('dialog', { name: 'New Relationship' })
        await drawer.getByLabel(/^From Entity$/).selectOption(String(fromId))
        await drawer.getByLabel(/^To Entity$/).selectOption(String(toId))
        await drawer.getByLabel(/^Relationship Type$/).selectOption('power')
        await drawer.getByRole('button', { name: 'Create Relationship' }).click()

        await expect(page).toHaveURL(/\/relationships\/\d+$/)
        await expect(page.getByRole('heading', { name: `${fromName} -> ${toName}` })).toBeVisible()
        await expect(page.locator('.chip', { hasText: 'Power' })).toBeVisible()
    })

    test('user can create a lore document and find it in search', async ({ page }) => {
        const title = `E2E Document ${Date.now()}`

        await login(page)

        await page.goto('/documents/create')
        const drawer = page.getByRole('dialog', { name: 'New Document' })
        await drawer.getByLabel(/^Title$/).fill(title)
        await drawer.getByLabel(/^Document Type$/).selectOption('research_notes')
        await drawer.getByLabel(/^Authenticity$/).selectOption('authentic')
        await drawer.getByLabel(/^Document Status$/).selectOption('extant')
        await drawer.getByRole('button', { name: 'Create Document' }).click()

        await expect(page).toHaveURL(/\/documents\/\d+$/)
        await expect(page.getByRole('heading', { name: title })).toBeVisible()

        await page.goto('/search')
        await page.getByPlaceholder('Search the archive...').fill(title)
        await page.getByRole('button', { name: 'Search' }).click()

        await expect(page.getByRole('link', { name: title })).toBeVisible()
        await expect(page.getByText('Research Notes')).toBeVisible()
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

        await expect(page.getByRole('link', { name: entityName })).toBeVisible()
        await expect(page.getByText('Character · Concept')).toBeVisible()
    })

    test('user can move an entity to trash and restore it', async ({ page }) => {
        const entityName = `E2E Trash ${Date.now()}`

        await login(page)

        await createEntity(page, entityName)
        await page.getByRole('button', { name: 'Move to Trash' }).click()
        await confirmAppDialog(page, 'Move to Trash')

        await expect(page).toHaveURL(/\/entities$/)

        await page.goto('/trash')
        await page.getByRole('textbox', { name: 'Search' }).fill(entityName)
        await page.getByRole('button', { name: 'Apply' }).click()

        const trashRecord = page.locator('.index-record', {
            has: page.getByRole('heading', { name: entityName }),
        })

        await expect(trashRecord).toBeVisible()
        await trashRecord.getByRole('button', { name: 'Restore' }).click()
        await confirmAppDialog(page, 'Restore')

        await expect(page.getByRole('heading', { name: entityName })).toHaveCount(0)

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
        await expect(page.getByText('Concept').first()).toBeVisible()
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
