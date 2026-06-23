import { expect, test } from '@playwright/test'
import { login } from './support/auth'

test.describe('additional domain CRUD flows', () => {
    test('user can create, edit, and trash a group relationship', async ({ page }) => {
        const stamp = Date.now()
        const name = `E2E Group ${stamp}`
        const updatedName = `${name} Revised`

        await login(page)

        await page.goto('/group-relationships/create')
        await page.getByLabel(/^Name$/).fill(name)
        await page.getByLabel(/^Relationship Type$/).fill('cabal')
        await page.getByRole('button', { name: 'Create Group' }).click()

        await expect(page).toHaveURL(/\/group-relationships\/\d+$/)
        await expect(page.getByRole('heading', { name })).toBeVisible()

        await page.getByRole('link', { name: 'Edit' }).click()
        await expect(page).toHaveURL(/\/group-relationships\/\d+\/edit$/)
        await page.getByLabel(/^Name$/).fill(updatedName)
        await page.getByRole('button', { name: 'Save Group' }).click()

        await expect(page).toHaveURL(/\/group-relationships\/\d+$/)
        await expect(page.getByRole('heading', { name: updatedName })).toBeVisible()

        page.once('dialog', (dialog) => dialog.accept())
        await page.getByRole('button', { name: 'Move to Trash' }).click()

        await expect(page).toHaveURL(/\/group-relationships$/)
        await expect(page.getByText(updatedName)).toHaveCount(0)
    })

    test('user can create, edit, and trash a timeline', async ({ page }) => {
        const stamp = Date.now()
        const name = `E2E Timeline ${stamp}`
        const updatedName = `${name} Revised`

        await login(page)

        await page.goto('/timelines/create')
        await page.getByLabel(/^Name$/).fill(name)
        await fillRichTextByLabel(page, 'Summary', 'A timeline created during the broader CRUD browser pass.')
        await page.getByRole('button', { name: 'Create Timeline' }).click()

        await expect(page).toHaveURL(/\/timelines\/\d+$/)
        await expect(page.getByRole('heading', { name })).toBeVisible()

        await page.getByRole('link', { name: 'Edit' }).click()
        await expect(page).toHaveURL(/\/timelines\/\d+\/edit$/)
        await page.getByLabel(/^Name$/).fill(updatedName)
        await page.getByRole('button', { name: 'Save Timeline' }).click()

        await expect(page).toHaveURL(/\/timelines\/\d+$/)
        await expect(page.getByRole('heading', { name: updatedName })).toBeVisible()

        page.once('dialog', (dialog) => dialog.accept())
        await page.getByRole('button', { name: 'Move to Trash' }).click()

        await expect(page).toHaveURL(/\/timelines$/)
        await expect(page.getByText(updatedName)).toHaveCount(0)
    })

    test('user can create, edit, and trash a character snapshot', async ({ page }) => {
        const stamp = Date.now()
        const entityName = `E2E Snapshot Subject ${stamp}`
        const snapshotLabel = `Snapshot ${stamp}`
        const updatedLabel = `${snapshotLabel} Revised`

        await login(page)

        const entityId = await createEntity(page, entityName, 'character')

        await page.goto('/character-states/create')
        await page.getByLabel(/^Character Entity$/).selectOption(String(entityId))
        await page.getByLabel(/^Snapshot Label$/).fill(snapshotLabel)
        await selectFirstNonEmptyOption(page, 'Snapshot Significance')
        await page.getByRole('button', { name: 'Create Snapshot' }).click()

        await expect(page).toHaveURL(/\/character-states\/\d+$/)
        await expect(page.getByRole('heading', { name: snapshotLabel })).toBeVisible()

        await page.getByRole('link', { name: 'Edit' }).click()
        await expect(page).toHaveURL(/\/character-states\/\d+\/edit$/)
        await page.getByLabel(/^Snapshot Label$/).fill(updatedLabel)
        await selectFirstNonEmptyOption(page, 'Stability Level')
        await page.getByRole('button', { name: 'Save Snapshot' }).click()

        await expect(page).toHaveURL(/\/character-states\/\d+$/)
        await expect(page.getByRole('heading', { name: updatedLabel })).toBeVisible()

        page.once('dialog', (dialog) => dialog.accept())
        await page.getByRole('button', { name: 'Move to Trash' }).click()

        await expect(page).toHaveURL(/\/character-states$/)
        await expect(page.getByText(updatedLabel)).toHaveCount(0)
    })

    test('user can create, edit, and trash a concurrency group', async ({ page }) => {
        const stamp = Date.now()
        const name = `E2E Concurrency ${stamp}`
        const updatedName = `${name} Revised`

        await login(page)

        await page.goto('/concurrency-groups/create')
        await page.getByLabel(/^Name$/).fill(name)
        await page.getByLabel(/^AU Date$/).fill('2026-06-23')
        await page.getByRole('button', { name: 'Create Group' }).click()

        await expect(page).toHaveURL(/\/concurrency-groups\/\d+$/)
        await expect(page.getByRole('heading', { name })).toBeVisible()

        await page.getByRole('link', { name: 'Edit' }).click()
        await expect(page).toHaveURL(/\/concurrency-groups\/\d+\/edit$/)
        await page.getByLabel(/^Name$/).fill(updatedName)
        await page.getByRole('button', { name: 'Save Group' }).click()

        await expect(page).toHaveURL(/\/concurrency-groups\/\d+$/)
        await expect(page.getByRole('heading', { name: updatedName })).toBeVisible()

        await page.getByRole('link', { name: 'Edit' }).click()
        await expect(page).toHaveURL(/\/concurrency-groups\/\d+\/edit$/)

        page.once('dialog', (dialog) => dialog.accept())
        await page.getByRole('dialog', { name: 'Edit Concurrency Group' })
            .getByRole('button', { name: 'Move to Trash' })
            .click()

        await expect(page).toHaveURL(/\/concurrency-groups$/)
        await expect(page.getByText(updatedName)).toHaveCount(0)
    })

    test('user can create, edit, and trash a location containment record', async ({ page }) => {
        const stamp = Date.now()
        const childName = `E2E Child Location ${stamp}`
        const parentName = `E2E Parent Location ${stamp}`
        const title = `${childName} -> ${parentName}`

        await login(page)

        const childId = await createEntity(page, childName, 'location')
        const parentId = await createEntity(page, parentName, 'location')

        await page.goto('/location-containment/create')
        await page.getByLabel(/^Child Location$/).selectOption(String(childId))
        await page.getByLabel(/^Parent Location$/).selectOption(String(parentId))
        await selectFirstNonEmptyOption(page, 'Containment Type')
        await page.getByRole('button', { name: 'Create Containment' }).click()

        await expect(page).toHaveURL(/\/location-containment$/)
        await expect(page.getByText(title)).toBeVisible()

        await page.getByRole('link', { name: title }).click()
        await expect(page).toHaveURL(/\/location-containment\/\d+\/edit$/)
        await page.getByLabel(/^Era End$/).fill('After the breach')
        await page.getByRole('button', { name: 'Save Containment' }).click()

        await expect(page).toHaveURL(/\/location-containment$/)
        await expect(page.getByText(title)).toBeVisible()

        await page.getByRole('link', { name: title }).click()
        await expect(page).toHaveURL(/\/location-containment\/\d+\/edit$/)

        page.once('dialog', (dialog) => dialog.accept())
        await page.getByRole('button', { name: 'Move to Trash' }).click()

        await expect(page).toHaveURL(/\/location-containment$/)
        await expect(page.getByText(title)).toHaveCount(0)
    })

    test('user can create, edit, and trash a location control record', async ({ page }) => {
        const stamp = Date.now()
        const locationName = `E2E Controlled Site ${stamp}`
        const controllerName = `E2E Controller ${stamp}`
        const title = `${locationName} -> ${controllerName}`

        await login(page)

        const locationId = await createEntity(page, locationName, 'location')
        const controllerId = await createEntity(page, controllerName, 'faction')

        await page.goto('/location-control/create')
        await page.getByLabel(/^Location$/).selectOption(String(locationId))
        await page.getByLabel(/^Controlling Entity$/).selectOption(String(controllerId))
        await selectFirstNonEmptyOption(page, 'Control Type')
        await page.getByRole('button', { name: 'Create Control Record' }).click()

        await expect(page).toHaveURL(/\/location-control$/)
        await expect(page.getByText(title)).toBeVisible()

        await page.getByRole('link', { name: title }).click()
        await expect(page).toHaveURL(/\/location-control\/\d+\/edit$/)
        await selectFirstNonEmptyOption(page, 'Resistance Level')
        await page.getByRole('button', { name: 'Save Control Record' }).click()

        await expect(page).toHaveURL(/\/location-control$/)
        await expect(page.getByText(title)).toBeVisible()

        await page.getByRole('link', { name: title }).click()
        await expect(page).toHaveURL(/\/location-control\/\d+\/edit$/)

        page.once('dialog', (dialog) => dialog.accept())
        await page.getByRole('button', { name: 'Move to Trash' }).click()

        await expect(page).toHaveURL(/\/location-control$/)
        await expect(page.getByText(title)).toHaveCount(0)
    })

    test('user can create, edit, and trash a perception state', async ({ page }) => {
        const stamp = Date.now()
        const entityName = `E2E Perception Subject ${stamp}`

        await login(page)

        const entityId = await createEntity(page, entityName, 'character')

        await page.goto('/perception-states/create')
        await page.getByLabel(/^Subject Type$/).selectOption('entity')
        await page.getByLabel(/^Subject$/).selectOption(String(entityId))
        await selectFirstNonEmptyOption(page, 'Divergence Level')
        await fillRichTextByLabel(page, 'True State', 'The real state underneath the mask.')
        await fillRichTextByLabel(page, 'Perceived State', 'The false version being maintained.')
        await page.getByRole('button', { name: 'Create Perception State' }).click()

        await expect(page).toHaveURL(/\/perception-states\/\d+$/)
        await expect(page.getByText(`${entityName} (character)`).first()).toBeVisible()

        await page.getByRole('link', { name: 'Edit' }).click()
        await expect(page).toHaveURL(/\/perception-states\/\d+\/edit$/)
        await selectFirstNonEmptyOption(page, 'Maintenance Effort')
        await page.getByRole('button', { name: 'Save Perception State' }).click()

        await expect(page).toHaveURL(/\/perception-states\/\d+$/)
        await expect(page.getByText(`${entityName} (character)`).first()).toBeVisible()

        page.once('dialog', (dialog) => dialog.accept())
        await page.getByRole('button', { name: 'Move to Trash' }).click()

        await expect(page).toHaveURL(/\/perception-states$/)
        await expect(page.getByText(entityName)).toHaveCount(0)
    })

    test('user can create, edit, and trash a session log', async ({ page }) => {
        const stamp = Date.now()
        const title = `E2E Session ${stamp}`
        const updatedTitle = `${title} Revised`

        await login(page)

        await page.goto('/session-logs/create')
        await page.getByLabel(/^Title$/).fill(title)
        await page.getByLabel(/^Session Date$/).fill('2026-06-23')
        await page.getByLabel(/^External Tool$/).selectOption('notion')
        await page.getByLabel(/^Focus Description$/).fill('Broader CRUD verification pass.')
        await page.getByRole('button', { name: 'Create Session Log' }).click()

        await expect(page).toHaveURL(/\/session-logs\/\d+$/)
        await expect(page.getByRole('heading', { name: title })).toBeVisible()

        await page.getByRole('link', { name: 'Edit' }).click()
        await expect(page).toHaveURL(/\/session-logs\/\d+\/edit$/)
        await page.getByLabel(/^Title$/).fill(updatedTitle)
        await selectFirstNonEmptyOption(page, 'Session Significance')
        await page.getByRole('button', { name: 'Save Session Log' }).click()

        await expect(page).toHaveURL(/\/session-logs\/\d+$/)
        await expect(page.getByRole('heading', { name: updatedTitle })).toBeVisible()

        page.once('dialog', (dialog) => dialog.accept())
        await page.getByRole('button', { name: 'Move to Trash' }).click()

        await expect(page).toHaveURL(/\/session-logs$/)
        await expect(page.getByText(updatedTitle)).toHaveCount(0)
    })

    test('user can create, edit, and trash a media reference', async ({ page }) => {
        const stamp = Date.now()
        const entityName = `E2E Media Target ${stamp}`
        const title = `E2E Media ${stamp}`
        const updatedTitle = `${title} Revised`

        await login(page)

        const entityId = await createEntity(page, entityName, 'character')

        await page.goto('/media-references/create')
        await page.getByLabel(/^Title$/).fill(title)
        await page.getByLabel(/^Attachment Type$/).selectOption('entity')
        await page.getByLabel(/^Attachment Target$/).selectOption(String(entityId))
        await page.getByLabel(/^Source Kind$/).selectOption('external')
        await page.getByLabel(/^External URL$/).fill(`https://example.com/reference-${stamp}.png`)
        await page.getByRole('button', { name: 'Create Media' }).click()

        await expect(page).toHaveURL(/\/media-references\/\d+$/)
        await expect(page.getByRole('heading', { name: title })).toBeVisible()

        await page.getByRole('link', { name: 'Edit' }).click()
        await expect(page).toHaveURL(/\/media-references\/\d+\/edit$/)
        await page.getByLabel(/^Title$/).fill(updatedTitle)
        await page.getByRole('button', { name: 'Save Media' }).click()

        await expect(page).toHaveURL(/\/media-references\/\d+$/)
        await expect(page.getByRole('heading', { name: updatedTitle })).toBeVisible()

        page.once('dialog', (dialog) => dialog.accept())
        await page.getByRole('button', { name: 'Move to Trash' }).click()

        await expect(page).toHaveURL(/\/media-references$/)
        await expect(page.getByText(updatedTitle)).toHaveCount(0)
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

async function fillRichTextByLabel(page, label, text) {
    const editor = page.locator(`[contenteditable="true"][aria-label="${label}"]`).first()
    await expect(editor).toBeVisible()
    await editor.click()
    await editor.press('Control+A')
    await editor.press('Backspace')
    await editor.type(text)
}

async function selectFirstNonEmptyOption(page, label) {
    const select = page.getByLabel(new RegExp(`^${escapeRegex(label)}$`))
    await expect(select).toBeVisible()

    const value = await select.evaluate((element) => {
        const options = Array.from(element.options)

        return options.find((option) => option.value !== '')?.value ?? ''
    })

    if (!value) {
        throw new Error(`No non-empty option available for ${label}`)
    }

    await select.selectOption(value)
}

function escapeRegex(value) {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}
