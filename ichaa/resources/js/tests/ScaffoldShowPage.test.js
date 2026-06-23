import { flushPromises, mount } from '@vue/test-utils'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'

const {
    routerDeleteMock,
    usePageMock,
} = vi.hoisted(() => ({
    routerDeleteMock: vi.fn(),
    usePageMock: vi.fn(),
}))

vi.mock('@inertiajs/vue3', async () => {
    const actual = await vi.importActual('@inertiajs/vue3')

    return {
        ...actual,
        Link: {
            name: 'Link',
            props: ['href'],
            template: '<a :data-href="JSON.stringify(href)"><slot /></a>',
        },
        router: {
            delete: routerDeleteMock,
        },
        usePage: usePageMock,
    }
})

const mountPage = (sections) => mount(ScaffoldShowPage, {
    props: {
        title: 'Meta Entry',
        backHref: '/meta',
        backLabel: 'Meta',
        sections,
    },
    global: {
        stubs: {
            AuthenticatedLayout: {
                template: '<div><slot name="header" /><slot /></div>',
            },
            AppButton: {
                template: '<button><slot /></button>',
            },
            NotionNotePanel: true,
        },
    },
})

describe('ScaffoldShowPage', () => {
    beforeEach(() => {
        routerDeleteMock.mockReset()
        usePageMock.mockReset()
        usePageMock.mockReturnValue({ props: {} })
    })

    it('renders TipTap-style json documents as prose instead of raw json', async () => {
        const wrapper = mountPage([
            {
                title: 'Content',
                entries: [
                    {
                        label: 'Content',
                        kind: 'json',
                        value: {
                            type: 'doc',
                            content: [
                                {
                                    type: 'paragraph',
                                    content: [
                                        { type: 'text', text: 'Normal ' },
                                        { type: 'text', text: 'bold', marks: [{ type: 'bold' }] },
                                        { type: 'text', text: ' and ' },
                                        {
                                            type: 'text',
                                            text: 'link',
                                            marks: [
                                                {
                                                    type: 'link',
                                                    attrs: { href: 'https://example.com', target: null },
                                                },
                                            ],
                                        },
                                        { type: 'text', text: '.' },
                                    ],
                                },
                            ],
                        },
                    },
                ],
            },
        ])

        await vi.dynamicImportSettled()
        await flushPromises()

        expect(wrapper.find('.rich-document-value').exists()).toBe(true)
        expect(wrapper.find('.json-block').exists()).toBe(false)
        expect(wrapper.text()).toContain('Normal bold and link.')
        expect(wrapper.html()).toContain('<strong>bold</strong>')
        expect(wrapper.html()).toContain('href="https://example.com"')
    })

    it('keeps non-document json payloads in the raw json block', () => {
        const wrapper = mountPage([
            {
                title: 'Details',
                entries: [
                    {
                        label: 'Effects',
                        kind: 'json',
                        value: [{ effect_type: 'suppresses' }],
                    },
                ],
            },
        ])

        expect(wrapper.find('.json-block').exists()).toBe(true)
        expect(wrapper.find('.rich-document-value').exists()).toBe(false)
        expect(wrapper.text()).toContain('"effect_type": "suppresses"')
    })
})
