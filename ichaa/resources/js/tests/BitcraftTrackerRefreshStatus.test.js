import { mount } from '@vue/test-utils'
import TrackerRefreshStatus from '@/Pages/Bitcraft/Components/TrackerRefreshStatus.vue'

describe('Bitcraft tracker refresh status', () => {
    it.each(['bitjuice', 'bitjita', 'relay'])('shows the actual fetched timestamp without advertising %s', (provider) => {
        const wrapper = mount(TrackerRefreshStatus, {
            props: {
                refresh: { provider, updatedAt: '2026-10-02T09:00:00Z' },
                sampledAt: '2026-10-02T09:05:00Z',
            },
        })
        expect(wrapper.text()).toContain('Updated')
        expect(wrapper.text()).not.toMatch(/bitjuice|bitjita|relay/i)
        expect(wrapper.get('time').attributes('datetime')).toBe('2026-10-02T09:00:00Z')
    })

    it('hides provider-only and fallback status for a successful refresh', () => {
        const wrapper = mount(TrackerRefreshStatus, {
            props: { refresh: { provider: 'bitjita', fallback: true, delayed: false } },
        })
        expect(wrapper.find('[role="status"]').exists()).toBe(false)
    })

    it('shows delayed cached data without changing its timestamp or naming the provider', () => {
        const wrapper = mount(TrackerRefreshStatus, {
            props: { refresh: { provider: 'relay', delayed: true }, sampledAt: '2026-10-02T09:00:00Z' },
        })
        expect(wrapper.text()).not.toMatch(/bitjuice|bitjita|relay/i)
        expect(wrapper.text()).toContain('Refresh delayed')
        expect(wrapper.get('time').attributes('datetime')).toBe('2026-10-02T09:00:00Z')
    })
})
