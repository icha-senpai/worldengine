import { reactive, readonly } from 'vue'

export const PENDING_DRAWER_REVEAL_MS = 90

const state = reactive({
    pendingHref: '',
    pendingVisible: false,
})

let revealTimer = null

export function useDrawerNavigationState() {
    return readonly(state)
}

function clearRevealTimer() {
    if (revealTimer !== null) {
        window.clearTimeout(revealTimer)
        revealTimer = null
    }
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

export function beginDrawerNavigation({ href = '' } = {}) {
    clearRevealTimer()

    state.pendingHref = normalizeDrawerHref(href)
    state.pendingVisible = false

    if (!state.pendingHref) {
        return
    }

    const pendingHref = state.pendingHref

    revealTimer = window.setTimeout(() => {
        if (state.pendingHref === pendingHref) {
            state.pendingVisible = true
        }
    }, PENDING_DRAWER_REVEAL_MS)
}

export function matchesPendingDrawerHref(href) {
    const target = normalizeDrawerHref(href)

    return Boolean(target) && state.pendingVisible && state.pendingHref === target
}

export function finishDrawerNavigation(href = '') {
    clearRevealTimer()

    if (!href) {
        state.pendingHref = ''
        state.pendingVisible = false
        return
    }

    const target = normalizeDrawerHref(href)

    if (target && state.pendingHref === target) {
        state.pendingHref = ''
        state.pendingVisible = false
    }
}
