import fs from 'node:fs'
import path from 'node:path'

const pagesRoot = path.resolve(import.meta.dirname, '../Pages')

describe('scaffold json field declarations', () => {
    it('requires explicit jsonMode for every scaffold json field', () => {
        const files = collectSourceFiles(pagesRoot)
        const violations = []

        for (const file of files) {
            const lines = fs.readFileSync(file, 'utf8').split(/\r?\n/)

            lines.forEach((line, index) => {
                if (!/type:\s*['"]json['"]/.test(line)) {
                    return
                }

                const window = lines
                    .slice(index, Math.min(index + 8, lines.length))
                    .join('\n')

                if (/jsonMode:\s*['"]/.test(window)) {
                    return
                }

                violations.push(`${path.relative(pagesRoot, file)}:${index + 1}`)
            })
        }

        expect(violations).toEqual([])
    })
})

function collectSourceFiles(root) {
    const entries = fs.readdirSync(root, { withFileTypes: true })

    return entries.flatMap((entry) => {
        const fullPath = path.join(root, entry.name)

        if (entry.isDirectory()) {
            return collectSourceFiles(fullPath)
        }

        if (!/\.(vue|js)$/.test(entry.name)) {
            return []
        }

        return [fullPath]
    })
}
