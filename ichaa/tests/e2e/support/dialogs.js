import { expect } from '@playwright/test'

export async function confirmAppDialog(page, confirmLabel) {
    const dialog = page.locator('dialog[open] .app-dialog').last()

    await expect(dialog).toBeVisible()
    await dialog.getByRole('button', { name: confirmLabel }).click()
}
