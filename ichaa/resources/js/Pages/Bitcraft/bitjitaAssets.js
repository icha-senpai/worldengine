const BITJITA_ASSET_ORIGIN = 'https://bitjita.com'

const generatedIconPrefix = 'GeneratedIcons/'

const tierColors = {
    1: '#636A74',
    2: '#875F45',
    3: '#5C6F4D',
    4: '#49619C',
    5: '#814F87',
    6: '#983A44',
    7: '#947014',
    8: '#538484',
    9: '#464953',
    10: '#97AFBF',
}

const rarityColors = {
    common: '#857051',
    uncommon: '#846052',
    rare: '#687CA2',
    epic: '#AD7513',
    legendary: '#106C86',
    mythic: '#374EDE',
}

const rarityNames = [null, 'common', 'uncommon', 'rare', 'epic', 'legendary', 'mythic']

const colorStyle = (prefix, color) => ({
    [`--bitcraft-${prefix}-accent`]: color,
    [`--bitcraft-${prefix}-bg`]: `color-mix(in srgb, ${color} 22%, transparent)`,
    [`--bitcraft-${prefix}-border`]: `color-mix(in srgb, ${color} 70%, transparent)`,
    [`--bitcraft-${prefix}-text`]: `color-mix(in srgb, ${color} 62%, white)`,
})

const tierColor = (tier) => {
    const tierNumber = Math.trunc(Number(tier))

    return tierColors[tierNumber] ?? null
}

const rarityColor = (rarity) => {
    const rarityKey = Number.isFinite(Number(rarity))
        ? rarityNames[Math.trunc(Number(rarity))]
        : String(rarity ?? '').trim().toLowerCase()

    return rarityColors[rarityKey] ?? null
}

export const bitjitaAssetUrl = (assetName) => {
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

    path = path.replace(/^\/+/, '')

    const generatedIconIndex = path.lastIndexOf(generatedIconPrefix)

    if (generatedIconIndex >= 0) {
        path = path.slice(generatedIconIndex)
    }

    if (!path.startsWith(generatedIconPrefix) || /[\[\]\uE000-\uFFFF]/u.test(path)) {
        return null
    }

    if (!/\.(webp|png|jpe?g|gif|svg)$/i.test(path)) {
        path = `${path}.webp`
    }

    return `${BITJITA_ASSET_ORIGIN}/${path.split('/').map(encodeURIComponent).join('/')}`
}

export const bitcraftTierStyle = (tier) => {
    const color = tierColor(tier)

    if (!color) {
        return {}
    }

    return colorStyle('tier', color)
}

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
