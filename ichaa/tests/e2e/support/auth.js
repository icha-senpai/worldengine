import { expect } from '@playwright/test'

const DATACRYPT_ROOT = '/datacrypt'
const DATACRYPT_PREFIX = `${DATACRYPT_ROOT}/worldengine`

function isPrefixedAppPath(url) {
    return url === DATACRYPT_ROOT || url.startsWith(`${DATACRYPT_ROOT}/`)
}

function isAuthOrPublicPath(url) {
    return [
        '/',
        '/login',
        '/register',
        '/forgot-password',
        '/reset-password',
        '/verify-email',
        '/confirm-password',
    ].some((path) => url === path || url.startsWith(`${path}/`))
}

export function appPath(path = '') {
    if (! path || path === '/') {
        return DATACRYPT_PREFIX
    }

    if (isPrefixedAppPath(path)) {
        return path
    }

    if (! path.startsWith('/')) {
        return `${DATACRYPT_PREFIX}/${path}`
    }

    return `${DATACRYPT_PREFIX}${path}`
}

export async function enableDatacryptRouting(page) {
    if (page.__datacryptRoutingEnabled) {
        return
    }

    const originalGoto = page.goto.bind(page)

    page.goto = async (url, options) => {
        if (typeof url === 'string' && url.startsWith('/') && ! isAuthOrPublicPath(url) && ! isPrefixedAppPath(url)) {
            return originalGoto(appPath(url), options)
        }

        return originalGoto(url, options)
    }

    page.__datacryptRoutingEnabled = true
}

export async function login(page) {
    await enableDatacryptRouting(page)
    await page.goto('/login')
    await page.getByLabel('Email').fill('e2e@example.com')
    await page.getByLabel('Password').fill('password')
    await page.getByRole('button', { name: 'Log in' }).click()

    await expect(page).not.toHaveURL(/\/login$/)
    await expect(page).toHaveURL(/\/datacrypt\/worldengine(?:\/)?$/)
    await expect(page.getByRole('banner')).toContainText('Dataverse')
}
