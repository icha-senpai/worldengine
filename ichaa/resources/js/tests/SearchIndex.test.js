import { mount } from '@vue/test-utils'
import SearchIndex from '@/Pages/Search/Index.vue'

const { routerGetMock } = vi.hoisted(() => ({
    routerGetMock: vi.fn(),
}))

vi.mock('@inertiajs/vue3', async () => {
    const actual = await vi.importActual('@inertiajs/vue3')

    return {
        ...actual,
        router: {
            get: routerGetMock,
        },
        Link: {
            template: '<a><slot /></a>',
        },
    }
})

describe('Search page', () => {
    beforeEach(() => {
        routerGetMock.mockReset()
        global.route = vi.fn((name, params) => ({ name, params }))
    })

    it('shows the empty state until a search term exists', () => {
        const wrapper = mountPage({
            term: '',
            results: {},
        })

        expect(wrapper.text()).toContain('Enter a term to start searching')
        expect(wrapper.text()).not.toContain('No matches.')
    })

    it('renders grouped results and submits the query through the inertia router', async () => {
        const wrapper = mountPage({
            term: 'Seraphine',
            results: {
                entities: [
                    {
                        id: 1,
                        name: 'Seraphine',
                        entity_type: 'character',
                        status: 'active',
                        notion_note_excerpt: 'Operates through the Grey Line under a false devotional shell.',
                    },
                ],
                documents: [],
                secrets: [
                    {
                        id: 2,
                        title: 'Puppet Cycle',
                        secret_type: 'plan',
                        exposure_risk: 'critical',
                        notion_note_excerpt: 'Notion draft ties the plan to Morbraith fallback rituals.',
                    },
                ],
                glossary: [
                    {
                        id: 3,
                        term: 'Grey Line',
                        usage_context: 'Temporal observation branch',
                        notion_note_excerpt: 'Used in Notion notes as shorthand for off-record observer cells.',
                    },
                ],
            },
        })

        expect(wrapper.text()).toContain('Entities')
        expect(wrapper.text()).toContain('Seraphine')
        expect(wrapper.text()).toContain('Character · Active')
        expect(wrapper.text()).toContain('Notion note')
        expect(wrapper.text()).toContain('false devotional shell')
        expect(wrapper.text()).toContain('Puppet Cycle')
        expect(wrapper.text()).toContain('Plan · Critical')
        expect(wrapper.text()).toContain('Grey Line')

        const input = wrapper.find('input[type="text"]')
        await input.setValue('Mirror Library')
        await wrapper.find('form').trigger('submit.prevent')

        expect(routerGetMock).toHaveBeenCalledWith(
            { name: 'search', params: undefined },
            { q: 'Mirror Library' },
            { preserveState: true, replace: true },
        )
    })
})

function mountPage(props) {
    return mount(SearchIndex, {
        props,
        global: {
            stubs: {
                AuthenticatedLayout: {
                    template: '<div><slot name="header" /><slot /></div>',
                },
            },
        },
    })
}
