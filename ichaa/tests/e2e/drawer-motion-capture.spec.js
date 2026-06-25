import fs from 'node:fs/promises'
import path from 'node:path'
import { expect, test } from '@playwright/test'
import { login } from './support/auth'

test.describe('drawer motion capture', () => {
    test('capture create and edit drawer open-close sequences', async ({ page }, testInfo) => {
        const outputRoot = path.resolve(process.cwd(), 'tmp', 'drawer-motion-captures')
        await fs.rm(outputRoot, { recursive: true, force: true })
        await fs.mkdir(outputRoot, { recursive: true })

        await login(page)
        await captureCreateDrawer(page, path.join(outputRoot, 'create-drawer'))
        await captureEditDrawer(page, path.join(outputRoot, 'edit-drawer'))

        await testInfo.attach('capture-root', {
            body: Buffer.from(outputRoot, 'utf8'),
            contentType: 'text/plain',
        })
    })
})

async function captureCreateDrawer(page, dir) {
    await fs.mkdir(dir, { recursive: true })

    await page.goto('/entities')
    await expect(page).toHaveURL(/\/entities$/)
    await expect(page.getByRole('heading', { name: 'Entities' })).toBeVisible()
    await page.screenshot({ path: path.join(dir, '00-before-open.png'), fullPage: true })

    const openClick = page.getByRole('link', { name: /New Entity|Create the first one/i }).first().click({ noWaitAfter: true })
    await captureFrames(page, dir, 'open')
    await openClick

    const closeClick = page.getByRole('button', { name: 'Close' }).click({ noWaitAfter: true })
    await captureFrames(page, dir, 'close')
    await closeClick
}

async function captureEditDrawer(page, dir) {
    await fs.mkdir(dir, { recursive: true })

    const entityName = `Drawer Motion ${Date.now()}`

    await page.goto('/entities/create')
    await page.getByLabel(/^Name$/).fill(entityName)
    await page.getByLabel(/^Type$/).selectOption('character')
    await page.getByLabel(/^Visibility$/).selectOption('public_knowledge')
    await page.getByRole('button', { name: 'Create Entity' }).click()
    await expect(page).toHaveURL(/\/entities\/\d+$/)
    await expect(page.getByRole('heading', { name: entityName })).toBeVisible()
    await page.screenshot({ path: path.join(dir, '00-before-open.png'), fullPage: true })

    const openClick = page.getByRole('link', { name: 'Edit' }).click({ noWaitAfter: true })
    await captureFrames(page, dir, 'open')
    await openClick

    const closeClick = page.getByRole('button', { name: 'Close' }).click({ noWaitAfter: true })
    await captureFrames(page, dir, 'close')
    await closeClick
}

async function captureFrames(page, dir, prefix) {
    const checkpoints = [0, 20, 40, 60, 80, 100, 130, 160, 200, 240, 300]

    for (let index = 0; index < checkpoints.length; index += 1) {
        const wait = index === 0 ? 0 : checkpoints[index] - checkpoints[index - 1]
        if (wait > 0) {
            await page.waitForTimeout(wait)
        }

        const file = `${prefix}-${String(index).padStart(2, '0')}-${String(checkpoints[index]).padStart(3, '0')}ms.png`
        await page.screenshot({ path: path.join(dir, file), fullPage: true })
    }
}
