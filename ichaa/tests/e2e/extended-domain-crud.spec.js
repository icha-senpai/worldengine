import { expect, test } from '@playwright/test'
import { login } from './support/auth'
import { confirmAppDialog } from './support/dialogs'

test.describe('extended domain CRUD flows', () => {
    test('user can create, edit, and trash a glossary term', async ({ page }) => {
        const stamp = Date.now()
        const term = `E2E Glossary ${stamp}`
        const updatedTerm = `${term} Revised`

        await login(page)

        await page.goto('/glossary/create')
        await page.getByLabel(/^Term$/).fill(term)
        await page.getByLabel(/^Usage Context$/).selectOption('both')
        await fillRichTextByLabel(page, 'Definition', 'A field-facing term used to verify glossary drawer behavior.')
        await page.getByRole('button', { name: 'Create Term' }).click()

        await expect(page).toHaveURL(/\/glossary\/\d+$/)
        await expect(page.getByRole('heading', { name: term })).toBeVisible()

        await page.getByRole('link', { name: 'Edit' }).click()
        await expect(page).toHaveURL(/\/glossary\/\d+\/edit$/)
        await page.getByLabel(/^Term$/).fill(updatedTerm)
        await page.getByRole('button', { name: 'Save Term' }).click()

        await expect(page).toHaveURL(/\/glossary\/\d+$/)
        await expect(page.getByRole('heading', { name: updatedTerm })).toBeVisible()

        await page.getByRole('button', { name: 'Move to Trash' }).click()
        await confirmAppDialog(page, 'Move to Trash')

        await expect(page).toHaveURL(/\/glossary$/)
        await expect(page.getByText(updatedTerm)).toHaveCount(0)
    })

    test('user can create, edit, and trash a canon reference', async ({ page }) => {
        const stamp = Date.now()
        const title = `E2E Canon ${stamp}`
        const updatedTitle = `${title} Updated`

        await login(page)

        await page.goto('/canon-references/create')
        await page.getByLabel(/^Universe$/).fill('Harry Potter')
        await selectFirstNonEmptyOption(page, 'Level')
        await page.getByLabel(/^Title$/).fill(title)
        await selectFirstNonEmptyOption(page, 'Universe Priority')
        await selectFirstNonEmptyOption(page, 'Research Status')
        await page.getByRole('button', { name: 'Create Reference' }).click()

        await expect(page).toHaveURL(/\/canon-references\/\d+$/)
        await expect(page.getByRole('heading', { name: title })).toBeVisible()

        await page.getByRole('link', { name: 'Edit' }).click()
        await expect(page).toHaveURL(/\/canon-references\/\d+\/edit$/)
        await page.getByLabel(/^Title$/).fill(updatedTitle)
        await page.getByRole('checkbox', { name: /^Canon Disputed$/ }).check()
        await page.getByRole('button', { name: 'Save Reference' }).click()

        await expect(page).toHaveURL(/\/canon-references\/\d+$/)
        await expect(page.getByRole('heading', { name: updatedTitle })).toBeVisible()

        await page.getByRole('button', { name: 'Move to Trash' }).click()
        await confirmAppDialog(page, 'Move to Trash')

        await expect(page).toHaveURL(/\/canon-references$/)
        await expect(page.getByText(updatedTitle)).toHaveCount(0)
    })

    test('user can create, edit, and trash a crossover entry point', async ({ page }) => {
        const stamp = Date.now()
        const sourceUniverse = `E2E Crossing ${stamp}`
        const updatedText = 'Updated crossover rules for the editor pass.'

        await login(page)

        await page.goto('/crossover-entry-points/create')
        await page.getByLabel(/^Source Universe$/).fill(sourceUniverse)
        await selectFirstNonEmptyOption(page, 'Status')
        await fillRichTextByLabel(page, 'Entry Mechanism', 'Initial threshold behavior for the crossover seam.')
        await page.getByRole('button', { name: 'Create Entry Point' }).click()

        await expect(page).toHaveURL(/\/crossover-entry-points\/\d+$/)
        await expect(page.getByRole('heading', { name: sourceUniverse })).toBeVisible()

        await page.getByRole('link', { name: 'Edit' }).click()
        await expect(page).toHaveURL(/\/crossover-entry-points\/\d+\/edit$/)
        await fillRichTextByLabel(page, 'Return Rules', updatedText)
        await page.getByRole('button', { name: 'Save Entry Point' }).click()

        await expect(page).toHaveURL(/\/crossover-entry-points\/\d+$/)
        await expect(page.getByText(updatedText)).toBeVisible()

        await page.getByRole('button', { name: 'Move to Trash' }).click()
        await confirmAppDialog(page, 'Move to Trash')

        await expect(page).toHaveURL(/\/crossover-entry-points$/)
        await expect(page.getByText(sourceUniverse)).toHaveCount(0)
    })

    test('user can create, edit, and trash a knowledge state', async ({ page }) => {
        const stamp = Date.now()
        const knowerName = `E2E Knower ${stamp}`
        const updatedContent = 'Revised belief state after the UI verification pass.'

        await login(page)

        const knowerId = await createEntity(page, knowerName, 'character')

        await page.goto('/knowledge-states/create')
        await page.getByLabel(/^Knower$/).selectOption(String(knowerId))
        await selectFirstNonEmptyOption(page, 'Knowledge Type')
        await selectFirstNonEmptyOption(page, 'Accuracy')
        await selectFirstNonEmptyOption(page, 'Belief State')
        await selectFirstNonEmptyOption(page, 'Acquired Through')
        await fillRichTextByLabel(page, 'Knowledge Content', 'Initial knowledge-state content for CRUD coverage.')
        await page.getByRole('button', { name: 'Create Knowledge State' }).click()

        await expect(page).toHaveURL(/\/knowledge-states\/\d+$/)
        await expect(page.getByRole('heading', { name: `${knowerName} Knowledge` })).toBeVisible()

        await page.getByRole('link', { name: 'Edit' }).click()
        await expect(page).toHaveURL(/\/knowledge-states\/\d+\/edit$/)
        await selectOptionByText(page, 'Belief State', 'Believes')
        await fillRichTextByLabel(page, 'Knowledge Content', updatedContent)
        await page.getByRole('button', { name: 'Save Knowledge State' }).click()

        await expect(page).toHaveURL(/\/knowledge-states\/\d+$/)
        await expect(page.getByText(updatedContent)).toBeVisible()

        await page.getByRole('button', { name: 'Move to Trash' }).click()
        await confirmAppDialog(page, 'Move to Trash')

        await expect(page).toHaveURL(/\/knowledge-states$/)
        await expect(page.getByText(knowerName)).toHaveCount(0)
    })

    test('user can create, edit, and trash a travel route', async ({ page }) => {
        const stamp = Date.now()
        const originName = `E2E Origin ${stamp}`
        const destinationName = `E2E Destination ${stamp}`
        const updatedDuration = 'Three measured hours'

        await login(page)

        const originId = await createEntity(page, originName, 'location')
        const destinationId = await createEntity(page, destinationName, 'location')

        await page.goto('/travel-routes/create')
        await page.getByLabel(/^Origin Location$/).selectOption(String(originId))
        await page.getByLabel(/^Destination Location$/).selectOption(String(destinationId))
        await selectFirstNonEmptyOption(page, 'Route Type')
        await page.getByLabel(/^Standard Duration$/).fill('Five hours')
        await page.getByRole('button', { name: 'Create Route' }).click()

        await expect(page).toHaveURL(/\/travel-routes\/\d+$/)
        await expect(page.getByRole('heading', { name: `${originName} -> ${destinationName}` })).toBeVisible()

        await page.getByRole('link', { name: 'Edit' }).click()
        await expect(page).toHaveURL(/\/travel-routes\/\d+\/edit$/)
        await page.getByLabel(/^Standard Duration$/).fill(updatedDuration)
        await page.getByRole('checkbox', { name: /^Is Active$/ }).uncheck()
        await page.getByRole('button', { name: 'Save Route' }).click()

        await expect(page).toHaveURL(/\/travel-routes\/\d+$/)
        await expect(page.getByText(updatedDuration)).toBeVisible()

        await page.getByRole('button', { name: 'Move to Trash' }).click()
        await confirmAppDialog(page, 'Move to Trash')

        await expect(page).toHaveURL(/\/travel-routes$/)
        await expect(page.getByText(originName)).toHaveCount(0)
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

async function selectOptionByText(page, label, text) {
    const select = page.getByLabel(new RegExp(`^${escapeRegex(label)}$`))
    await expect(select).toBeVisible()

    const value = await select.evaluate((element, wantedText) => {
        const options = Array.from(element.options)

        return options.find((option) => option.text.trim() === wantedText)?.value ?? ''
    }, text)

    if (!value) {
        throw new Error(`Option "${text}" not available for ${label}`)
    }

    await select.selectOption(value)
}

function escapeRegex(value) {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}
