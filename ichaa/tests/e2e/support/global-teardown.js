import fs from 'node:fs'
import path from 'node:path'

function lockPaths(cwd) {
    const lockPath = path.join(cwd, 'storage', 'framework', 'testing', 'e2e-test-db.lock')

    return {
        lockPath,
        ownerPath: path.join(lockPath, 'owner.json'),
    }
}

function readLockOwner(ownerPath) {
    try {
        return JSON.parse(fs.readFileSync(ownerPath, 'utf8'))
    } catch {
        return null
    }
}

export default async function globalTeardown() {
    const cwd = path.resolve(process.cwd())
    const { lockPath, ownerPath } = lockPaths(cwd)
    const owner = readLockOwner(ownerPath)

    if (! owner || owner.token !== process.env.E2E_DATABASE_LOCK_TOKEN) {
        return
    }

    fs.rmSync(lockPath, { force: true, recursive: true })
    delete process.env.E2E_DATABASE_LOCK_TOKEN
}
