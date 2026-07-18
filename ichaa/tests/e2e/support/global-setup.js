import { execFileSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'
import { randomUUID } from 'node:crypto'

const LOCK_TIMEOUT_MS = 10 * 60 * 1000
const LOCK_RETRY_MS = 500

function sleep(ms) {
    return new Promise((resolve) => {
        setTimeout(resolve, ms)
    })
}

function lockPaths(cwd) {
    const testingPath = path.join(cwd, 'storage', 'framework', 'testing')

    return {
        testingPath,
        lockPath: path.join(testingPath, 'e2e-test-db.lock'),
        ownerPath: path.join(testingPath, 'e2e-test-db.lock', 'owner.json'),
    }
}

function readLockOwner(ownerPath) {
    try {
        return JSON.parse(fs.readFileSync(ownerPath, 'utf8'))
    } catch {
        return null
    }
}

async function acquireE2eDatabaseLock(cwd) {
    const { testingPath, lockPath, ownerPath } = lockPaths(cwd)
    const token = randomUUID()
    const startedAt = Date.now()
    let lastNoticeAt = 0

    fs.mkdirSync(testingPath, { recursive: true })

    while (Date.now() - startedAt < LOCK_TIMEOUT_MS) {
        try {
            fs.mkdirSync(lockPath)
            fs.writeFileSync(ownerPath, JSON.stringify({
                pid: process.pid,
                token,
                startedAt: new Date().toISOString(),
            }, null, 2))

            process.env.E2E_DATABASE_LOCK_TOKEN = token

            return
        } catch (error) {
            if (error.code !== 'EEXIST') {
                throw error
            }

            if (Date.now() - lastNoticeAt > 5000) {
                const owner = readLockOwner(ownerPath)
                const ownerLabel = owner?.pid ? `pid ${owner.pid}` : 'another process'
                console.log(`Waiting for E2E test database lock held by ${ownerLabel}...`)
                lastNoticeAt = Date.now()
            }

            await sleep(LOCK_RETRY_MS)
        }
    }

    throw new Error(`Timed out waiting for E2E test database lock at ${lockPath}`)
}

export default async function globalSetup() {
    const cwd = path.resolve(process.cwd())

    await acquireE2eDatabaseLock(cwd)

    try {
        execFileSync('php', ['artisan', 'migrate:fresh', '--env=testing', '--force'], {
            cwd,
            stdio: 'inherit',
        })

        execFileSync('php', ['tests/e2e/support/seed-e2e-user.php', '--env=testing'], {
            cwd,
            stdio: 'inherit',
        })
    } catch (error) {
        const { lockPath } = lockPaths(cwd)

        fs.rmSync(lockPath, { force: true, recursive: true })
        delete process.env.E2E_DATABASE_LOCK_TOKEN

        throw error
    }
}
