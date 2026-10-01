export const retryAfterSeconds = (response) => {
    const value = response.headers?.get('Retry-After')

    if (!value) return 60
    if (/^\d+$/.test(value)) return Math.max(1, Number(value))

    const date = Date.parse(value)
    return Number.isFinite(date) ? Math.max(1, Math.ceil((date - Date.now()) / 1000)) : 60
}

export const createTrackerPoller = (refresh, interval) => {
    let timer = null
    let inFlight = false
    let notBefore = 0

    const run = async () => {
        if (document.hidden || inFlight || Date.now() < notBefore) return

        inFlight = true
        try {
            const delay = await refresh()
            if (delay > 0) notBefore = Date.now() + delay * 1000
        } finally {
            inFlight = false
        }
    }

    return {
        start(delay = 0) {
            this.stop()
            notBefore = Date.now() + delay * 1000
            timer = window.setInterval(run, interval)
        },
        stop() {
            window.clearInterval(timer)
            timer = null
        },
    }
}
