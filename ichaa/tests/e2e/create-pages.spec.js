import { expect, test } from '@playwright/test'
import { login } from './support/auth'

test('top-level create pages render usable forms across the site', async ({ page }) => {
    await login(page)

    const createRoutes = [
        '/entities/create',
        '/media-references/create',
        '/relationships/create',
        '/group-relationships/create',
        '/faction-memberships/create',
        '/collections/create',
        '/glossary/create',
        '/documents/create',
        '/canon-references/create',
        '/crossover-entry-points/create',
        '/timelines/create',
        '/character-states/create',
        '/concurrency-groups/create',
        '/power-interactions/create',
        '/location-containment/create',
        '/travel-routes/create',
        '/location-control/create',
        '/knowledge-states/create',
        '/secrets/create',
        '/perception-states/create',
        '/meta/create',
        '/pipeline/create',
        '/session-logs/create',
    ]

    for (const route of createRoutes) {
        await page.goto(route)
        await expect(page).toHaveURL(new RegExp(route.replaceAll('/', '\\/')))
        await expect(page.getByRole('heading', { level: 1 }).last(), route).toBeVisible()
        await expect(page.getByRole('button', { name: /Create/i }).last(), route).toBeVisible()
    }
})
