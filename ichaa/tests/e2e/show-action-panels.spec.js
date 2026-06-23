import { expect, test } from '@playwright/test'
import { login } from './support/auth'

test.describe('show page action panels', () => {
    test('meta show page can link entities, resolve notes, and supersede', async ({ page }) => {
        const stamp = Date.now()
        const entityName = `E2E Meta Link ${stamp}`
        const firstTitle = `E2E Meta First ${stamp}`
        const secondTitle = `E2E Meta Second ${stamp}`

        await login(page)

        const entityId = await createEntity(page, entityName, 'character')
        const firstMetaId = await createMeta(page, firstTitle)
        await createMeta(page, secondTitle)

        await page.goto(`/meta/${firstMetaId}`)

        await page.getByLabel(/^Link Entity$/).selectOption(String(entityId))
        await page.getByRole('button', { name: 'Link Entity' }).click()
        await expect(page.locator('.record-card').filter({ hasText: entityName }).first()).toBeVisible()

        await fillRichTextByLabel(page, 'Resolution Notes', 'This thread was settled after the scene outline was locked.')
        await page.getByRole('button', { name: 'Resolve Note' }).click()
        await expect(page.getByRole('button', { name: 'Already Resolved' })).toBeVisible()

        await page.getByLabel(/^Superseded By$/).selectOption({ label: secondTitle })
        await page.getByLabel(/^Supersession Reason$/).fill('A newer note absorbed the decision.')
        await page.getByRole('button', { name: 'Supersede Note' }).click()
        await expect(page.getByText(secondTitle).first()).toBeVisible()
    })

    test('knowledge state show page can mark knowledge as acted on', async ({ page }) => {
        const stamp = Date.now()
        const knowerName = `E2E Action Knower ${stamp}`

        await login(page)

        const knowerId = await createEntity(page, knowerName, 'character')
        const knowledgeId = await createKnowledgeState(page, knowerId)

        await page.goto(`/knowledge-states/${knowledgeId}`)
        await fillRichTextByLabel(page, 'Action Notes', 'The knower used this information to change the plan.')
        await page.getByRole('button', { name: 'Mark Acted On' }).click()

        await expect(page.getByText('This knowledge state has already been acted on.')).toBeVisible()
        await expect(page.getByText('The knower used this information to change the plan.')).toBeVisible()
    })

    test('secret show page can add known-by entities and record exposure', async ({ page }) => {
        const stamp = Date.now()
        const secretTitle = `E2E Secret Action ${stamp}`
        const witnessName = `E2E Witness ${stamp}`

        await login(page)

        const witnessId = await createEntity(page, witnessName, 'character')
        const secretId = await createSecret(page, secretTitle)

        await page.goto(`/secrets/${secretId}`)
        await page.getByLabel(/^Entity$/).selectOption(String(witnessId))
        await page.getByRole('button', { name: 'Add Entity' }).click()
        await expect(page.getByRole('main').getByText(`${witnessName} (character)`)).toBeVisible()

        await page.getByLabel(/^Revealed At Era$/).fill('Red Winter')
        await page.getByLabel(/^Exposure Level$/).selectOption('fully_exposed')
        await page.getByRole('button', { name: 'Record Exposure' }).click()

        await expect(page.getByText('Red Winter')).toBeVisible()
        await expect(page.getByText('fully_exposed')).toBeVisible()
    })

    test('perception state show page can add immune entities and collapse', async ({ page }) => {
        const stamp = Date.now()
        const subjectName = `E2E Perception Subject ${stamp}`
        const immuneName = `E2E Perception Immune ${stamp}`

        await login(page)

        const subjectId = await createEntity(page, subjectName, 'character')
        const immuneId = await createEntity(page, immuneName, 'character')
        const perceptionId = await createPerceptionState(page, subjectId)

        await page.goto(`/perception-states/${perceptionId}`)
        await page.getByLabel(/^Entity$/).selectOption(String(immuneId))
        await page.getByRole('button', { name: 'Add Immune' }).click()
        await expect(page.getByRole('main').getByText(`${immuneName} (character)`)).toBeVisible()

        await page.getByLabel(/^Revealed At Era$/).fill('Shattered Spring')
        await page.getByRole('button', { name: 'Collapse Perception State' }).click()

        await expect(page.getByText('This perception state has already collapsed.')).toBeVisible()
    })

    test('power interaction show page can record an instance and resolve the interaction', async ({ page }) => {
        const stamp = Date.now()
        const systemA = `E2E Action System A ${stamp}`
        const systemB = `E2E Action System B ${stamp}`
        const eventName = `E2E Interaction Event ${stamp}`
        const interactionName = `E2E Action Interaction ${stamp}`

        await login(page)

        const systemAId = await createEntity(page, systemA, 'power_system')
        const systemBId = await createEntity(page, systemB, 'power_system')
        const eventId = await createEntity(page, eventName, 'event')
        const interactionId = await createPowerInteraction(page, systemAId, systemBId, interactionName)

        await page.goto(`/power-interactions/${interactionId}/edit`)
        await page.getByRole('checkbox', { name: /^Unresolved$/ }).check()
        await page.getByRole('button', { name: 'Save Interaction' }).click()
        await expect(page).toHaveURL(new RegExp(`/power-interactions/${interactionId}$`))

        await page.getByLabel(/^Event Entity$/).selectOption(String(eventId))
        await page.getByLabel(/^Outcome Match$/).selectOption('confirmed')
        await page.getByLabel(/^Observed At Era$/).fill('Quiet Dawn')
        await fillRichTextByLabel(page, 'Outcome Notes', 'The observed event matched the established rule.')
        await page.getByRole('button', { name: 'Record Instance' }).click()
        await expect(page.getByText(eventName).first()).toBeVisible()

        await page.getByLabel(/^Knowledge State$/).selectOption('established')
        await fillRichTextByLabel(page, 'Resolution Notes', 'Repeated observation locked the rule into place.')
        await page.getByRole('button', { name: 'Resolve Interaction' }).click()

        await expect(page.getByText('This interaction is currently resolved.')).toBeVisible()
    })
})

