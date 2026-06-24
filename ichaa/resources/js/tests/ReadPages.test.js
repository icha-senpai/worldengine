import { defineComponent } from 'vue'
import { mount } from '@vue/test-utils'
import EntityShow from '@/Pages/Entities/Show.vue'
import RelationshipShow from '@/Pages/Relationships/Show.vue'
import CollectionShow from '@/Pages/Collections/Show.vue'
import CollectionsIndex from '@/Pages/Collections/Index.vue'
import GlossaryShow from '@/Pages/Glossary/Show.vue'
import DocumentShow from '@/Pages/Lore/Documents/Show.vue'
import CharacterStateShow from '@/Pages/Temporal/CharacterStates/Show.vue'
import ConcurrencyGroupShow from '@/Pages/Temporal/ConcurrencyGroups/Show.vue'
import TimelineShow from '@/Pages/Temporal/Timelines/Show.vue'
import SecretIndex from '@/Pages/Intelligence/Secrets/Index.vue'
import SecretShow from '@/Pages/Intelligence/Secrets/Show.vue'
import KnowledgeStateShow from '@/Pages/Intelligence/KnowledgeStates/Show.vue'
import PerceptionStateShow from '@/Pages/Intelligence/PerceptionStates/Show.vue'
import PipelineShow from '@/Pages/Production/Pipeline/Show.vue'
import LocationControlIndex from '@/Pages/World/LocationControl/Index.vue'
import PowerInteractionShow from '@/Pages/World/PowerInteractions/Show.vue'
import TravelRouteShow from '@/Pages/World/TravelRoutes/Show.vue'

const {
    confirmDialogMock,
    formInstances,
    routerDeleteMock,
    routerPostMock,
    showErrorDialogMock,
    useFormMock,
    usePageMock,
} = vi.hoisted(() => ({
    confirmDialogMock: vi.fn(),
    formInstances: [],
    routerDeleteMock: vi.fn(),
    routerPostMock: vi.fn(),
    showErrorDialogMock: vi.fn(),
    useFormMock: vi.fn(),
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
            post: routerPostMock,
        },
        useForm: useFormMock,
        usePage: usePageMock,
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
        badge: { type: String, default: '' },
        backHref: { type: [String, Object], default: null },
        editHref: { type: [String, Object], default: null },
    },
    template: '<div data-test="show-page">{{ title }}</div>',
})

const ScaffoldIndexPageStub = defineComponent({
    name: 'ScaffoldIndexPage',
    props: {
        title: { type: String, default: '' },
        count: { type: Number, default: 0 },
        countLabel: { type: String, default: '' },
        createHref: { type: [String, Object], default: null },
        createLabel: { type: String, default: '' },
        items: { type: Array, default: () => [] },
        emptyTitle: { type: String, default: '' },
        emptyCtaHref: { type: [String, Object], default: null },
        emptyCtaLabel: { type: String, default: '' },
    },
    template: '<div data-test="index-page">{{ title }}</div>',
})

const AppButtonStub = defineComponent({
    name: 'AppButton',
    inheritAttrs: false,
    props: {
        href: { type: [String, Object], default: '' },
        type: { type: String, default: 'button' },
        disabled: { type: Boolean, default: false },
        opensDrawer: { type: Boolean, default: false },
    },
    emits: ['click'],
    methods: {
        stringify(value) {
            return JSON.stringify(value)
        },
    },
    template: `
        <a
            v-if="href"
            v-bind="$attrs"
            :data-href="stringify(href)"
            :data-opens-drawer="opensDrawer ? 'true' : undefined"
            @click="$emit('click', $event)"
        ><slot /></a>
        <button
            v-else
            v-bind="$attrs"
            :type="type"
            :disabled="disabled"
            @click="$emit('click', $event)"
        ><slot /></button>
    `,
})

const DrawerLinkStub = defineComponent({
    name: 'DrawerLink',
    inheritAttrs: false,
    props: {
        href: { type: [String, Object], required: true },
        opensDrawer: { type: Boolean, default: false },
    },
    methods: {
        stringify(value) {
            return JSON.stringify(value)
        },
    },
    template: `
        <a
            v-bind="$attrs"
            :data-href="stringify(href)"
            :data-opens-drawer="opensDrawer ? 'true' : undefined"
        ><slot /></a>
    `,
})

