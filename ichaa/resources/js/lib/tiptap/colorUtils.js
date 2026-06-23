export function loadSavedColors(storageKey, size = 16) {
    if (typeof window === 'undefined') {
        return new Array(size).fill('')
    }

    try {
        const parsed = JSON.parse(window.localStorage.getItem(storageKey) ?? '[]')

        if (!Array.isArray(parsed)) {
            return new Array(size).fill('')
        }

        return Array.from({ length: size }, (_, index) => normalizeOptionalHex(parsed[index]))
    } catch {
        return new Array(size).fill('')
    }
}

export function persistSavedColors(storageKey, colors) {
    if (typeof window === 'undefined') {
        return
    }

    window.localStorage.setItem(storageKey, JSON.stringify(colors))
}

export function normalizeOptionalHex(value) {
    if (!value) {
        return ''
    }

    return normalizeHex(value)
}

export function normalizeHex(value) {
    const source = String(value ?? '').trim().replace(/^#/, '')

    if (/^[0-9a-f]{3}$/i.test(source)) {
        return `#${source.split('').map((char) => char + char).join('').toUpperCase()}`
    }

    if (/^[0-9a-f]{6}$/i.test(source)) {
        return `#${source.toUpperCase()}`
    }

    return '#1E293B'
}

export function hexToRgbObject(value) {
    const hex = normalizeHex(value).slice(1)

    return {
        r: Number.parseInt(hex.slice(0, 2), 16),
        g: Number.parseInt(hex.slice(2, 4), 16),
        b: Number.parseInt(hex.slice(4, 6), 16),
    }
}

export function rgbToHex({ r, g, b }) {
    return `#${[r, g, b]
        .map((channel) => clampChannel(channel).toString(16).padStart(2, '0'))
        .join('')
        .toUpperCase()}`
}

export function rgbToHsl255({ r, g, b }) {
    const red = clampChannel(r) / 255
    const green = clampChannel(g) / 255
    const blue = clampChannel(b) / 255
    const max = Math.max(red, green, blue)
    const min = Math.min(red, green, blue)
    const delta = max - min
    const lightness = (max + min) / 2
    let hue = 0
    let saturation = 0

    if (delta !== 0) {
        saturation = delta / (1 - Math.abs(2 * lightness - 1))

        switch (max) {
            case red:
                hue = 60 * (((green - blue) / delta) % 6)
                break
            case green:
                hue = 60 * (((blue - red) / delta) + 2)
                break
            default:
                hue = 60 * (((red - green) / delta) + 4)
                break
        }
    }

    return {
        h: Math.round((hue + 360) % 360),
        s: Math.round(saturation * 255),
        l: Math.round(lightness * 255),
    }
}

export function rgbToHsv({ r, g, b }) {
    const red = clampChannel(r) / 255
    const green = clampChannel(g) / 255
    const blue = clampChannel(b) / 255
    const max = Math.max(red, green, blue)
    const min = Math.min(red, green, blue)
    const delta = max - min
    let hue = 0

    if (delta !== 0) {
        switch (max) {
            case red:
                hue = 60 * (((green - blue) / delta) % 6)
                break
            case green:
                hue = 60 * (((blue - red) / delta) + 2)
                break
            default:
                hue = 60 * (((red - green) / delta) + 4)
                break
        }
    }

    const saturation = max === 0 ? 0 : delta / max

    return {
        h: Math.round((hue + 360) % 360),
        s: Math.round(saturation * 100),
        v: Math.round(max * 100),
    }
}

export function hsvToHex({ h, s, v }) {
    const hue = clampHue(h)
    const saturation = clampPercent(s) / 100
    const value = clampPercent(v) / 100
    const chroma = value * saturation
    const segment = hue / 60
    const secondary = chroma * (1 - Math.abs((segment % 2) - 1))
    const match = value - chroma

    let red = 0
    let green = 0
    let blue = 0

    if (segment >= 0 && segment < 1) {
        red = chroma
        green = secondary
    } else if (segment < 2) {
        red = secondary
        green = chroma
    } else if (segment < 3) {
        green = chroma
        blue = secondary
    } else if (segment < 4) {
        green = secondary
        blue = chroma
    } else if (segment < 5) {
        red = secondary
        blue = chroma
    } else {
        red = chroma
        blue = secondary
    }

    return rgbToHex({
        r: Math.round((red + match) * 255),
        g: Math.round((green + match) * 255),
        b: Math.round((blue + match) * 255),
    })
}

export function hsl255ToHex({ h, s, l }) {
    const hue = clampHue(h)
    const saturation = clampChannel(s) / 255
    const lightness = clampChannel(l) / 255
    const chroma = (1 - Math.abs(2 * lightness - 1)) * saturation
    const segment = hue / 60
    const secondary = chroma * (1 - Math.abs((segment % 2) - 1))
    const match = lightness - chroma / 2

    let red = 0
    let green = 0
    let blue = 0

    if (segment >= 0 && segment < 1) {
        red = chroma
        green = secondary
    } else if (segment < 2) {
        red = secondary
        green = chroma
    } else if (segment < 3) {
        green = chroma
        blue = secondary
    } else if (segment < 4) {
        green = secondary
        blue = chroma
    } else if (segment < 5) {
        red = secondary
        blue = chroma
    } else {
        red = chroma
        blue = secondary
    }

    return rgbToHex({
        r: Math.round((red + match) * 255),
        g: Math.round((green + match) * 255),
        b: Math.round((blue + match) * 255),
    })
}

export function clampChannel(value) {
    return Math.max(0, Math.min(255, Number(value) || 0))
}

export function clampHue(value) {
    return Math.max(0, Math.min(360, Number(value) || 0))
}

export function clampPercent(value) {
    return Math.max(0, Math.min(100, Number(value) || 0))
}