async function createEntity(page, name, typeValue = 'character') {
    await page.goto('/entities/create')
    await page.getByLabel(/^Name$/).fill(name)
    await page.getByLabel(/^Type$/).selectOption(typeValue)
    await page.getByLabel(/^Visibility$/).selectOption('public_knowledge')
    await page.getByRole('button', { name: 'Create Entity' }).click()

    await expect(page).toHaveURL(/\/entities\/\d+$/)

    return extractId(page.url(), /\/entities\/(\d+)$/)
}

async function createMeta(page, title) {
    await page.goto('/meta/create')
    await page.getByLabel(/^Title$/).fill(title)
    await page.getByLabel(/^Category$/).selectOption('themes_and_motifs')
    await page.getByLabel(/^Note Type$/).selectOption('decision')
    await page.getByLabel(/^Priority$/).selectOption('medium')
    await page.getByRole('button', { name: 'Create Meta Note' }).click()

    await expect(page).toHaveURL(/\/meta\/\d+$/)

    return extractId(page.url(), /\/meta\/(\d+)$/)
}

async function createKnowledgeState(page, knowerId) {
    await page.goto('/knowledge-states/create')
    await page.getByLabel(/^Knower$/).selectOption(String(knowerId))
    await selectFirstNonEmptyOption(page, 'Knowledge Type')
    await selectFirstNonEmptyOption(page, 'Accuracy')
    await selectFirstNonEmptyOption(page, 'Belief State')
    await selectFirstNonEmptyOption(page, 'Acquired Through')
    await fillRichTextByLabel(page, 'Knowledge Content', 'Knowledge state content for the action panel flow.')
    await page.getByRole('button', { name: 'Create Knowledge State' }).click()

    await expect(page).toHaveURL(/\/knowledge-states\/\d+$/)

    return extractId(page.url(), /\/knowledge-states\/(\d+)$/)
}

async function createSecret(page, title) {
    await page.goto('/secrets/create')
    await page.getByLabel(/^Title$/).fill(title)
    await page.getByLabel(/^Secret Type$/).selectOption('plan')
    await page.getByLabel(/^Exposure Risk$/).selectOption('high')
    await page.getByLabel(/^Status$/).fill('active')
    await fillRichTextByLabel(page, 'Secret Content', 'Secret content used to verify show page actions.')
    await page.getByRole('button', { name: 'Create Secret' }).click()

    await expect(page).toHaveURL(/\/secrets\/\d+$/)

    return extractId(page.url(), /\/secrets\/(\d+)$/)
}

async function createPerceptionState(page, subjectId) {
    await page.goto('/perception-states/create')
    await page.getByLabel(/^Subject Type$/).selectOption('entity')
    await page.getByLabel(/^Subject$/).selectOption(String(subjectId))
    await selectFirstNonEmptyOption(page, 'Divergence Level')
    await fillRichTextByLabel(page, 'True State', 'What is really true here.')
    await fillRichTextByLabel(page, 'Perceived State', 'What most observers think is true.')
    await page.getByRole('button', { name: 'Create Perception State' }).click()

    await expect(page).toHaveURL(/\/perception-states\/\d+$/)

    return extractId(page.url(), /\/perception-states\/(\d+)$/)
}

async function createPowerInteraction(page, systemAId, systemBId, interactionName) {
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

    return extractId(page.url(), /\/power-interactions\/(\d+)$/)
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
    const select = page.getByLabel(new RegExp(`^${label}$`))
    const options = await select.locator('option').evaluateAll((nodes) =>
        nodes.map((node) => ({ value: node.value, label: node.textContent?.trim() ?? '' })),
    )
    const firstValue = options.find((option) => option.value)?.value

    if (!firstValue) {
        throw new Error(`No selectable option found for ${label}`)
    }

    await select.selectOption(firstValue)
}

function extractId(url, regex) {
    const match = url.match(regex)

    return Number(match?.[1])
}
