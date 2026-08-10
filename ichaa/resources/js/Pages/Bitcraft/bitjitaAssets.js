const BITCRAFT_LOCAL_ASSET_BASE = '/bitcraft-assets'

const tierColors = {
    '-1': '#413A64',
    0: '#413A64',
    1: '#636A74',
    2: '#875F45',
    3: '#5C6F4D',
    4: '#49619C',
    5: '#814F87',
    6: '#983A44',
    7: '#947014',
    8: '#538484',
    9: '#464953',
    10: '#97AFBE',
}

const rarityColors = {
    default: '#636363',
    common: '#7b674a',
    uncommon: '#775142',
    rare: '#495774',
    epic: '#8b550e',
    legendary: '#0b5c7d',
    mythic: '#3c32dc',
}

const rarityNames = ['default', 'common', 'uncommon', 'rare', 'epic', 'legendary', 'mythic']

const colorStyle = (prefix, color) => ({
    [`--bitcraft-${prefix}-accent`]: color,
    [`--bitcraft-${prefix}-bg`]: `color-mix(in srgb, ${color} 22%, transparent)`,
    [`--bitcraft-${prefix}-border`]: color,
    [`--bitcraft-${prefix}-text`]: `color-mix(in srgb, ${color} 62%, white)`,
})

const tierColor = (tier) => {
    const tierNumber = Math.trunc(Number(tier))

    return tierColors[tierNumber] ?? null
}

export const hasBitcraftTier = (tier) => tier !== null
    && tier !== undefined
    && tier !== ''
    && Number.isFinite(Number(tier))

const rarityColor = (rarity) => {
    const rawRarity = typeof rarity === 'object' && rarity !== null
        ? rarity.tag
        : rarity
    const rarityKey = Number.isFinite(Number(rawRarity))
        ? rarityNames[Math.trunc(Number(rawRarity))]
        : String(rawRarity ?? '').trim().toLowerCase()

    return rarityColors[rarityKey] ?? null
}

export const bitcraftAssetUrl = (assetName, quantity = null) => {
    if (typeof assetName !== 'string') {
        return null
    }

    let path = assetName.trim().replaceAll('\\', '/')

    if (!path) {
        return null
    }

    if (/^https?:\/\//i.test(path)) {
        return path
    }

    if (path.startsWith('/')) {
        return path
    }

    if (/[\uE000-\uFFFF]/u.test(path)) {
        return null
    }

    const bracketMatch = path.match(/^([/\w-]+)(\[(,\d+)+])$/)

    if (bracketMatch) {
        const baseName = bracketMatch[1]
        const quantities = bracketMatch[2]
            .split(',')
            .map((value) => Number.parseInt(value, 10))
            .filter(Number.isFinite)
            .sort((left, right) => left - right)

        if (quantity && quantities.length) {
            const selectedQuantity = quantities.reduce((selected, value) => (
                Number(quantity) >= value ? value : selected
            ), quantities[0])
            path = `${baseName}${selectedQuantity}`
        } else {
            path = baseName
        }
    }

    path = path.replace(/^\/+/, '')

    if (!/\.(webp|png|jpe?g|gif|svg)$/i.test(path)) {
        path = `${path}.webp`
    }

    return `${BITCRAFT_LOCAL_ASSET_BASE}/sprites/${path.split('/').map(encodeURIComponent).join('/')}`
}

export const bitjitaAssetUrl = bitcraftAssetUrl

export const bitcraftTierStyle = (tier) => {
    const color = tierColor(tier)

    if (!color) {
        return {}
    }

    return colorStyle('tier', color)
}

export const bitcraftTierBadgeUrl = (tier) => {
    const tierNumber = Math.trunc(Number(tier))

    if (tierNumber < 1 || tierNumber > 10) {
        return null
    }

    return `${BITCRAFT_LOCAL_ASSET_BASE}/UI/Badges/badge-tier-number-${tierNumber}.webp`
}

export const bitcraftTierBadgeContainerUrl = () => `${BITCRAFT_LOCAL_ASSET_BASE}/UI/Badges/badge-tier-container.webp`

export const bitcraftTierBadgeStyle = (tier) => ({
    ...bitcraftTierStyle(tier),
    '--bitcraft-tier-badge-mask': `url('${bitcraftTierBadgeContainerUrl()}')`,
})

export const bitcraftRarityStyle = (rarity) => {
    const color = rarityColor(rarity)

    if (!color) {
        return {}
    }

    return colorStyle('rarity', color)
}

export const bitcraftItemFrameStyle = (tier, rarity) => {
    const color = tierColor(tier) ?? rarityColor(rarity)

    if (!color) {
        return {}
    }

    return colorStyle('item-frame', color)
}
