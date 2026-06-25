import { reactive } from 'vue'
import { mount } from '@vue/test-utils'
import ScaffoldFilterBar from '@/Components/scaffold/ScaffoldFilterBar.vue'

describe('ScaffoldFilterBar', () => {
    it('treats visibility fields as select inputs with shared visibility options', () => {
        const form = reactive({
            visibility: 'private',
        })

        const wrapper = mount(ScaffoldFilterBar, {
            props: {
                fields: [
                    { key: 'visibility', placeholder: 'All visibility' },
                ],
                form,
                hasActiveFilters: true,
                onApply: vi.fn(),
                onClear: vi.fn(),
            },
        })

        const select = wrapper.find('select')

        expect(select.exists()).toBe(true)
        expect(select.text()).toContain('Private')
        expect(select.text()).toContain('Author Only')
        expect(select.text()).toContain('Secret')
        expect(select.text()).toContain('Public Knowledge')
    })

    it('applies q searches live after a short debounce', async () => {
        vi.useFakeTimers()

        const form = reactive({
            q: '',
        })
        const onApply = vi.fn()

        const wrapper = mount(ScaffoldFilterBar, {
            props: {
                fields: [
                    { key: 'q', type: 'text', placeholder: 'Search...' },
                ],
                form,
                hasActiveFilters: false,
                onApply,
                onClear: vi.fn(),
            },
        })

        await wrapper.find('input').setValue('mirror')
        await vi.advanceTimersByTimeAsync(249)
        expect(onApply).not.toHaveBeenCalled()

        await vi.advanceTimersByTimeAsync(1)
        expect(onApply).toHaveBeenCalledTimes(1)

        vi.useRealTimers()
    })
})
