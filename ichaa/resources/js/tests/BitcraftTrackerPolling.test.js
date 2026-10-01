import { createTrackerPoller, retryAfterSeconds } from '@/Pages/Bitcraft/trackerPolling'

describe('Bitcraft tracker polling', () => {
    let poller

    beforeEach(() => {
        vi.useFakeTimers()
        vi.spyOn(document, 'hidden', 'get').mockReturnValue(false)
    })

    afterEach(() => {
        poller?.stop()
        vi.useRealTimers()
    })

    it('skips hidden tabs and resumes when visible', async () => {
        const refresh = vi.fn()
        poller = createTrackerPoller(refresh, 10000)
        poller.start()
        vi.spyOn(document, 'hidden', 'get').mockReturnValue(true)
        await vi.advanceTimersByTimeAsync(30000)
        expect(refresh).not.toHaveBeenCalled()
        vi.spyOn(document, 'hidden', 'get').mockReturnValue(false)
        await vi.advanceTimersByTimeAsync(10000)
        expect(refresh).toHaveBeenCalledTimes(1)
    })

    it('does not overlap requests and respects the returned retry delay', async () => {
        let complete
        const refresh = vi.fn(() => new Promise((resolve) => { complete = resolve }))
        poller = createTrackerPoller(refresh, 10000)
        poller.start()
        await vi.advanceTimersByTimeAsync(30000)
        expect(refresh).toHaveBeenCalledTimes(1)
        complete(60)
        await vi.advanceTimersByTimeAsync(50000)
        expect(refresh).toHaveBeenCalledTimes(1)
        await vi.advanceTimersByTimeAsync(10000)
        expect(refresh).toHaveBeenCalledTimes(2)
    })

    it('applies initial cooldown and stops polling after cleanup', async () => {
        const refresh = vi.fn()
        poller = createTrackerPoller(refresh, 10000)
        poller.start(30)
        await vi.advanceTimersByTimeAsync(20000)
        expect(refresh).not.toHaveBeenCalled()
        await vi.advanceTimersByTimeAsync(10000)
        expect(refresh).toHaveBeenCalledTimes(1)
        poller.stop()
        await vi.advanceTimersByTimeAsync(30000)
        expect(refresh).toHaveBeenCalledTimes(1)
    })

    it('parses seconds and HTTP dates with a fallback for invalid headers', () => {
        const response = (value) => ({ headers: { get: () => value } })
        expect(retryAfterSeconds(response('90'))).toBe(90)
        expect(retryAfterSeconds(response(new Date(Date.now() + 120000).toUTCString()))).toBe(120)
        expect(retryAfterSeconds(response('invalid'))).toBe(60)
    })
})
