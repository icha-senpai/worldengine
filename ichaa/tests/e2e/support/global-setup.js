import { execFileSync } from 'node:child_process'
import path from 'node:path'

export default async function globalSetup() {
    const cwd = path.resolve(process.cwd())

    execFileSync('php', ['artisan', 'migrate:fresh', '--env=testing', '--force'], {
        cwd,
        stdio: 'inherit',
    })

    execFileSync('php', ['tests/e2e/support/seed-e2e-user.php', '--env=testing'], {
        cwd,
        stdio: 'inherit',
    })
}
