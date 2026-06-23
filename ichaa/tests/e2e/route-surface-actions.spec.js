import { expect, test } from '@playwright/test'
import { login } from './support/auth'

test.describe('route surface actions', () => {
    test('faction membership flow stays inside the memberships tab', async ({ page }) => {
        const stamp = Date.now()
        const factionName = `E2E Faction ${stamp}`
        const memberName = `E2E Member ${stamp}`

        await login(page)

        const factionId = await createEntity(page, factionName, 'faction')
        const memberId = await createEntity(page, memberName, 'character')

        await page.goto(`/entities/${factionId}?tab=memberships`)
        await expect(page.getByRole('heading', { name: factionName })).toBeVisible()
        await expect(page.getByRole('heading', { name: 'Faction Roster' })).toBeVisible()

        await page.getByRole('link', { name: 'Add Member' }).click()

        const drawer = page.getByRole('dialog', { name: 'New Faction Membership' })
        await expect(drawer).toBeVisible()

        await drawer.getByRole('link', { name: 'Cancel' }).click()
        await expect(page).toHaveURL(new RegExp(`/entities/${factionId}\\?tab=memberships$`))
        await expect(page.getByRole('heading', { name: 'Faction Roster' })).toBeVisible()

        await page.getByRole('link', { name: 'Add Member' }).click()
        await expect(drawer).toBeVisible()
        await drawer.getByLabel(/^Member$/).selectOption(String(memberId))
        await drawer.getByLabel(/^Rank or Role$/).fill('Field Liaison')
        await drawer.getByLabel(/^Membership Status$/).selectOption('active')
        await drawer.getByLabel(/^Joined Era$/).fill('Postwar Convergence')
        await drawer.getByLabel(/^Membership Notes$/).fill('Acts as the first outside liaison into the faction.')
        await drawer.getByRole('button', { name: 'Create Membership' }).click()

        await expect(page).toHaveURL(new RegExp(`/entities/${factionId}\\?tab=memberships$`))
        await expect(page.getByRole('heading', { name: factionName })).toBeVisible()
        const rosterCard = page.locator('.record-card', { hasText: memberName }).first()
        await expect(rosterCard).toBeVisible()
        await expect(rosterCard.getByText('Field Liaison')).toBeVisible()

        await rosterCard.getByRole('link', { name: 'Edit' }).click()
        const editDrawer = page.getByRole('dialog', { name: 'Edit Faction Membership' })
        await expect(editDrawer).toBeVisible()
        await editDrawer.getByLabel(/^Membership Status$/).selectOption('former')
        await editDrawer.getByLabel(/^Left Era$/).fill('Tribunal Winter')
        await editDrawer.getByLabel(/^Departure Reason$/).fill('Reassigned after the liaison window closed.')
        await editDrawer.getByRole('button', { name: 'Save Membership' }).click()

        await expect(page).toHaveURL(new RegExp(`/entities/${factionId}\\?tab=memberships$`))
        const updatedRosterCard = page.locator('.record-card', { hasText: memberName }).first()
        await expect(updatedRosterCard).toBeVisible()
        await expect(updatedRosterCard.getByText('Former')).toBeVisible()
        await expect(updatedRosterCard.getByText('Tribunal Winter')).toBeVisible()

        await updatedRosterCard.getByRole('link', { name: 'Edit' }).click()
        await expect(editDrawer).toBeVisible()
        page.once('dialog', (dialog) => dialog.accept())
        await editDrawer.getByRole('button', { name: 'Move to Trash' }).click()

        await expect(page).toHaveURL(new RegExp(`/entities/${factionId}\\?tab=memberships$`))
        await expect(page.locator('.record-card', { hasText: memberName })).toHaveCount(0)
    })

    test('member affiliation flow stays inside the member memberships tab', async ({ page }) => {
        const stamp = Date.now()
        const factionName = `E2E Affiliation Faction ${stamp}`
        const memberName = `E2E Affiliation Member ${stamp}`

        await login(page)

        const factionId = await createEntity(page, factionName, 'faction')
        const memberId = await createEntity(page, memberName, 'character')

        await page.goto(`/entities/${memberId}?tab=memberships`)
        await expect(page.getByRole('heading', { name: memberName })).toBeVisible()
        await expect(page.getByRole('heading', { name: 'Affiliations' })).toBeVisible()

        await page.getByRole('link', { name: 'Add Affiliation' }).click()

        const drawer = page.getByRole('dialog', { name: 'New Faction Membership' })
        await expect(drawer).toBeVisible()
        await drawer.getByLabel(/^Faction$/).selectOption(String(factionId))
        await drawer.getByLabel(/^Rank or Role$/).fill('Embedded contact')
        await drawer.getByLabel(/^Joined Era$/).fill('Glassfall Accord')
        await drawer.getByRole('button', { name: 'Create Membership' }).click()

        await expect(page).toHaveURL(new RegExp(`/entities/${memberId}\\?tab=memberships$`))
        const affiliationCard = page.locator('.record-card', { hasText: factionName }).first()
        await expect(affiliationCard).toBeVisible()
        await expect(affiliationCard.getByText('Embedded contact')).toBeVisible()

        await affiliationCard.getByRole('link', { name: 'Edit' }).click()
        const editDrawer = page.getByRole('dialog', { name: 'Edit Faction Membership' })
        await expect(editDrawer).toBeVisible()
        await editDrawer.getByLabel(/^Membership Status$/).selectOption('inactive')
        await editDrawer.getByLabel(/^Membership Notes$/).fill('Shifted into a dormant observer role.')
        await editDrawer.getByRole('button', { name: 'Save Membership' }).click()

        await expect(page).toHaveURL(new RegExp(`/entities/${memberId}\\?tab=memberships$`))
        const updatedAffiliationCard = page.locator('.record-card', { hasText: factionName }).first()
        await expect(updatedAffiliationCard).toBeVisible()
        await expect(updatedAffiliationCard.getByText('Inactive')).toBeVisible()

        await updatedAffiliationCard.getByRole('link', { name: 'Edit' }).click()
        await expect(editDrawer).toBeVisible()
        page.once('dialog', (dialog) => dialog.accept())
        await editDrawer.getByRole('button', { name: 'Move to Trash' }).click()

        await expect(page).toHaveURL(new RegExp(`/entities/${memberId}\\?tab=memberships$`))
        await expect(page.locator('.record-card', { hasText: factionName })).toHaveCount(0)
    })

    test('entity versions index and show pages render from the initial version', async ({ page }) => {
        const stamp = Date.now()
        const entityName = `E2E Versioned Entity ${stamp}`

        await login(page)

        const entityId = await createEntity(page, entityName, 'character')

        await page.goto(`/entities/${entityId}/versions`)
        await expect(page.getByRole('heading', { name: `${entityName} Versions` })).toBeVisible()

        const firstVersionLink = page.getByRole('link', { name: /Initial|Version/i }).first()
        await expect(firstVersionLink).toBeVisible()
        await firstVersionLink.click()

        await expect(page).toHaveURL(new RegExp(`/entities/${entityId}/versions/\\d+$`))
        await expect(page.getByRole('heading', { level: 1, name: /Initial|Version/i })).toBeVisible()
        await expect(page.getByRole('link', { name: entityName })).toBeVisible()
    })

    test('collection show page can add and remove members in place', async ({ page }) => {
        const stamp = Date.now()
        const collectionName = `E2E Managed Collection ${stamp}`
        const memberName = `E2E Collection Member ${stamp}`

        await login(page)

        const memberId = await createEntity(page, memberName, 'character')
        const collectionId = await createCollection(page, collectionName)

        await page.goto(`/collections/${collectionId}`)
        await expect(page.getByRole('heading', { name: collectionName })).toBeVisible()

        await page.getByLabel(/^Entity$/).selectOption(String(memberId))
        await page.getByLabel(/^Role In Collection$/).fill('Primary file subject')
        await page.getByRole('button', { name: 'Add Member' }).click()

        const memberCard = page.locator('.record-card', { hasText: memberName }).first()
        await expect(memberCard).toBeVisible()
        await expect(memberCard.getByText('manual')).toBeVisible()

        page.once('dialog', (dialog) => dialog.accept())
        await memberCard.getByRole('button', { name: 'Remove' }).click()

        await expect(page.locator('.record-card', { hasText: memberName })).toHaveCount(0)
    })

    test('group relationship show page can add and remove members in place', async ({ page }) => {
        const stamp = Date.now()
        const groupName = `E2E Managed Group ${stamp}`
        const memberName = `E2E Group Member ${stamp}`

        await login(page)

        const memberId = await createEntity(page, memberName, 'character')
        const groupId = await createGroupRelationship(page, groupName)

        await page.goto(`/group-relationships/${groupId}`)
        await expect(page.getByRole('heading', { name: groupName })).toBeVisible()

        await page.getByLabel(/^Entity$/).selectOption(String(memberId))
        await page.getByLabel(/^Role In Group$/).fill('Observer')
        await page.getByLabel(/^Joined Era$/).fill('Crossing Dawn')
        await page.getByRole('button', { name: 'Add Member' }).click()

        const memberCard = page.locator('.record-card', { hasText: memberName }).first()
        await expect(memberCard).toBeVisible()
        await expect(memberCard.getByText('Observer')).toBeVisible()

        page.once('dialog', (dialog) => dialog.accept())
        await memberCard.getByRole('button', { name: 'Remove' }).click()

        const departedMemberCard = page.locator('.record-card', { hasText: memberName }).first()
        await expect(departedMemberCard).toBeVisible()
        await expect(departedMemberCard.getByText('departed')).toBeVisible()
        await expect(departedMemberCard.getByRole('button', { name: 'Remove' })).toHaveCount(0)
    })
})

