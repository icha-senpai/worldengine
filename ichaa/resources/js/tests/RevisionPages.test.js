import { defineComponent } from 'vue'
import { mount } from '@vue/test-utils'
import RevisionShow from '@/Pages/System/Revisions/Show.vue'
import RevisionCompare from '@/Pages/System/Revisions/Compare.vue'

const {
    confirmDialogMock,
    routerPostMock,
    showErrorDialogMock,
} = vi.hoisted(() => ({
    confirmDialogMock: vi.fn(),
    routerPostMock: vi.fn(),
    showErrorDialogMock: vi.fn(),
}))

vi.mock('@inertiajs/vue3', async () => {
    const actual = await vi.importActual('@inertiajs/vue3')

    return {
        ...actual,
        router: {
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
    template: '<div><slot name="hero-actions" /><slot /></div>',
})

const AppButtonStub = defineComponent({
    name: 'AppButton',
    props: {
        href: { type: [String, Object], default: null },
        type: { type: String, default: 'button' },
    },
    emits: ['click'],
    template: `
        <button v-if="!href" :type="type" @click="$emit('click', $event)"><slot /></button>
        <a v-else :data-href="JSON.stringify(href)"><slot /></a>
    `,
})

describe('revision pages', () => {
    beforeEach(() => {
        confirmDialogMock.mockReset()
        confirmDialogMock.mockResolvedValue(true)
        routerPostMock.mockReset()
        showErrorDialogMock.mockReset()
        global.route = vi.fn((name, params) => ({ name, params }))
    })

    it('restores a revision from the detail page with the current revision id', async () => {
        const wrapper = mount(RevisionShow, {
            props: {
                revision: {
                    id: 42,
                    resource_type: 'entities',
                    resource_id: '9',
                    action: 'update',
                    actor: { name: 'Sammi' },
                    before_payload: { name: 'Before' },
                    after_payload: { name: 'After' },
                    created_at: '2026-06-27 12:00:00',
                },
                resourceLink: { label: 'Entity #9', href: { name: 'entities.show', params: 9 } },
                compareCandidates: [{ id: 41, label: '#41' }],
                currentRevisionId: 44,
                diffRows: [{ field: 'name', before: 'Before', after: 'After' }],
            },
            global: {
                mocks: {
                    route: global.route,
                },
                stubs: {
                    ScaffoldShowPage: ScaffoldShowPageStub,
                    AppButton: AppButtonStub,
                },
            },
        })

        await wrapper.get('button[type="button"]').trigger('click')

        expect(confirmDialogMock).toHaveBeenCalled()
        expect(routerPostMock).toHaveBeenCalledWith(
            { name: 'revisions.restore', params: 42 },
            { base_revision_id: 44 },
            expect.objectContaining({ preserveScroll: true }),
        )
    })

    it('restores the right revision from compare view', async () => {
        const wrapper = mount(RevisionCompare, {
            props: {
                left: { id: 11, resource_type: 'entities', resource_id: '9', action: 'update', actor: { name: 'Left' }, created_at: '2026-06-26' },
                right: { id: 12, resource_type: 'entities', resource_id: '9', action: 'update', actor: { name: 'Right' }, created_at: '2026-06-27' },
                resourceLink: { label: 'Entity #9', href: { name: 'entities.show', params: 9 } },
                currentRevisionId: 12,
                rows: [{ field: 'name', left: 'Stable', right: 'Changed', changed: true }],
            },
            global: {
                mocks: {
                    route: global.route,
                },
                stubs: {
                    ScaffoldShowPage: ScaffoldShowPageStub,
                    AppButton: AppButtonStub,
                },
            },
        })

        await wrapper.get('button[type="button"]').trigger('click')

        expect(confirmDialogMock).toHaveBeenCalled()
        expect(routerPostMock).toHaveBeenCalledWith(
            { name: 'revisions.restore', params: 12 },
            { base_revision_id: 12 },
            expect.objectContaining({ preserveScroll: true }),
        )
    })
})
