import { expect } from '@playwright/test'

export async function login(page) {
    await page.goto('/login')
    await page.getByLabel('Email').fill('e2e@example.com')
    await page.getByLabel('Password').fill('password')
    await page.getByRole('button', { name: 'Log in' }).click()

    await expect(page).not.toHaveURL(/\/login$/)
    await expect(page.getByRole('banner')).toContainText('Dataverse')
}