async function createEntity(page, name, typeValue = 'character') {
    await page.goto('/entities/create')
    await page.getByLabel(/^Name$/).fill(name)
    await page.getByLabel(/^Type$/).selectOption(typeValue)
    await page.getByLabel(/^Visibility$/).selectOption('public_knowledge')
    await page.getByRole('button', { name: 'Create Entity' }).click()

    await expect(page).toHaveURL(/\/entities\/\d+$/)

    const match = page.url().match(/\/entities\/(\d+)$/)

    return Number(match?.[1])
}

async function createCollection(page, name) {
    await page.goto('/collections/create')
    await page.getByLabel(/^Name$/).fill(name)
    await page.getByLabel(/^Collection Type$/).selectOption('custom')
    await page.getByLabel(/^Collection Mode$/).selectOption('manual')
    await page.getByRole('button', { name: 'Create Collection' }).click()

    await expect(page).toHaveURL(/\/collections\/\d+$/)

    const match = page.url().match(/\/collections\/(\d+)$/)

    return Number(match?.[1])
}

async function createGroupRelationship(page, name) {
    await page.goto('/group-relationships/create')
    await page.getByLabel(/^Name$/).fill(name)
    await page.getByLabel(/^Relationship Type$/).fill('accord')
    await page.getByRole('button', { name: 'Create Group' }).click()

    await expect(page).toHaveURL(/\/group-relationships\/\d+$/)

    const match = page.url().match(/\/group-relationships\/(\d+)$/)

    return Number(match?.[1])
}
