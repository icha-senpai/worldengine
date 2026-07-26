export const bitcraftWidgetThemes = [
    {
        key: 'dataverse',
        label: 'Dataverse',
        accentColor: '#d4a44a',
        highlightColor: '#6fb08d',
        panelColor: '#110a18',
        textColor: '#fff6e6',
        mutedColor: '#b99456',
        borderColor: '#8c6531',
    },
    {
        key: 'harbor',
        label: 'Harbor',
        accentColor: '#38bdf8',
        highlightColor: '#22c55e',
        panelColor: '#082f49',
        textColor: '#f0f9ff',
        mutedColor: '#bae6fd',
        borderColor: '#0ea5e9',
    },
    {
        key: 'grove',
        label: 'Grove',
        accentColor: '#a3e635',
        highlightColor: '#2dd4bf',
        panelColor: '#1a2e05',
        textColor: '#f7fee7',
        mutedColor: '#bef264',
        borderColor: '#65a30d',
    },
    {
        key: 'ember',
        label: 'Ember',
        accentColor: '#fb923c',
        highlightColor: '#facc15',
        panelColor: '#431407',
        textColor: '#fff7ed',
        mutedColor: '#fdba74',
        borderColor: '#f97316',
    },
    {
        key: 'violet',
        label: 'Violet',
        accentColor: '#c084fc',
        highlightColor: '#f0abfc',
        panelColor: '#2e1065',
        textColor: '#faf5ff',
        mutedColor: '#ddd6fe',
        borderColor: '#a855f7',
    },
]

const DEFAULT_THEME = {
    ...bitcraftWidgetThemes[0],
    fontScale: 100,
    width: 450,
    radius: 18,
    panelOpacity: 96,
}

const colorPattern = /^#[0-9a-f]{6}$/i

const themeFor = (key) => bitcraftWidgetThemes.find((theme) => theme.key === key) ?? DEFAULT_THEME
const clampRgb = (value) => Math.min(255, Math.max(0, Math.round(value)))

const hexToRgb = (hex) => {
    const normalized = normalizeColor(hex, DEFAULT_THEME.accentColor).slice(1)
    const value = Number.parseInt(normalized, 16)

    return {
        r: (value >> 16) & 255,
        g: (value >> 8) & 255,
        b: value & 255,
    }
}

const rgbToHex = ({ r, g, b }) => `#${[r, g, b]
    .map((value) => clampRgb(value).toString(16).padStart(2, '0'))
    .join('')}`

const rgbValue = (color) => {
    const { r, g, b } = hexToRgb(color)

    return `${r} ${g} ${b}`
}

const mixColor = (base, overlay, overlayWeight = 0.5) => {
    const baseRgb = hexToRgb(base)
    const overlayRgb = hexToRgb(overlay)
    const baseWeight = 1 - overlayWeight

    return rgbToHex({
        r: baseRgb.r * baseWeight + overlayRgb.r * overlayWeight,
        g: baseRgb.g * baseWeight + overlayRgb.g * overlayWeight,
        b: baseRgb.b * baseWeight + overlayRgb.b * overlayWeight,
    })
}

const normalizeColor = (value, fallback) => {
    const color = String(value ?? '').trim()

    return colorPattern.test(color) ? color : fallback
}

const normalizeNumber = (value, fallback, min, max) => {
    const number = Number(value)

    if (!Number.isFinite(number)) {
        return fallback
    }

    return Math.min(max, Math.max(min, Math.round(number)))
}

export const normalizeWidgetTheme = (value = {}) => {
    const selectedTheme = String(value?.theme ?? DEFAULT_THEME.key)
    const theme = bitcraftWidgetThemes.some((option) => option.key === selectedTheme)
        ? selectedTheme
        : DEFAULT_THEME.key
    const preset = themeFor(theme)
    const colors = theme === DEFAULT_THEME.key ? preset : {
        accentColor: normalizeColor(value?.accentColor, preset.accentColor),
        highlightColor: normalizeColor(value?.highlightColor, preset.highlightColor),
        panelColor: normalizeColor(value?.panelColor, preset.panelColor),
        textColor: normalizeColor(value?.textColor, preset.textColor),
        mutedColor: normalizeColor(value?.mutedColor, preset.mutedColor),
        borderColor: normalizeColor(value?.borderColor, preset.borderColor),
    }

    return {
        theme,
        ...colors,
        fontScale: normalizeNumber(value?.fontScale, DEFAULT_THEME.fontScale, 80, 140),
        width: normalizeNumber(value?.width, DEFAULT_THEME.width, 280, 900),
        radius: normalizeNumber(value?.radius, DEFAULT_THEME.radius, 0, 32),
        panelOpacity: normalizeNumber(value?.panelOpacity, DEFAULT_THEME.panelOpacity, 20, 100),
    }
}

export const applyWidgetThemePreset = (target, key) => {
    const preset = themeFor(key)

    Object.assign(target, {
        theme: preset.key,
        accentColor: preset.accentColor,
        highlightColor: preset.highlightColor,
        panelColor: preset.panelColor,
        textColor: preset.textColor,
        mutedColor: preset.mutedColor,
        borderColor: preset.borderColor,
    })
}