describe('read pages', () => {
    beforeEach(() => {
        confirmDialogMock.mockReset()
        confirmDialogMock.mockResolvedValue(true)
        formInstances.length = 0
        routerDeleteMock.mockReset()
        routerPostMock.mockReset()
        showErrorDialogMock.mockReset()
        useFormMock.mockReset()
        usePageMock.mockReset()
        usePageMock.mockReturnValue({ url: '/entities/1?tab=aliases', props: {} })
        useFormMock.mockImplementation((initial) => {
            const form = {
                ...initial,
                processing: false,
                errors: {},
                reset: vi.fn(),
                clearErrors: vi.fn(),
                post: vi.fn(),
                put: vi.fn(),
            }

            formInstances.push(form)

            return form
        })

        global.route = vi.fn((name, params) => ({ name, params }))
        global.confirm = vi.fn(() => true)
    })

    it('renders the entity show page with route-selected alias content', () => {
        usePageMock.mockReturnValue({
            url: '/entities/1?tab=aliases',
            props: {
                notionNote: {
                    label: 'Notion Notes',
                    content: 'This is mirrored from the Notion page body.',
                },
            },
        })

        const wrapper = mountPage(EntityShow, {
            entity: {
                id: 1,
                name: 'Seraphine Vale',
                entity_type: 'character',
                status: 'active',
                summary: 'A central figure in the fracture.',
                source_universes: ['Harry Potter'],
                visibility: 'private',
                content_classification: 'restricted',
                completion_score: 55,
                has_attributes: true,
                has_relationships: true,
                has_timeline_entries: false,
                has_aliases: true,
                has_media: false,
                aliases: [
                    {
                        id: 10,
                        alias: 'Silent Heir',
                        alias_type: 'title',
                        context: 'Court usage',
                        era_start: 'Year 0',
                        era_end: null,
                        is_active: true,
                    },
                ],
                notes: [],
                questions: [],
            },
        })

        expect(wrapper.text()).toContain('Seraphine Vale')
        expect(wrapper.text()).toContain('Silent Heir')
        expect(wrapper.text()).toContain('Court usage')
        expect(wrapper.text()).toContain('Add Alias')
        expect(wrapper.text()).toContain('Notion Notes')
        expect(wrapper.text()).toContain('This is mirrored from the Notion page body.')
    })

    it('builds the relationship show sections with participant and state-link data', () => {
        const scaffold = mountScaffoldPage(RelationshipShow, {
            relationship: {
                id: 12,
                relationship_type: 'conflict',
                direction: 'one_way',
                perceived_type: 'alliance',
                true_type: 'betrayal',
                current_tension_charge: 'volatile',
                is_active: true,
                from_entity: { id: 3, name: 'Seraphine' },
                to_entity: { id: 4, name: 'Johnny' },
                state_relationships: [
                    { character_state: { id: 8, snapshot_label: 'Year 0' } },
                ],
            },
        })

        expect(scaffold.props('title')).toBe('Seraphine -> Johnny')
        expect(scaffold.props('badge')).toBe('Conflict')
        expect(findEntry(scaffold.props('sections'), 'Tension Charge').value).toBe('Volatile')
        expect(findEntry(scaffold.props('sections'), 'Character States').value).toEqual([
            {
                label: 'Year 0',
                href: { name: 'character-states.show', params: 8 },
            },
        ])
    })

    it('builds the collection show sections with rules and child collections', () => {
        const scaffold = mountScaffoldPage(CollectionShow, {
            collection: {
                id: 13,
                name: 'Character Roster',
                collection_type: 'character_roster',
                collection_mode: 'smart',
                completion_state: 'in_progress',
                visibility: 'private',
                content_classification: 'restricted',
                rules: [{ field: 'entity_type', operator: 'equals', value: 'character' }],
                entities: [
                    { id: 4, name: 'Seraphine', entity_type: 'character' },
                ],
                child_collections: [
                    { id: 6, name: 'Core Cast', collection_type: 'custom' },
                ],
            },
        })

        expect(scaffold.props('title')).toBe('Character Roster')
        expect(scaffold.props('badge')).toBe('Character Roster')
        expect(findEntry(scaffold.props('sections'), 'Mode', 'Overview').value).toBe('Smart')
        expect(findEntry(scaffold.props('sections'), 'Rules', 'Rules').value).toEqual([
            { field: 'entity_type', operator: 'equals', value: 'character' },
        ])
        expect(findEntry(scaffold.props('sections'), 'Child Collections', 'Children').value).toEqual([
            {
                label: 'Core Cast (Custom)',
                href: { name: 'collections.show', params: 6 },
            },
        ])
    })

    it('builds the collections index items with type, mode, and entity counts', () => {
        const scaffold = mountIndexScaffoldPage(CollectionsIndex, {
            collections: {
                data: [
                    {
                        id: 16,
                        name: 'Faction Threads',
                        collection_type: 'custom',
                        collection_mode: 'smart',
                        completion_state: 'complete',
                        visibility: 'private',
                        entities_count: 7,
                    },
                ],
                total: 1,
            },
        })

        expect(scaffold.props('title')).toBe('Collections')
        expect(scaffold.props('count')).toBe(1)
        expect(scaffold.props('items')).toEqual([
            {
                id: 16,
                href: { name: 'collections.show', params: 16 },
                title: 'Faction Threads',
                badges: [
                    { label: 'Type', value: 'Custom' },
                    { label: 'Mode', value: 'Smart' },
                ],
                meta: [
                    { label: 'State', value: 'Complete' },
                    { label: 'Visibility', value: 'Private' },
                ],
                stats: [{ label: 'Entities', value: 7 }],
            },
        ])
    })

    it('builds the document show sections with authorship and narrative payloads', () => {
        const scaffold = mountScaffoldPage(DocumentShow, {
            document: {
                id: 14,
                title: 'Mirror Concordance',
                document_type: 'research_notes',
                document_status: 'suppressed',
                document_authenticity: 'disputed',
                era_created: 'Cycle 12',
                official_author: { id: 5, name: 'Public Scribe' },
                true_author: { id: 6, name: 'Shadow Editor' },
                owner: { id: 7, name: 'Archive Circle' },
                official_narrative: { type: 'doc', content: [] },
                true_content: { type: 'doc', content: [{ type: 'paragraph' }] },
            },
        })

        expect(scaffold.props('title')).toBe('Mirror Concordance')
        expect(findEntry(scaffold.props('sections'), 'Official Author').value).toBe('Public Scribe')
        expect(findEntry(scaffold.props('sections'), 'True Author').value).toBe('Shadow Editor')
        expect(findEntry(scaffold.props('sections'), 'Content', 'True Content').value).toEqual({
            type: 'doc',
            content: [{ type: 'paragraph' }],
        })
    })

    it('builds the glossary show sections with classification and definition payloads', () => {
        const scaffold = mountScaffoldPage(GlossaryShow, {
            term: {
                id: 15,
                term: 'Grey Line',
                usage_context: 'meta',
                origin_universe: 'Harry Potter',
                era_introduced: 'Cycle 12',
                term_status: 'active',
                definition: { type: 'doc', content: [{ type: 'paragraph' }] },
            },
        })

        expect(scaffold.props('title')).toBe('Grey Line')
        expect(findEntry(scaffold.props('sections'), 'Origin Universe').value).toBe('Harry Potter')
        expect(findEntry(scaffold.props('sections'), 'Term Status').value).toBe('active')
        expect(findEntry(scaffold.props('sections'), 'Definition', 'Definition').value).toEqual({
            type: 'doc',
            content: [{ type: 'paragraph' }],
        })
    })

    it('builds the character-state show sections with psychology and relationship links', () => {
        const scaffold = mountScaffoldPage(CharacterStateShow, {
            state: {
                id: 18,
                snapshot_label: 'After the Fracture',
                au_date: 'Year 0',
                source_date: '1998',
                snapshot_significance: 'transformative',
                current_stability_level: 'broken',
                mask_integrity: 'shattered',
                timeline_position: 25,
                current_trauma_profile: 'Acute destabilization',
                active_psychological_patterns: 'Hypervigilance',
                core_wound: 'Abandonment',
                current_desire: 'Restore order',
                current_fear: 'Becoming the wound',
                shadow_self: 'Control everything',
                true_self: 'Needs safety',
                performed_self: 'Composed leader',
                entity: { id: 2, name: 'Seraphine' },
                era: { id: 3, name: 'Cycle 12' },
                state_relationships: [
                    { relationship: { id: 9, relationship_type: 'conflict' } },
                ],
            },
        })

        expect(scaffold.props('title')).toBe('After the Fracture')
        expect(findEntry(scaffold.props('sections'), 'Entity', 'Overview').value).toBe('Seraphine')
        expect(findEntry(scaffold.props('sections'), 'Timeline Position', 'Overview').value).toBe(25)
        expect(findEntry(scaffold.props('sections'), 'Core Wound', 'Psychology').value).toBe('Abandonment')
        expect(findEntry(scaffold.props('sections'), 'Relationship Links', 'Links').value).toEqual([
            {
                label: 'conflict #9',
                href: { name: 'relationships.show', params: 9 },
            },
        ])
    })

    it('renders the timeline show page with direct placement controls and removal actions', async () => {
        const wrapper = mountPage(TimelineShow, {
            timeline: {
                id: 21,
                name: 'Grey Line',
                status: 'active',
                summary: 'Tracks the fracture chronology.',
                visibility: 'private',
            },
            events: [
                {
                    id: 31,
                    entry_label: 'The Fracture',
                    au_date: 'Year 0',
                    timeline_position: 10,
                    event_significance: 'major',
                    concurrency_group: { name: 'Night of Falling' },
                    event_entity: { id: 11, name: 'Fracture Event' },
                },
            ],
            atemporal: [
                {
                    id: 32,
                    entry_label: 'The Library Watches',
                    source_date: 'Canon Week 2',
                    event_entity: { id: 12, name: 'Mirror Library' },
                },
            ],
            availableEvents: [
                { id: 44, name: 'Archive Fire', entity_type: 'event' },
            ],
            concurrencyGroups: [
                { id: 55, name: 'Night of Falling', au_date: 'Year 0' },
            ],
            eventSignificanceLevels: ['minor', 'major'],
        })
        const form = formInstances.at(-1)

        expect(wrapper.text()).toContain('Place Event')
        expect(wrapper.text()).toContain('Tracks the fracture chronology.')
        expect(wrapper.text()).toContain('The Fracture')
        expect(wrapper.text()).toContain('The Library Watches')
        expect(wrapper.text()).toContain('Archive Fire (#44 · Event)')
        expect(wrapper.get('[data-test="edit-entry-31"]').attributes('data-href')).toBe(JSON.stringify({
            name: 'timelines.events.edit',
            params: { timeline: 21, entry: 31 },
        }))
        expect(wrapper.get('[data-test="edit-entry-32"]').attributes('data-href')).toBe(JSON.stringify({
            name: 'timelines.events.edit',
            params: { timeline: 21, entry: 32 },
        }))

        await wrapper.find('#event_entity_id').setValue('44')
        await wrapper.find('#entry_label').setValue('Archive Fire on Grey Line')
        await wrapper.find('#au_date').setValue('Year 2000')
        await wrapper.find('[data-test="timeline-placement-form"]').trigger('submit')

        expect(form.post).toHaveBeenCalledWith(
            { name: 'timelines.events.place', params: { timeline: 21, event: 44 } },
            {
                preserveScroll: true,
                onSuccess: expect.any(Function),
            },
        )

        await wrapper.find('[data-test="remove-entry-31"]').trigger('click')

        expect(routerDeleteMock).toHaveBeenCalledWith(
            { name: 'timelines.events.remove', params: { timeline: 21, entry: 31 } },
            { preserveScroll: true },
        )
    })

    it('builds the concurrency-group show sections with timeline entry labels and links', () => {
        const scaffold = mountScaffoldPage(ConcurrencyGroupShow, {
            group: {
                id: 25,
                name: 'Night of Falling',
                au_date: 'Year 0',
                narrative_significance: 'pivotal',
                description: { type: 'doc', content: [{ type: 'paragraph' }] },
                timeline_entries: [
                    {
                        id: 44,
                        entry_label: 'Library Breach',
                        au_date: 'Year 0',
                        timeline: { name: 'Grey Line' },
                        event_entity: { id: 13, name: 'Library Breach Event' },
                    },
                ],
            },
        })

        expect(scaffold.props('title')).toBe('Night of Falling')
        expect(scaffold.props('badge')).toBe('pivotal')
        expect(findEntry(scaffold.props('sections'), 'Description', 'Overview').value).toEqual({
            type: 'doc',
            content: [{ type: 'paragraph' }],
        })
        expect(findEntry(scaffold.props('sections'), 'Entries', 'Timeline Entries').value).toEqual([
            {
                label: 'Library Breach · on Grey Line · Year 0',
                href: { name: 'entities.show', params: 13 },
            },
        ])
    })

    it('builds the secrets index items with risk and status metadata', () => {
        const scaffold = mountIndexScaffoldPage(SecretIndex, {
            secrets: {
                data: [
                    {
                        id: 51,
                        title: 'Puppet Cycle',
                        secret_type: 'plan',
                        exposure_risk: 'critical',
                        status: 'active',
                    },
                ],
                total: 1,
            },
        })

        expect(scaffold.props('title')).toBe('Secrets')
        expect(scaffold.props('count')).toBe(1)
        expect(scaffold.props('items')).toEqual([
            {
                id: 51,
                href: { name: 'secrets.show', params: 51 },
                title: 'Puppet Cycle',
                badges: [{ label: 'Type', value: 'plan' }],
                meta: [
                    { label: 'Risk', value: 'critical' },
                    { label: 'Status', value: 'active' },
                ],
            },
        ])
    })

    it('builds the secret show sections with linked entity lists', () => {
        const scaffold = mountScaffoldPage(SecretShow, {
            secret: {
                id: 41,
                title: 'Puppet Cycle',
                secret_type: 'plan',
                exposure_risk: 'critical',
                status: 'active',
                revelation_trigger: 'Johnny learns the pattern',
                secret_content: { type: 'doc', content: [] },
            },
            subjectEntities: [{ label: 'Seraphine (character)', href: '/entities/1' }],
            holderEntities: [{ label: 'Mirror Council (faction)', href: '/entities/2' }],
            knownByEntities: [{ label: 'Johnny (character)', href: '/entities/3' }],
        })

        expect(scaffold.props('title')).toBe('Puppet Cycle')
        expect(findEntry(scaffold.props('sections'), 'Exposure Risk').value).toBe('critical')
        expect(findEntry(scaffold.props('sections'), 'Subject Entities', 'Content').value).toEqual([
            { label: 'Seraphine (character)', href: '/entities/1' },
        ])
    })

    it('builds the knowledge-state show sections with participant links and assessment data', () => {
        const scaffold = mountScaffoldPage(KnowledgeStateShow, {
            state: {
                id: 55,
                knowledge_type: 'secret',
                accuracy: 'partial',
                current_belief_state: 'believed',
                acquired_through: 'observation',
                acquired_at_era: 'Cycle 12',
                knowledge_content: { type: 'doc', content: [{ type: 'paragraph' }] },
                knower: { id: 8, name: 'Johnny' },
                subject_entity: { id: 9, name: 'Seraphine' },
                subject_secret: { title: 'Puppet Cycle' },
                acquired_from: { id: 10, name: 'Mirror Council' },
            },
        })

        expect(scaffold.props('title')).toBe('Johnny Knowledge')
        expect(scaffold.props('badge')).toBe('Secret')
        expect(findEntry(scaffold.props('sections'), 'Knower', 'Participants').value).toBe('Johnny')
        expect(findEntry(scaffold.props('sections'), 'Subject Secret', 'Participants').value).toBe('Puppet Cycle')
        expect(findEntry(scaffold.props('sections'), 'Acquired Through', 'Assessment').value).toBe('Observation')
        expect(findEntry(scaffold.props('sections'), 'Knowledge Content', 'Content').value).toEqual({
            type: 'doc',
            content: [{ type: 'paragraph' }],
        })
    })

    it('builds the perception-state show sections with maintained-by entities and state payloads', () => {
        const scaffold = mountScaffoldPage(PerceptionStateShow, {
            state: {
                id: 57,
                subject_type: 'secret',
                subject_id: 12,
                divergence_level: 'severe',
                maintenance_method: 'ritual',
                maintenance_effort: 'high',
                revelation_risk: 'critical',
                true_state: { type: 'doc', content: [{ type: 'paragraph' }] },
                perceived_state: { type: 'doc', content: [] },
            },
            subjectDisplay: {
                label: 'Puppet Cycle',
                href: '/secrets/12',
            },
            maintainedByEntities: [
                { label: 'Mirror Council', href: '/entities/7' },
            ],
        })

        expect(scaffold.props('title')).toBe('secret perception gap')
        expect(findEntry(scaffold.props('sections'), 'Subject', 'Overview').value).toBe('Puppet Cycle')
        expect(findEntry(scaffold.props('sections'), 'Revelation Risk', 'Overview').value).toBe('critical')
        expect(findEntry(scaffold.props('sections'), 'Maintained By Entities', 'States').value).toEqual([
            { label: 'Mirror Council', href: '/entities/7' },
        ])
    })

    it('builds the power-interaction show sections with risk and observed instances', () => {
        const scaffold = mountScaffoldPage(PowerInteractionShow, {
            interaction: {
                id: 61,
                interaction_name: 'Storm and Null',
                knowledge_state: 'established',
                directionality: 'contextual',
                interaction_scale: 'regional',
                danger_rating: 'high',
                proximity_required: true,
                unresolved_flag: false,
                resolution_notes: { type: 'doc', content: [] },
                description: { type: 'doc', content: [{ type: 'paragraph' }] },
                effects: [{ effect_type: 'suppresses' }],
                system_a: { id: 14, name: 'Storm Binding' },
                system_b: { id: 15, name: 'Null Weave' },
                instances: [
                    { event_entity: { id: 16, name: 'Mirror Collapse' }, outcome_match: 'confirmed' },
                ],
            },
        })

        expect(scaffold.props('title')).toBe('Storm and Null')
        expect(scaffold.props('badge')).toBe('Established')
        expect(findEntry(scaffold.props('sections'), 'Danger Rating', 'Risk and Resolution').value).toBe('High')
        expect(findEntry(scaffold.props('sections'), 'Proximity Required', 'Risk and Resolution').value).toBe(true)
        expect(findEntry(scaffold.props('sections'), 'Effects', 'Effect Model').value).toEqual([
            { effect_type: 'suppresses' },
        ])
        expect(findEntry(scaffold.props('sections'), 'Instances', 'Observed Instances').value).toEqual([
            {
                label: 'Mirror Collapse (Confirmed)',
                href: { name: 'entities.show', params: 16 },
            },
        ])
    })

    it('builds the travel-route show sections with route links and serialized detail blocks', () => {
        const scaffold = mountScaffoldPage(TravelRouteShow, {
            routeRecord: {
                id: 71,
                route_type: 'planar',
                standard_duration: 'Two nights',
                bidirectional: true,
                is_active: true,
                method_variants: [{ method_name: 'Gatewalk' }],
                hazards: [{ hazard_type: 'storm' }],
                origin: { id: 21, name: 'Grey London' },
                destination: { id: 22, name: 'Mirror Library' },
                controlled_by: { id: 23, name: 'Night Council' },
            },
        })

        expect(scaffold.props('title')).toBe('Grey London -> Mirror Library')
        expect(scaffold.props('badge')).toBe('planar')
        expect(findEntry(scaffold.props('sections'), 'Controlled By', 'Route').value).toBe('Night Council')
        expect(findEntry(scaffold.props('sections'), 'Bidirectional', 'Route').value).toBe(true)
        expect(findEntry(scaffold.props('sections'), 'Method Variants', 'Details').value).toEqual([
            { method_name: 'Gatewalk' },
        ])
        expect(findEntry(scaffold.props('sections'), 'Hazards', 'Details').value).toEqual([
            { hazard_type: 'storm' },
        ])
    })

    it('builds the location-control index items with controller, type, and era metadata', () => {
        const scaffold = mountIndexScaffoldPage(LocationControlIndex, {
            records: [
                {
                    id: 81,
                    control_type: 'occupied',
                    resistance_level: 'active_conflict',
                    control_start_era: 'Cycle 2',
                    control_end_era: 'Cycle 3',
                    location: { name: 'Aster Province' },
                    controlling_entity: { name: 'New Accord' },
                },
            ],
        })

        expect(scaffold.props('title')).toBe('Location Control')
        expect(scaffold.props('count')).toBe(1)
        expect(scaffold.props('items')).toEqual([
            {
                id: 81,
                href: { name: 'location-control.edit', params: 81 },
                preserveScroll: true,
                preserveState: true,
                opensDrawer: true,
                title: 'Aster Province -> New Accord',
                badges: [{ label: 'Type', value: 'occupied' }],
                meta: [
                    { label: 'Resistance', value: 'active_conflict' },
                    { label: 'Start', value: 'Cycle 2' },
                    { label: 'End', value: 'Cycle 3' },
                ],
            },
        ])
    })

    it('renders the pipeline show page and wires advance and delete actions', async () => {
        const wrapper = mountPage(PipelineShow, {
            item: {
                id: 99,
                title: 'Year 0 Confrontation',
                pipeline_type: 'scene',
                pipeline_stage: 'concept',
                word_count: 1250,
                reading_time_minutes: 6,
                content: 'A key scene.',
                emotional_beat: 'shock',
                narrative_purpose: 'Break the status quo.',
                notes: 'Needs sharper final line.',
                visibility: 'private',
                content_classification: 'restricted',
                pov_character: { id: 1, name: 'Seraphine' },
                location: { id: 2, name: 'Mirror Library' },
                parent: { id: 88, title: 'Chapter 1' },
                children: [
                    {
                        id: 100,
                        title: 'Aftershock',
                        pipeline_type: 'note',
                        pipeline_stage: 'outlined',
                        word_count: 400,
                    },
                ],
            },
        })

        expect(wrapper.text()).toContain('Year 0 Confrontation')
        expect(wrapper.text()).toContain('Advance')
        expect(wrapper.text()).toContain('Needs sharper final line.')

        await clickButtonByText(wrapper, 'button', 'Advance →')
        await clickButtonByText(wrapper, 'button', 'Move to Trash')
        await Promise.resolve()

        expect(routerPostMock).toHaveBeenCalledWith({ name: 'pipeline.advance', params: 99 })
        expect(routerDeleteMock).toHaveBeenCalledWith(
            { name: 'pipeline.destroy', params: 99 },
            { onError: expect.any(Function) },
        )
    })
})

function mountScaffoldPage(component, props) {
    const wrapper = mountPage(component, props, {
        ScaffoldShowPage: ScaffoldShowPageStub,
    })

    return wrapper.getComponent(ScaffoldShowPageStub)
}

function mountIndexScaffoldPage(component, props) {
    const wrapper = mountPage(component, props, {
        ScaffoldIndexPage: ScaffoldIndexPageStub,
    })

    return wrapper.getComponent(ScaffoldIndexPageStub)
}

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
                AppButton: AppButtonStub,
                DrawerLink: DrawerLinkStub,
                ScaffoldShowPage: ScaffoldShowPageStub,
                ScaffoldIndexPage: ScaffoldIndexPageStub,
                ...extraStubs,
            },
        },
    })
}

function findEntry(sections, label, sectionTitle = null) {
    const sectionPool = sectionTitle
        ? sections.filter((section) => section.title === sectionTitle)
        : sections

    return sectionPool
        .flatMap((section) => section.entries)
        .find((entry) => entry.label === label)
}

async function clickButtonByText(wrapper, selector, text) {
    const button = wrapper.findAll(selector).find((candidate) => candidate.text() === text)

    expect(button).toBeTruthy()

    await button.trigger('click')
}
