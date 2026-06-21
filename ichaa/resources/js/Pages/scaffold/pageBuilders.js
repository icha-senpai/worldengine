import { formatLabel, summarizeValue } from '@/Components/scaffold/formatters'

export const asArray = (value) => {
    if (Array.isArray(value)) {
        return value
    }

    if (value?.data && Array.isArray(value.data)) {
        return value.data
    }

    return []
}

export const countRecords = (value) => {
    if (typeof value?.total === 'number') {
        return value.total
    }

    return asArray(value).length
}

export const buildMeta = (pairs) =>
    pairs
        .filter((pair) => pair?.value !== null && pair?.value !== undefined && pair.value !== '')
        .map((pair) => ({
            label: pair.label,
            value: summarizeValue(pair.value),
        }))

export const badge = (label, value) => ({
    label,
    value: summarizeValue(value),
})

export const sectionEntry = (label, value, extra = {}) => ({
    label,
    value,
    ...extra,
})

export { formatLabel, summarizeValue }
