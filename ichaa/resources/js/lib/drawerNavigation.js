import { reactive, readonly } from 'vue'

const DRAWER_ROUTE_PATTERNS = [
    /\/create(?:\/)?(?:\?.*)?$/,
    /\/edit(?:\/)?(?:\?.*)?$/,
    /\/events\/\d+\/edit(?:\/)?(?:\?.*)?$/,
]

const state = reactive({
    pendingHref: '',
})

export function useDrawerNavigationState() {
    return readonly(state)
}

export function normalizeDrawerHref(href) {
    if (!href) {
        return ''
    }

    try {
        const url = new URL(href, window.location.origin)
        return `${url.pathname}${url.search}`
    } catch {
        return ''
    }
}

export function isDrawerRouteHref(href) {
    const target = normalizeDrawerHref(href)

    return target
        ? DRAWER_ROUTE_PATTERNS.some((pattern) => pattern.test(target))
        : false
}

export function beginDrawerNavigation({ href = '' } = {}) {
    state.pendingHref = normalizeDrawerHref(href)
}

export function matchesPendingDrawerHref(href) {
    const target = normalizeDrawerHref(href)

    return Boolean(target) && state.pendingHref === target
}

export function finishDrawerNavigation(href = '') {
    if (!href) {
        state.pendingHref = ''
        return
    }

    const target = normalizeDrawerHref(href)

    if (target && state.pendingHref === target) {
        state.pendingHref = ''
    }
}