export const widgetThemePayload = (theme) => {
    const normalized = normalizeWidgetTheme(theme)

    return {
        theme: normalized.theme,
        accentColor: normalized.accentColor,
        highlightColor: normalized.highlightColor,
        panelColor: normalized.panelColor,
        textColor: normalized.textColor,
        mutedColor: normalized.mutedColor,
        borderColor: normalized.borderColor,
        fontScale: normalized.fontScale,
        width: normalized.width,
        radius: normalized.radius,
        panelOpacity: normalized.panelOpacity,
    }
}

export const widgetThemeStyle = (theme) => {
    const normalized = normalizeWidgetTheme(theme)

    return {
        '--tracker-accent': normalized.accentColor,
        '--tracker-highlight': normalized.highlightColor,
        '--tracker-panel': normalized.panelColor,
        '--tracker-text': normalized.textColor,
        '--tracker-muted': normalized.mutedColor,
        '--tracker-border': normalized.borderColor,
        '--tracker-font-scale': normalized.fontScale / 100,
        '--tracker-width': `${normalized.width}px`,
        '--tracker-radius': `${normalized.radius}px`,
        '--tracker-panel-opacity': `${normalized.panelOpacity}%`,
    }
}

export const normalizeSiteTheme = (value) => {
    const theme = String(value ?? DEFAULT_THEME.key)

    return bitcraftWidgetThemes.some((option) => option.key === theme)
        ? theme
        : DEFAULT_THEME.key
}

export const siteThemeStyle = (themeKey) => {
    const theme = themeFor(normalizeSiteTheme(themeKey))
    const canvas = mixColor(theme.panelColor, '#000000', 0.74)
    const canvas2 = mixColor(theme.panelColor, '#000000', 0.48)
    const surface2 = mixColor(theme.panelColor, theme.accentColor, 0.08)
    const surface3 = mixColor(theme.panelColor, theme.accentColor, 0.18)
    const surface4 = mixColor(theme.panelColor, theme.accentColor, 0.3)
    const primary = '#f6f8fc'
    const primary2 = '#ffffff'
    const muted = '#c9d1dc'
    const muted2 = '#aab4c2'
    const muted3 = '#7f8998'
    const accent2 = mixColor(theme.accentColor, primary2, 0.34)
    const accent3 = mixColor(theme.accentColor, theme.panelColor, 0.45)
    const highlight2 = mixColor(theme.highlightColor, primary2, 0.26)
    const highlight3 = mixColor(theme.highlightColor, theme.panelColor, 0.45)
    const border = mixColor(theme.borderColor, theme.panelColor, 0.35)
    const border3 = mixColor(theme.borderColor, primary2, 0.28)
    const success2 = mixColor(theme.highlightColor, theme.panelColor, 0.35)
    const scrollbarTrack = `rgb(${rgbValue(canvas2)} / 0.72)`
    const scrollbarThumb = `rgb(${rgbValue(border)} / 0.82)`
    const scrollbarThumbHover = `rgb(${rgbValue(border3)} / 0.92)`

    return {
        '--bg-canvas': canvas,
        '--bg-canvas-rgb': rgbValue(canvas),
        '--bg-canvas-2': canvas2,
        '--bg-canvas-2-rgb': rgbValue(canvas2),
        '--bg-surface': theme.panelColor,
        '--bg-surface-rgb': rgbValue(theme.panelColor),
        '--bg-surface-2': surface2,
        '--bg-surface-2-rgb': rgbValue(surface2),
        '--bg-surface-3': surface3,
        '--bg-surface-3-rgb': rgbValue(surface3),
        '--bg-surface-4': surface4,
        '--bg-surface-4-rgb': rgbValue(surface4),
        '--text-primary': primary,
        '--text-primary-rgb': rgbValue(primary),
        '--text-primary-2': primary2,
        '--text-muted': muted,
        '--text-muted-2': muted2,
        '--text-muted-3': muted3,
        '--accent-cyan': theme.accentColor,
        '--accent-cyan-rgb': rgbValue(theme.accentColor),
        '--accent-cyan-2': accent2,
        '--accent-cyan-2-rgb': rgbValue(accent2),
        '--accent-cyan-3': accent3,
        '--accent-cyan-3-rgb': rgbValue(accent3),
        '--accent-pink': theme.highlightColor,
        '--accent-pink-rgb': rgbValue(theme.highlightColor),
        '--accent-pink-2': highlight2,
        '--accent-pink-2-rgb': rgbValue(highlight2),
        '--accent-pink-3': highlight3,
        '--accent-pink-3-rgb': rgbValue(highlight3),
        '--border-color': border,
        '--border-color-rgb': rgbValue(border),
        '--border-color-2': theme.borderColor,
        '--border-color-2-rgb': rgbValue(theme.borderColor),
        '--border-color-3': border3,
        '--border-color-3-rgb': rgbValue(border3),
        '--success': theme.highlightColor,
        '--success-rgb': rgbValue(theme.highlightColor),
        '--success-2': success2,
        '--overlay': `rgb(${rgbValue(canvas)} / 0.78)`,
        '--overlay-2': `rgb(${rgbValue(canvas2)} / 0.58)`,
        '--overlay-3': `rgb(${rgbValue(canvas)} / 0.88)`,
        '--scrollbar-track': scrollbarTrack,
        '--scrollbar-thumb': scrollbarThumb,
        '--scrollbar-thumb-hover': scrollbarThumbHover,
        '--scrollbar-thumb-border': `rgb(${rgbValue(canvas2)} / 0.88)`,
    }
}
