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
