import { expect, test } from '@playwright/test'
import { login } from './support/auth'
import { confirmAppDialog } from './support/dialogs'

test.describe('representative domain CRUD flows', () => {
    test('user can create, edit, and trash a collection', async ({ page }) => {
        const stamp = Date.now()
        const name = `E2E Collection CRUD ${stamp}`
        const updatedName = `${name} Updated`

        await login(page)

        await page.goto('/collections/create')
        await page.getByLabel(/^Name$/).fill(name)
        await page.getByLabel(/^Collection Type$/).selectOption('character_roster')
        await page.getByLabel(/^Collection Mode$/).selectOption('manual')
        await page.getByRole('button', { name: 'Create Collection' }).click()

        await expect(page).toHaveURL(/\/collections\/\d+$/)
        await expect(page.getByRole('heading', { name })).toBeVisible()

        await page.getByRole('link', { name: 'Edit' }).click()
        await expect(page).toHaveURL(/\/collections\/\d+\/edit$/)
        await page.getByLabel(/^Name$/).fill(updatedName)
        await page.getByRole('button', { name: 'Save Collection' }).click()

        await expect(page).toHaveURL(/\/collections\/\d+$/)
        await expect(page.getByRole('heading', { name: updatedName })).toBeVisible()

        await page.getByRole('button', { name: 'Move to Trash' }).click()
        await confirmAppDialog(page, 'Move to Trash')

        await expect(page).toHaveURL(/\/collections$/)
        await expect(page.getByText(updatedName)).toHaveCount(0)
    })

    test('user can create, edit, and trash a document', async ({ page }) => {
        const stamp = Date.now()
        const title = `E2E Document CRUD ${stamp}`
        const updatedTitle = `${title} Revised`

        await login(page)

        await page.goto('/documents/create')
        await page.getByLabel(/^Title$/).fill(title)
        await page.getByLabel(/^Document Type$/).selectOption('research_notes')
        await page.getByLabel(/^Authenticity$/).selectOption('authentic')
        await page.getByLabel(/^Document Status$/).selectOption('extant')
        await page.getByRole('button', { name: 'Create Document' }).click()

        await expect(page).toHaveURL(/\/documents\/\d+$/)
        await expect(page.getByRole('heading', { name: title })).toBeVisible()

        await page.getByRole('link', { name: 'Edit' }).click()
        await expect(page).toHaveURL(/\/documents\/\d+\/edit$/)
        await page.getByLabel(/^Title$/).fill(updatedTitle)
        await page.getByRole('button', { name: 'Save Document' }).click()

        await expect(page).toHaveURL(/\/documents\/\d+$/)
        await expect(page.getByRole('heading', { name: updatedTitle })).toBeVisible()

        await page.getByRole('button', { name: 'Move to Trash' }).click()
        await confirmAppDialog(page, 'Move to Trash')

        await expect(page).toHaveURL(/\/documents$/)
        await expect(page.getByText(updatedTitle)).toHaveCount(0)
    })

    test('user can create, edit, and trash a meta note', async ({ page }) => {
        const stamp = Date.now()
        const title = `E2E Meta CRUD ${stamp}`
        const updatedTitle = `${title} Updated`

        await login(page)

        await page.goto('/meta/create')
        await page.getByLabel(/^Title$/).fill(title)
        await page.getByLabel(/^Category$/).selectOption('themes_and_motifs')
        await page.getByLabel(/^Note Type$/).selectOption('decision')
        await page.getByLabel(/^Priority$/).selectOption('medium')
        await page.getByRole('button', { name: 'Create Meta Note' }).click()

        await expect(page).toHaveURL(/\/meta\/\d+$/)
        await expect(page.getByRole('heading', { name: title })).toBeVisible()

        await page.getByRole('link', { name: 'Edit' }).click()
        await expect(page).toHaveURL(/\/meta\/\d+\/edit$/)
        await page.getByLabel(/^Title$/).fill(updatedTitle)
        await page.getByLabel(/^Action Status$/).selectOption('in_progress')
        await page.getByRole('button', { name: 'Save Meta Note' }).click()

        await expect(page).toHaveURL(/\/meta\/\d+$/)
        await expect(page.getByRole('heading', { name: updatedTitle })).toBeVisible()
        await expect(page.getByText('In Progress')).toBeVisible()

        await page.getByRole('button', { name: 'Move to Trash' }).click()
        await confirmAppDialog(page, 'Move to Trash')

        await expect(page).toHaveURL(/\/meta$/)
        await expect(page.getByText(updatedTitle)).toHaveCount(0)
    })

    test('user can create, edit, and trash a secret with rich text content', async ({ page }) => {
        const stamp = Date.now()
        const title = `E2E Secret CRUD ${stamp}`
        const updatedTitle = `${title} Opened`

        await login(page)

        await page.goto('/secrets/create')
        await page.getByLabel(/^Title$/).fill(title)
        await page.getByLabel(/^Secret Type$/).selectOption('plan')
        await page.getByLabel(/^Exposure Risk$/).selectOption('high')
        await page.getByLabel(/^Status$/).fill('active')
        await fillRichTextByLabel(page, 'Secret Content', 'This secret exists to validate the rich text create flow.')
        await page.getByRole('button', { name: 'Create Secret' }).click()

        await expect(page).toHaveURL(/\/secrets\/\d+$/)
        await expect(page.getByRole('heading', { name: title })).toBeVisible()

        await page.getByRole('link', { name: 'Edit' }).click()
        await expect(page).toHaveURL(/\/secrets\/\d+\/edit$/)
        await page.getByLabel(/^Title$/).fill(updatedTitle)
        await page.getByLabel(/^Status$/).fill('partially_exposed')
        await page.getByRole('button', { name: 'Save Secret' }).click()

        await expect(page).toHaveURL(/\/secrets\/\d+$/)
        await expect(page.getByRole('heading', { name: updatedTitle })).toBeVisible()

        await page.getByRole('button', { name: 'Move to Trash' }).click()
        await confirmAppDialog(page, 'Move to Trash')

        await expect(page).toHaveURL(/\/secrets$/)
        await expect(page.getByText(updatedTitle)).toHaveCount(0)
    })

    test('user can create, edit, and trash a power interaction', async ({ page }) => {
        const stamp = Date.now()
        const systemA = `E2E System A ${stamp}`
        const systemB = `E2E System B ${stamp}`
        const interactionName = `E2E Interaction ${stamp}`
        const updatedName = `${interactionName} Revised`

        await login(page)

        const systemAId = await createEntity(page, systemA, 'power_system')
        const systemBId = await createEntity(page, systemB, 'power_system')

        await page.goto('/power-interactions/create')
        await page.getByLabel(/^System A$/).selectOption(String(systemAId))
        await page.getByLabel(/^System B$/).selectOption(String(systemBId))
        await page.getByLabel(/^Interaction Name$/).fill(interactionName)
        await page.getByLabel(/^Directionality$/).selectOption('contextual')
        await page.getByLabel(/^Interaction Scale$/).selectOption('local')
        await page.getByLabel(/^Knowledge State$/).selectOption('theorized')
        await page.getByLabel(/^Danger Rating$/).selectOption('high')
        await page.getByRole('button', { name: 'Create Interaction' }).click()

        await expect(page).toHaveURL(/\/power-interactions\/\d+$/)
        await expect(page.getByRole('heading', { name: interactionName })).toBeVisible()

        await page.getByRole('link', { name: 'Edit' }).click()
        await expect(page).toHaveURL(/\/power-interactions\/\d+\/edit$/)
        await page.getByLabel(/^Interaction Name$/).fill(updatedName)
        await page.getByRole('checkbox', { name: /^Unresolved$/ }).check()
        await page.getByRole('button', { name: 'Save Interaction' }).click()

        await expect(page).toHaveURL(/\/power-interactions\/\d+$/)
        await expect(page.getByRole('heading', { name: updatedName })).toBeVisible()

        await page.getByRole('button', { name: 'Move to Trash' }).click()
        await confirmAppDialog(page, 'Move to Trash')

        await expect(page).toHaveURL(/\/power-interactions$/)
        await expect(page.getByText(updatedName)).toHaveCount(0)
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
