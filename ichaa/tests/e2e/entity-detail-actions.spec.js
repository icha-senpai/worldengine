import { expect, test } from '@playwright/test'
import { login } from './support/auth'
import { confirmAppDialog } from './support/dialogs'

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
        const updatedQuestion = `Open question ${stamp} revised?`

        await login(page)

        const entityId = await createEntity(page, entityName, 'character')

        await page.goto(`/entities/${entityId}?tab=aliases`)

        await page.getByRole('link', { name: 'Add Alias' }).click()
        const aliasDrawer = page.getByRole('dialog', { name: 'New Alias' })
        await expect(aliasDrawer).toBeVisible()
        await aliasDrawer.getByLabel(/^Alias$/).fill(alias)
        await aliasDrawer.getByLabel(/^Type$/).selectOption('nickname')
        await aliasDrawer.getByRole('button', { name: 'Create Alias' }).click()

        await expect(page).toHaveURL(new RegExp(`/entities/${entityId}\\?tab=aliases$`))
        await expect(page.getByText(alias)).toBeVisible()

        const aliasCard = page.locator('.record-card').filter({ hasText: alias }).first()
        await aliasCard.getByRole('link', { name: 'Edit' }).click()
        const editAliasDrawer = page.getByRole('dialog', { name: 'Edit Alias' })
        await expect(editAliasDrawer).toBeVisible()
        await editAliasDrawer.getByLabel(/^Alias$/).fill(updatedAlias)
        await editAliasDrawer.getByRole('button', { name: 'Save Alias' }).click()

        await expect(page).toHaveURL(new RegExp(`/entities/${entityId}\\?tab=aliases$`))
        await expect(page.getByText(updatedAlias)).toBeVisible()

        const updatedAliasCard = page.locator('.record-card').filter({ hasText: updatedAlias }).first()
        await updatedAliasCard.getByRole('link', { name: 'Edit' }).click()
        await expect(editAliasDrawer).toBeVisible()
        await editAliasDrawer.getByRole('button', { name: 'Delete Alias' }).click()
        await confirmAppDialog(page, 'Delete Alias')
        await expect(page.locator('.record-card').filter({ hasText: updatedAlias })).toHaveCount(0)

        await page.getByRole('button', { name: 'Notes' }).click()
        await page.getByRole('link', { name: 'Add Note' }).click()
        const noteDrawer = page.getByRole('dialog', { name: 'New Note' })
        await expect(noteDrawer).toBeVisible()
        await noteDrawer.getByLabel(/^Label$/).fill(noteLabel)
        await noteDrawer.getByPlaceholder('Note content...').fill(noteContent)
        await noteDrawer.getByRole('button', { name: 'Create Note' }).click()

        await expect(page).toHaveURL(new RegExp(`/entities/${entityId}\\?tab=notes$`))
        await expect(page.getByText(noteContent)).toBeVisible()

        const noteCard = page.locator('.record-card').filter({ hasText: noteContent }).first()
        await noteCard.getByRole('link', { name: 'Edit' }).click()
        const editNoteDrawer = page.getByRole('dialog', { name: 'Edit Note' })
        await expect(editNoteDrawer).toBeVisible()
        await editNoteDrawer.getByPlaceholder('Note content...').fill(updatedNoteContent)
        await editNoteDrawer.getByRole('button', { name: 'Save Note' }).click()

        await expect(page).toHaveURL(new RegExp(`/entities/${entityId}\\?tab=notes$`))
        await expect(page.getByText(updatedNoteContent)).toBeVisible()

        const updatedNoteCard = page.locator('.record-card').filter({ hasText: updatedNoteContent }).first()
        await updatedNoteCard.getByRole('link', { name: 'Edit' }).click()
        await expect(editNoteDrawer).toBeVisible()
        await editNoteDrawer.getByRole('button', { name: 'Delete Note' }).click()
        await confirmAppDialog(page, 'Delete Note')
        await expect(page.locator('.record-card').filter({ hasText: updatedNoteContent })).toHaveCount(0)

        await page.getByRole('button', { name: 'Questions' }).click()
        await page.getByRole('link', { name: 'Add Question' }).click()
        const questionDrawer = page.getByRole('dialog', { name: 'New Question' })
        await expect(questionDrawer).toBeVisible()
        await questionDrawer.getByLabel(/^Question$/).fill(question)
        await questionDrawer.getByRole('button', { name: 'Create Question' }).click()

        await expect(page).toHaveURL(new RegExp(`/entities/${entityId}\\?tab=questions$`))
        await expect(page.getByText(question)).toBeVisible()

        const questionCard = page.locator('.record-card').filter({ hasText: question }).first()
        await questionCard.getByRole('link', { name: 'Edit' }).click()
        const editQuestionDrawer = page.getByRole('dialog', { name: 'Edit Question' })
        await expect(editQuestionDrawer).toBeVisible()
        await editQuestionDrawer.getByLabel(/^Question$/).fill(updatedQuestion)
        await editQuestionDrawer.getByRole('button', { name: 'Save Question' }).click()

        await expect(page).toHaveURL(new RegExp(`/entities/${entityId}\\?tab=questions$`))
        const updatedQuestionCard = page.locator('.record-card').filter({ hasText: updatedQuestion }).first()
        await expect(updatedQuestionCard).toBeVisible()
        await updatedQuestionCard.getByRole('button', { name: 'Resolve' }).click()
        await expect(updatedQuestionCard.getByText('Resolved')).toBeVisible()

        await updatedQuestionCard.getByRole('link', { name: 'Edit' }).click()
        await expect(editQuestionDrawer).toBeVisible()
        await editQuestionDrawer.getByRole('button', { name: 'Delete Question' }).click()
        await confirmAppDialog(page, 'Delete Question')
        await expect(page.locator('.record-card').filter({ hasText: updatedQuestion })).toHaveCount(0)
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
