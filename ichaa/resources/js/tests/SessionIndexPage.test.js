import { defineComponent } from 'vue'
import { mount } from '@vue/test-utils'
import SessionIndex from '@/Pages/Production/Sessions/Index.vue'

const ScaffoldIndexPageStub = defineComponent({
    name: 'ScaffoldIndexPage',
    props: {
        title: { type: String, default: '' },
        count: { type: Number, default: 0 },
        items: { type: Array, default: () => [] },
    },
    template: '<div data-test="index-page"><slot name="toolbar" /><slot name="create-drawer" /></div>',
})

describe('Session index page', () => {
    beforeEach(() => {
        global.route = vi.fn((name, params) => ({ name, params }))
    })

    it('renders recent session stats above the scaffold index surface', () => {
        const wrapper = mount(SessionIndex, {
            props: {
                sessions: {
                    data: [
                        {
                            id: 42,
                            title: 'Archive Sweep',
                            session_date: '2026-06-24',
                            external_tool: 'claude',
                            session_significance: 'major',
                            focus_description: 'Investigating hydration',
                        },
                    ],
                    total: 1,
                },
                stats: {
                    session_count: 4,
                    major_count: 2,
                },
                filters: {},
                externalTools: ['claude'],
                significanceLevels: ['minor', 'major'],
            },
            global: {
                config: {
                    globalProperties: {
                        route: global.route,
                    },
                },
                stubs: {
                    ScaffoldIndexPage: ScaffoldIndexPageStub,
                },
            },
        })

        const scaffold = wrapper.getComponent(ScaffoldIndexPageStub)

        expect(wrapper.text()).toContain('Sessions / 30d')
        expect(wrapper.text()).toContain('4')
        expect(wrapper.text()).toContain('Major sessions')
        expect(wrapper.text()).toContain('2')
        expect(scaffold.props('count')).toBe(1)
        expect(scaffold.props('items')).toEqual([
            {
                id: 42,
                href: { name: 'session-logs.show', params: 42 },
                title: 'Archive Sweep',
                badges: [{ label: 'Tool', value: 'claude' }],
                meta: [
                    { label: 'Date', value: '2026-06-24' },
                    { label: 'Significance', value: 'major' },
                    { label: 'Focus', value: 'Investigating hydration' },
                ],
            },
        ])
    })
})
