import fs from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const root = path.resolve(__dirname, '..')
const cdnBase = 'https://cdn.brico.app'
const publicAssetRoot = path.join(root, 'public', 'bitcraft-assets')

const args = new Set(process.argv.slice(2))
const dryRun = args.has('--dry-run')
const concurrency = Number.parseInt(process.env.BITCRAFT_ASSET_SYNC_CONCURRENCY ?? '16', 10)

const scanRoots = [
    path.join(root, 'storage', 'app', 'bitcraft', 'spacetime-static.json'),
    path.join(root, 'storage', 'app', 'bitcraft', 'spacetime-full-static.json'),
    path.join(root, 'resources', 'js'),
    path.join(root, 'app'),
    path.join(root, 'tests'),
]

const assetPaths = new Set([
    'UI/Badges/badge-tier-container.webp',
])

for (let tier = 1; tier <= 10; tier += 1) {
    assetPaths.add(`UI/Badges/badge-tier-number-${tier}.webp`)
}

const textAssetPattern = /(?:GeneratedIcons|Items|Cargo|UI)\/[A-Za-z0-9 _./-]+(?:\[(?:,\d+)+])?/g
const likelyAssetKeys = new Set([
    'iconAssetName',
    'icon_asset_name',
    'itemIconAssetName',
    'item_icon_asset_name',
    'cargoIconAssetName',
    'cargo_icon_asset_name',
    'overrideIconAssetName',
    'override_icon_asset_name',
])

const addAsset = (value) => {
    if (typeof value !== 'string') {
        return
    }

    const paths = normalizedSpritePaths(value)

    for (const assetPath of paths) {
        assetPaths.add(`sprites/${assetPath}`)
    }
}

const normalizedSpritePaths = (assetName) => {
    let assetPath = assetName.trim().replaceAll('\\', '/')

    if (!assetPath || /^https?:\/\//i.test(assetPath) || assetPath.startsWith('/')) {
        return []
    }

    if (/[\uE000-\uFFFF]/u.test(assetPath)) {
        return []
    }

    const bracketMatch = assetPath.match(/^([/\w -]+)(\[(,\d+)+])$/)
    const paths = []

    if (bracketMatch) {
        const baseName = bracketMatch[1]
        const quantities = bracketMatch[2]
            .split(',')
            .map((quantity) => Number.parseInt(quantity, 10))
            .filter(Number.isFinite)
            .sort((left, right) => left - right)

        paths.push(baseName)

        for (const quantity of quantities) {
            paths.push(`${baseName}${quantity}`)
        }
    } else {
        paths.push(assetPath)
    }

    return paths.map((candidate) => {
        const cleaned = candidate.replace(/^\/+/, '')

        return /\.(webp|png|jpe?g|gif|svg)$/i.test(cleaned) ? cleaned : `${cleaned}.webp`
    })
}

const scanValue = (value) => {
    if (Array.isArray(value)) {
        for (const item of value) {
            scanValue(item)
        }

        return
    }

    if (!value || typeof value !== 'object') {
        return
    }

    for (const [key, child] of Object.entries(value)) {
        if (likelyAssetKeys.has(key)) {
            addAsset(child)
        }

        scanValue(child)
    }
}

const scanText = (text) => {
    for (const match of text.matchAll(textAssetPattern)) {
        addAsset(match[0])
    }
}

const scanPath = async (target) => {
    let stat

    try {
        stat = await fs.stat(target)
    } catch {
        return
    }

    if (stat.isDirectory()) {
        const entries = await fs.readdir(target, { withFileTypes: true })

        for (const entry of entries) {
            if (entry.name === 'node_modules' || entry.name === 'vendor' || entry.name === 'public' || entry.name === 'storage') {
                continue
            }

            await scanPath(path.join(target, entry.name))
        }

        return
    }

    if (!stat.isFile()) {
        return
    }

    const extension = path.extname(target).toLowerCase()

    if (!['.json', '.js', '.vue', '.php', '.ts', '.tsx'].includes(extension)) {
        return
    }

    const text = await fs.readFile(target, 'utf8')

    if (extension === '.json') {
        try {
            scanValue(JSON.parse(text))
        } catch {
            scanText(text)
        }

        return
    }

    scanText(text)
}

const remoteUrl = (assetPath) => `${cdnBase}/${assetPath.split('/').map(encodeURIComponent).join('/')}`
const localPath = (assetPath) => path.join(publicAssetRoot, ...assetPath.split('/'))

const downloadAsset = async (assetPath) => {
    const destination = localPath(assetPath)

    try {
        await fs.access(destination)

        return 'kept'
    } catch {
        // Missing locally, fetch it below.
    }

    if (dryRun) {
        return 'missing'
    }

    let response

    try {
        response = await fetch(remoteUrl(assetPath))
    } catch (error) {
        return `failed:${error?.cause?.code ?? error?.code ?? 'fetch'}`
    }

    if (!response.ok) {
        return `failed:${response.status}`
    }

    await fs.mkdir(path.dirname(destination), { recursive: true })
    await fs.writeFile(destination, Buffer.from(await response.arrayBuffer()))

    return 'downloaded'
}

for (const scanRoot of scanRoots) {
    await scanPath(scanRoot)
}

const sortedAssets = [...assetPaths].sort((left, right) => left.localeCompare(right))
const counts = {
    total: sortedAssets.length,
    kept: 0,
    downloaded: 0,
    missing: 0,
    unavailable: 0,
    failed: 0,
}
const failures = []
const unavailable = []
let currentAssetIndex = 0

const syncNextAsset = async () => {
    while (currentAssetIndex < sortedAssets.length) {
        const assetPath = sortedAssets[currentAssetIndex]
        currentAssetIndex += 1

        const result = await downloadAsset(assetPath)

        if (result === 'kept' || result === 'downloaded' || result === 'missing') {
            counts[result] += 1
        } else if (result === 'failed:404') {
            counts.unavailable += 1
            unavailable.push(assetPath)
        } else {
            counts.failed += 1
            failures.push({ assetPath, result })
        }
    }
}

const workerCount = dryRun ? 1 : Math.max(1, Math.min(concurrency || 16, 32, sortedAssets.length))

await Promise.all(Array.from({ length: workerCount }, syncNextAsset))

const manifest = {
    generatedAt: new Date().toISOString(),
    source: cdnBase,
    dryRun,
    counts,
    assets: sortedAssets,
    mirroredAssets: sortedAssets.filter((assetPath) => !unavailable.includes(assetPath)),
    unavailable,
    failures,
}

if (!dryRun) {
    await fs.mkdir(publicAssetRoot, { recursive: true })
    await fs.writeFile(path.join(publicAssetRoot, 'manifest.json'), `${JSON.stringify(manifest, null, 2)}\n`)
}

console.log(JSON.stringify({
    ...counts,
    manifest: dryRun ? null : 'public/bitcraft-assets/manifest.json',
}, null, 2))

if (failures.length) {
    console.error(JSON.stringify(failures.slice(0, 20), null, 2))
    process.exitCode = 1
}
