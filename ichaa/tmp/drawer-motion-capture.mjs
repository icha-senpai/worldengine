import { chromium } from 'playwright'
import fs from 'node:fs/promises'
import path from 'node:path'
import { execFileSync, spawn } from 'node:child_process'

const cwd = process.cwd()
const baseURL = 'http://127.0.0.1:8011'
const outputRoot = path.resolve(cwd, 'tmp', 'drawer-motion-captures')

await fs.rm(outputRoot, { recursive: true, force: true })
await fs.mkdir(outputRoot, { recursive: true })

let serverProcess

try {
    prepareDatabase(cwd)
    serverProcess = startServer(cwd)
    await waitForServer()

    const browser = await chromium.launch({ headless: true })
    const page = await browser.newPage({
        viewport: { width: 1600, height: 1100 },
    })

    await login(page)
    await captureCreateDrawer(page)
    await captureEditDrawer(page)

    await browser.close()
} finally {
    if (serverProcess) {
        serverProcess.kill()
    }
}

function prepareDatabase(projectCwd) {
    execFileSync('php', ['artisan', 'migrate:fresh', '--env=testing', '--force'], {
        cwd: projectCwd,
        stdio: 'inherit',
    })

    execFileSync('php', ['tests/e2e/support/seed-e2e-user.php', '--env=testing'], {
        cwd: projectCwd,
        stdio: 'inherit',
    })
}

function startServer(projectCwd) {
    return spawn('php', ['artisan', 'serve', '--env=testing', '--host=127.0.0.1', '--port=8011'], {
        cwd: projectCwd,
        stdio: 'ignore',
        shell: false,
    })
}

async function waitForServer() {
    for (let attempt = 0; attempt < 60; attempt += 1) {
        try {
            const response = await fetch(`${baseURL}/login`)
            if (response.ok) {
                return
            }
        } catch {}

        await delay(500)
    }

    throw new Error('Timed out waiting for local test server')
}

async function login(page) {
    await page.goto(`${baseURL}/login`)
    await page.getByLabel('Email').fill('e2e@example.com')
    await page.getByLabel('Password').fill('password')
    await page.getByRole('button', { name: 'Log in' }).click()
    await page.waitForURL((url) => !url.pathname.endsWith('/login'))
}

async function captureCreateDrawer(page) {
    const dir = path.join(outputRoot, 'create-drawer')
    await fs.mkdir(dir, { recursive: true })

    await page.goto(`${baseURL}/entities`)
    await page.waitForLoadState('networkidle')
    await page.screenshot({ path: path.join(dir, '00-before-open.png'), fullPage: true })

    await page.getByRole('link', { name: /New Entity|Create the first one/i }).first().click()
    await captureFrames(page, dir, 'open')

    await page.getByRole('button', { name: 'Close' }).click()
    await captureFrames(page, dir, 'close')
}

async function captureEditDrawer(page) {
    const dir = path.join(outputRoot, 'edit-drawer')
    await fs.mkdir(dir, { recursive: true })

    const entityName = `Drawer Motion ${Date.now()}`

    await page.goto(`${baseURL}/entities/create`)
    await page.getByLabel(/^Name$/).fill(entityName)
    await page.getByLabel(/^Type$/).selectOption('character')
    await page.getByLabel(/^Visibility$/).selectOption('public_knowledge')
    await page.getByRole('button', { name: 'Create Entity' }).click()
    await page.waitForLoadState('networkidle')
    await page.screenshot({ path: path.join(dir, '00-before-open.png'), fullPage: true })

    await page.getByRole('link', { name: 'Edit' }).click()
    await captureFrames(page, dir, 'open')

    await page.getByRole('button', { name: 'Close' }).click()
    await captureFrames(page, dir, 'close')
}

async function captureFrames(page, dir, prefix) {
    const checkpoints = [0, 20, 40, 60, 80, 100, 130, 160, 200, 240, 300]

    for (let index = 0; index < checkpoints.length; index += 1) {
        const wait = index === 0 ? 0 : checkpoints[index] - checkpoints[index - 1]
        if (wait > 0) {
            await delay(wait)
        }

        const file = `${prefix}-${String(index).padStart(2, '0')}-${String(checkpoints[index]).padStart(3, '0')}ms.png`
        await page.screenshot({ path: path.join(dir, file), fullPage: true })
    }
}

function delay(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms))
}
