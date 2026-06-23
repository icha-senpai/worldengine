import { computed, defineComponent } from 'vue'
import { mount } from '@vue/test-utils'
import MediaReferencesIndex from '@/Pages/Identity/MediaReferences/Index.vue'
import MediaReferenceShow from '@/Pages/Identity/MediaReferences/Show.vue'

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
            delete: vi.fn(),
        },
    }
})

const ScaffoldIndexPageStub = defineComponent({
    name: 'ScaffoldIndexPage',
    props: {
        title: { type: String, default: '' },
        count: { type: Number, default: 0 },
        items: { type: Array, default: () => [] },
    },
    template: '<div data-test="index-page">{{ title }}</div>',
})

describe('media reference pages', () => {
    beforeEach(() => {
        global.route = vi.fn((name, params) => ({ name, params }))
        global.confirm = vi.fn(() => true)
    })

    it('builds the media index items with source and classification metadata', () => {
        const wrapper = mount(MediaReferencesIndex, {
            props: {
                media: {
                    data: [
                        {
                            id: 8,
                            title: 'Grey Line Sketch',
                            description: 'Primary visual note.',
                            media_type: 'image',
                            purpose: 'reference',
                            url: 'https://example.com/grey-line.png',
                            is_primary: true,
                            visibility: 'private',
                            content_classification: 'restricted',
                        },
                    ],
                    total: 1,
                },
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

        expect(scaffold.props('count')).toBe(1)
        expect(scaffold.props('items')).toEqual([
            {
                id: 8,
                href: { name: 'media-references.show', params: 8 },
                title: 'Grey Line Sketch',
                subtitle: 'Primary visual note.',
                badges: [
                    { label: 'Type', value: 'Image' },
                    { label: 'Purpose', value: 'Reference' },
                ],
                meta: [
                    { label: 'Source', value: 'External' },
                    { label: 'Primary', value: 'Yes' },
                    { label: 'Visibility', value: 'Private' },
                    { label: 'Classification', value: 'Restricted' },
                ],
            },
        ])
    })

    it('renders the media show page preview and attachment details', () => {
        const wrapper = mount(MediaReferenceShow, {
            props: {
                media: {
                    id: 12,
                    title: 'Mirror Archive Layout',
                    description: 'Layout reference for the lower stacks.',
                    media_type: 'image',
                    purpose: 'map',
                    preview_url: 'https://example.com/archive-layout.png',
                    source_kind: 'external',
                    attachment: {
                        type: 'entity',
                        label: 'Mirror Archive',
                        href: '/entities/5',
                    },
                    url: 'https://example.com/archive-layout.png',
                    file_path: null,
                    file_name: 'archive-layout.png',
                    mime_type: 'image/png',
                    width_px: 1200,
                    height_px: 800,
                    file_size_bytes: 4096,
                    sort_order: 2,
                    is_primary: true,
                    visibility: 'private',
                    content_classification: 'restricted',
                },
            },
            global: {
                stubs: {
                    AuthenticatedLayout: {
                        template: '<div><slot name="header" /><slot /></div>',
                    },
                    AppButton: {
                        props: ['href', 'variant', 'type'],
                        template: '<button><slot /></button>',
                    },
                },
                config: {
                    globalProperties: {
                        route: global.route,
                    },
                },
            },
        })

        expect(wrapper.text()).toContain('Mirror Archive Layout')
        expect(wrapper.text()).toContain('Layout reference for the lower stacks.')
        expect(wrapper.text()).toContain('Mirror Archive')
        expect(wrapper.text()).toContain('1200 × 800')
        expect(wrapper.text()).toContain('4,096 bytes')
        expect(wrapper.find('img').attributes('src')).toBe('https://example.com/archive-layout.png')
    })
})
