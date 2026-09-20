import { ref, watch } from 'vue'

export function usePersistedPanelState(storageKey, fallbackState, validators = {}) {
    const savedState = readSavedState(storageKey)
    const state = Object.fromEntries(Object.entries(fallbackState).map(([key, fallbackValue]) => [
        key,
        ref(validatedValue(savedState[key], fallbackValue, validators[key])),
    ]))

    watch(Object.values(state), () => {
        persistState(storageKey, state)
    }, { deep: true })

    return state
}

function readSavedState(storageKey) {
    if (typeof window === 'undefined') {
        return {}
    }

    try {
        return JSON.parse(window.localStorage.getItem(storageKey) ?? '{}') ?? {}
    } catch {
        return {}
    }
}

function validatedValue(value, fallbackValue, validator) {
    if (typeof validator === 'function') {
        return validator(value) ? value : fallbackValue
    }

    if (Array.isArray(validator)) {
        return validator.includes(value) ? value : fallbackValue
    }

    if (validator instanceof Set) {
        return validator.has(value) ? value : fallbackValue
    }

    return typeof value === typeof fallbackValue ? value : fallbackValue
}

function persistState(storageKey, state) {
    if (typeof window === 'undefined') {
        return
    }

    try {
        window.localStorage.setItem(storageKey, JSON.stringify(Object.fromEntries(Object.entries(state).map(([key, value]) => [
            key,
            value.value,
        ]))))
    } catch {
        return
    }
}
