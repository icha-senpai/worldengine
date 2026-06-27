import { defineComponent } from 'vue'
import { mount } from '@vue/test-utils'
import RevisionIndex from '@/Pages/Admin/Revisions/Index.vue'
import RevisionShow from '@/Pages/Admin/Revisions/Show.vue'

const {
    confirmDialogMock,
    routerGetMock,
    routerPostMock,
    showErrorDialogMock,
} = vi.hoisted(() => ({
    confirmDialogMock: vi.fn(),
    routerGetMock: vi.fn(),
    routerPostMock: vi.fn(),
    showErrorDialogMock: vi.fn(),
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
            get: routerGetMock,
            post: routerPostMock,
        },
    }
})

vi.mock('@/lib/appDialog', () => ({
    confirmDialog: confirmDialogMock,
    showErrorDialog: showErrorDialogMock,
}))

const ScaffoldShowPageStub = defineComponent({
    name: 'ScaffoldShowPage',
    props: {
        title: { type: String, default: '' },
        sections: { type: Array, default: () => [] },
    },
    template: '<div data-test="show-page">{{ title }}<slot name="hero-actions" /><slot /></div>',
})

const ScaffoldIndexPageStub = defineComponent({
    name: 'ScaffoldIndexPage',
    props: {
        title: { type: String, default: '' },
        items: { type: Array, default: () => [] },
    },
    template: '<div data-test="index-page">{{ title }}<slot name="toolbar" /></div>',
})

describe('admin pages', () => {
    beforeEach(() => {
        confirmDialogMock.mockReset()
        confirmDialogMock.mockResolvedValue(true)
        routerGetMock.mockReset()
        routerPostMock.mockReset()
        showErrorDialogMock.mockReset()
        global.route = vi.fn((name, params) => ({ name, params }))
    })

    it('submits revision compare requests from the admin index page', async () => {
        const wrapper = mountPage(RevisionIndex, {
            revisions: { data: [{ id: 1 }], total: 1 },
            items: [{ id: 1, title: 'Revision One' }],
            filters: {},
            filterFields: [{ key: 'q', type: 'text', placeholder: 'Search revisions...' }],
            compareOptions: [
                { value: 4, label: '#4 · Update' },
                { value: 9, label: '#9 · Restore' },
            ],
        }, {
            ScaffoldIndexPage: ScaffoldIndexPageStub,
        })

        await wrapper.find('#left-revision').setValue('4')
        await wrapper.find('#right-revision').setValue('9')
        await wrapper.find('form.panel').trigger('submit')

        expect(routerGetMock).toHaveBeenCalledWith(
            { name: 'admin.revisions.compare', params: undefined },
            { left: 4, right: 9 },
        )
    })

    it('confirms and posts revision restores from the admin show page', async () => {
        const wrapper = mountPage(RevisionShow, {
            revision: {
                id: 12,
                resource_type: 'entities',
                resource_id: 7,
                action: 'update',
                actor_name: 'Sammi',
                created_at: '2026-06-27T12:00:00Z',
                record_link: { label: 'Seraphine', href: '/entities/7' },
                before_payload: { name: 'Old' },
                after_payload: { name: 'New' },
                diff_payload: { name: { before: 'Old', after: 'New' } },
            },
            restoreHref: '/admin/revisions/12/restore',
            compareHref: '/admin/revisions/compare',
            compareOptions: [{ value: 10, label: '#10 · Update' }],
        }, {
            ScaffoldShowPage: ScaffoldShowPageStub,
        })

        await wrapper.findAll('button')[1].trigger('click')

        expect(confirmDialogMock).toHaveBeenCalled()
        expect(routerPostMock).toHaveBeenCalledWith(
            '/admin/revisions/12/restore',
            {},
            expect.objectContaining({
                preserveScroll: true,
                onError: expect.any(Function),
            }),
        )
    })
})

function mountPage(component, props, extraStubs = {}) {
    return mount(component, {
        props,
        global: {
            config: {
                globalProperties: {
                    route: global.route,
                },
            },
            stubs: {
                AuthenticatedLayout: {
                    template: '<div><slot name="header" /><slot /></div>',
                },
                ScaffoldShowPage: ScaffoldShowPageStub,
                ScaffoldIndexPage: ScaffoldIndexPageStub,
                ...extraStubs,
            },
        },
    })
}
