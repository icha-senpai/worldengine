import { defineComponent } from 'vue'
import { mount } from '@vue/test-utils'
import SessionShow from '@/Pages/Production/Sessions/Show.vue'

const ScaffoldShowPageStub = defineComponent({
    name: 'ScaffoldShowPage',
    props: {
        title: { type: String, default: '' },
        subtitle: { type: String, default: '' },
        backHref: { type: [String, Object], default: null },
        backLabel: { type: String, default: '' },
        editHref: { type: [String, Object], default: null },
        editPreserveScroll: { type: Boolean, default: false },
        editPreserveState: { type: Boolean, default: false },
        editDrawerOpen: { type: Boolean, default: false },
        editCloseHref: { type: [String, Object], default: null },
        destroyHref: { type: [String, Object], default: null },
        badge: { type: String, default: '' },
        sections: { type: Array, default: () => [] },
    },
    template: `
        <div data-test="show-page">
            <slot />
            <slot name="edit-drawer" />
        </div>
    `,
})

const EditSessionLogStub = defineComponent({
    name: 'EditSessionLog',
    props: {
        embedded: { type: Boolean, default: false },
        session: { type: Object, required: true },
        entities: { type: Array, default: () => [] },
        groupRelationships: { type: Array, default: () => [] },
        collections: { type: Array, default: () => [] },
        significanceLevels: { type: Array, default: () => [] },
    },
    template: '<div data-test="session-edit-drawer">{{ session.title }}</div>',
})

describe('Session show page', () => {
    beforeEach(() => {
        global.route = vi.fn((name, params) => ({ name, params }))
    })

    it('renders the embedded edit form through the scaffold edit drawer slot', () => {
        const session = {
            id: 42,
            title: 'Archive Sweep',
            session_date: '2026-06-24',
            external_tool: 'claude',
            focus_description: 'Investigating hydration',
            session_significance: 'major',
            decisions_made: null,
            changes_applied: null,
            open_threads: null,
            notes: null,
            entity_questions: [],
        }

        const editDrawer = {
            entities: [{ id: 1, name: 'Seraphine', entity_type: 'character' }],
            groupRelationships: [{ id: 2, name: 'Triad', relationship_type: 'alliance' }],
            collections: [{ id: 3, name: 'Core Cast', collection_type: 'custom' }],
            significanceLevels: ['minor', 'major'],
        }

        const wrapper = mount(SessionShow, {
            props: {
                session,
                editDrawer,
            },
            global: {
                config: {
                    globalProperties: {
                        route: global.route,
                    },
                },
                stubs: {
                    ScaffoldShowPage: ScaffoldShowPageStub,
                    EditSessionLog: EditSessionLogStub,
                },
            },
        })

        const scaffold = wrapper.getComponent(ScaffoldShowPageStub)
        const editForm = wrapper.getComponent(EditSessionLogStub)

        expect(scaffold.props('editDrawerOpen')).toBe(true)
        expect(scaffold.props('editCloseHref')).toEqual({
            name: 'session-logs.show',
            params: 42,
        })
        expect(editForm.props('embedded')).toBe(true)
        expect(editForm.props('session')).toEqual(session)
        expect(editForm.props('entities')).toEqual(editDrawer.entities)
        expect(editForm.props('groupRelationships')).toEqual(editDrawer.groupRelationships)
        expect(editForm.props('collections')).toEqual(editDrawer.collections)
        expect(editForm.props('significanceLevels')).toEqual(editDrawer.significanceLevels)
    })
})
