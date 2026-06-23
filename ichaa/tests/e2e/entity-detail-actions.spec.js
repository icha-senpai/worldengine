import { expect, test } from '@playwright/test'
import { login } from './support/auth'

test.describe('entity detail actions', () => {
    test('user can manage aliases, notes, and questions from the entity show page', async ({ page }) => {
        const stamp = Date.now()
        const entityName = `E2E Detail Entity ${stamp}`
        const alias = `Alias ${stamp}`
        const updatedAlias = `${alias} Revised`
        const noteLabel = `Note ${stamp}`
        const noteContent = `Initial note content ${stamp}`
        const updatedNoteContent = `${noteContent} revised`
        const question = `Open question ${stamp}?`

        await login(page)

        const entityId = await createEntity(page, entityName, 'character')

        await page.goto(`/entities/${entityId}`)

        await page.getByRole('button', { name: 'Aliases' }).click()
        const aliasPanel = page.locator('.panel').filter({ hasText: /Alias/ }).first()
        await aliasPanel.getByPlaceholder('The alias text').fill(alias)
        await aliasPanel.locator('select').selectOption('nickname')
        await aliasPanel.getByRole('button', { name: 'Add Alias' }).click()
        await expect(page.getByText(alias)).toBeVisible()

        const aliasCard = page.locator('.record-card').filter({ hasText: alias }).first()
        await aliasCard.getByRole('button', { name: 'Edit' }).click()
        await aliasPanel.getByPlaceholder('The alias text').fill(updatedAlias)
        await aliasPanel.getByRole('button', { name: 'Save Alias' }).click()
        await expect(page.getByText(updatedAlias)).toBeVisible()

        await page.locator('.record-card').filter({ hasText: updatedAlias }).first()
            .getByRole('button', { name: 'Delete' })
            .click()
        await expect(page.getByText(updatedAlias)).toHaveCount(0)

        await page.getByRole('button', { name: 'Notes' }).click()
        const notePanel = page.locator('.panel').filter({ hasText: /Note/ }).first()
        await notePanel.getByPlaceholder(/Backstory, Motivation, Arc notes/i).fill(noteLabel)
        await notePanel.getByPlaceholder('Note content...').fill(noteContent)
        await notePanel.getByRole('button', { name: 'Add Note' }).click()
        await expect(page.getByText(noteContent)).toBeVisible()

        const noteCard = page.locator('.record-card').filter({ hasText: noteContent }).first()
        await noteCard.getByRole('button', { name: 'Edit' }).click()
        await notePanel.getByPlaceholder('Note content...').fill(updatedNoteContent)
        await notePanel.getByRole('button', { name: 'Save Note' }).click()
        await expect(page.getByText(updatedNoteContent)).toBeVisible()

        await page.locator('.record-card').filter({ hasText: updatedNoteContent }).first()
            .getByRole('button', { name: 'Delete' })
            .click()
        await expect(page.getByText(updatedNoteContent)).toHaveCount(0)

        await page.getByRole('button', { name: 'Questions' }).click()
        const questionPanel = page.locator('.panel').filter({ hasText: /Question/ }).first()
        await questionPanel.getByPlaceholder('What needs to be resolved?').fill(question)
        await questionPanel.getByRole('button', { name: 'Add Question' }).click()
        await expect(page.getByText(question)).toBeVisible()

        const questionCard = page.locator('.record-card').filter({ hasText: question }).first()
        await questionCard.getByRole('button', { name: 'Resolve' }).click()
        await expect(questionCard.getByText('Resolved')).toBeVisible()

        await questionCard.getByRole('button', { name: 'Delete' }).click()
        await expect(page.getByText(question)).toHaveCount(0)
    })

    test('user can advance a pipeline item from its show page', async ({ page }) => {
        const stamp = Date.now()
        const title = `E2E Pipeline Advance ${stamp}`

        await login(page)

        await page.goto('/pipeline/create')
        await page.getByLabel(/^Title$/).fill(title)
        await page.getByLabel(/^Type$/).selectOption('note')
        await fillRichTextByLabel(page, 'Content', 'Pipeline content used to verify stage advancement.')
        await page.getByRole('button', { name: 'Create Item' }).click()

        await expect(page).toHaveURL(/\/pipeline\/\d+$/)
        await expect(page.getByRole('heading', { name: title })).toBeVisible()
        await expect(page.getByRole('main').getByText(/^Concept$/).first()).toBeVisible()

        await page.getByRole('button', { name: 'Advance →' }).click()
        await expect(page.getByRole('main').getByText(/^Outlined$/).first()).toBeVisible()
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
