import { defineComponent } from 'vue'
import { mount } from '@vue/test-utils'
import RelationshipCreate from '@/Pages/Relationships/Create.vue'
import RelationshipEdit from '@/Pages/Relationships/Edit.vue'
import GroupRelationshipCreate from '@/Pages/GroupRelationships/Create.vue'
import GroupRelationshipEdit from '@/Pages/GroupRelationships/Edit.vue'
import FactionMembershipCreate from '@/Pages/FactionMemberships/Create.vue'
import FactionMembershipEdit from '@/Pages/FactionMemberships/Edit.vue'
import CollectionCreate from '@/Pages/Collections/Create.vue'
import CollectionEdit from '@/Pages/Collections/Edit.vue'
import GlossaryCreate from '@/Pages/Glossary/Create.vue'
import GlossaryEdit from '@/Pages/Glossary/Edit.vue'
import DocumentCreate from '@/Pages/Lore/Documents/Create.vue'
import DocumentEdit from '@/Pages/Lore/Documents/Edit.vue'
import CanonReferenceCreate from '@/Pages/Lore/CanonReferences/Create.vue'
import CanonReferenceEdit from '@/Pages/Lore/CanonReferences/Edit.vue'
import CrossoverEntryPointCreate from '@/Pages/Lore/CrossoverEntryPoints/Create.vue'
import CrossoverEntryPointEdit from '@/Pages/Lore/CrossoverEntryPoints/Edit.vue'
import CharacterStateCreate from '@/Pages/Temporal/CharacterStates/Create.vue'
import CharacterStateEdit from '@/Pages/Temporal/CharacterStates/Edit.vue'
import ConcurrencyGroupCreate from '@/Pages/Temporal/ConcurrencyGroups/Create.vue'
import ConcurrencyGroupEdit from '@/Pages/Temporal/ConcurrencyGroups/Edit.vue'
import TimelineCreate from '@/Pages/Temporal/Timelines/Create.vue'
import TimelineEdit from '@/Pages/Temporal/Timelines/Edit.vue'
import TimelineEventEdit from '@/Pages/Temporal/Timelines/Events/Edit.vue'
import SecretCreate from '@/Pages/Intelligence/Secrets/Create.vue'
import SecretEdit from '@/Pages/Intelligence/Secrets/Edit.vue'
import KnowledgeStateCreate from '@/Pages/Intelligence/KnowledgeStates/Create.vue'
import KnowledgeStateEdit from '@/Pages/Intelligence/KnowledgeStates/Edit.vue'
import PerceptionStateCreate from '@/Pages/Intelligence/PerceptionStates/Create.vue'
import PerceptionStateEdit from '@/Pages/Intelligence/PerceptionStates/Edit.vue'
import MetaCreate from '@/Pages/Production/Meta/Create.vue'
import MetaEdit from '@/Pages/Production/Meta/Edit.vue'
import PowerInteractionCreate from '@/Pages/World/PowerInteractions/Create.vue'
import PowerInteractionEdit from '@/Pages/World/PowerInteractions/Edit.vue'
import LocationContainmentCreate from '@/Pages/World/LocationContainment/Create.vue'
import LocationContainmentEdit from '@/Pages/World/LocationContainment/Edit.vue'
import TravelRouteCreate from '@/Pages/World/TravelRoutes/Create.vue'
import TravelRouteEdit from '@/Pages/World/TravelRoutes/Edit.vue'
import LocationControlCreate from '@/Pages/World/LocationControl/Create.vue'
import LocationControlEdit from '@/Pages/World/LocationControl/Edit.vue'
import SessionCreate from '@/Pages/Production/Sessions/Create.vue'
import SessionEdit from '@/Pages/Production/Sessions/Edit.vue'

const { formInstances, useFormMock } = vi.hoisted(() => ({
    formInstances: [],
    useFormMock: vi.fn(),
}))

vi.mock('@inertiajs/vue3', async () => {
    const actual = await vi.importActual('@inertiajs/vue3')

    return {
        ...actual,
        useForm: useFormMock,
    }
})

const ScaffoldFormPageStub = defineComponent({
    name: 'ScaffoldFormPage',
    props: {
        title: { type: String, default: '' },
        backHref: { type: [String, Object], default: null },
        backLabel: { type: String, default: '' },
        cancelHref: { type: [String, Object], default: null },
        submitLabel: { type: String, default: '' },
        processingLabel: { type: String, default: '' },
        form: { type: Object, required: true },
        sections: { type: Array, default: () => [] },
        onSubmit: { type: Function, required: true },
    },
    template: '<div data-test="scaffold">{{ title }}</div>',
})

describe('domain scaffold forms', () => {
    beforeEach(() => {
        formInstances.length = 0
        useFormMock.mockReset()
        useFormMock.mockImplementation((initial) => {
            const form = {
                ...initial,
                errors: {},
                processing: false,
                post: vi.fn(),
                put: vi.fn(),
            }

            formInstances.push(form)

            return form
        })

        global.route = vi.fn((name, params) => ({ name, params }))
    })

    it('builds the relationship create form with a default direction and store route', async () => {
        const { form, scaffold } = mountPage(RelationshipCreate, {
            entities: [
                { id: 1, name: 'Seraphine', entity_type: 'character' },
                { id: 2, name: 'Johnny', entity_type: 'character' },
            ],
            relationshipTypes: ['power', 'conflict'],
            tensionCharges: ['neutral', 'volatile'],
        })

        expect(form.direction).toBe('one_way')
        expect(form.visibility).toBe('private')
        expect(form.content_classification).toBe('restricted')

        const fromField = findField(scaffold.props('sections'), 'from_entity_id')
        expect(fromField.options).toEqual([
            { value: 1, label: 'Seraphine (#1 · Character)' },
            { value: 2, label: 'Johnny (#2 · Character)' },
        ])

        await scaffold.props('onSubmit')()

        expect(form.post).toHaveBeenCalledWith({ name: 'relationships.store', params: undefined })
    })

    it('builds the relationship edit form and targets the update route', async () => {
        const { form, scaffold } = mountPage(RelationshipEdit, {
            relationship: {
                id: 18,
                relationship_type: 'conflict',
                direction: 'mutual_equal',
                current_tension_charge: 'complex',
                is_active: true,
                perspective_a: { fear: 'high' },
            },
            relationshipTypes: ['conflict'],
            tensionCharges: ['complex'],
        })

        expect(form.relationship_type).toBe('conflict')
        expect(form.direction).toBe('mutual_equal')
        expect(form.charge_change_reason).toBe('')

        await scaffold.props('onSubmit')()

        expect(form.put).toHaveBeenCalledWith({ name: 'relationships.update', params: 18 })
    })

    it('builds the group relationship create form and passes tension charge options through', async () => {
        const { form, scaffold } = mountPage(GroupRelationshipCreate, {
            tensionCharges: ['neutral', 'negative'],
        })

        const chargeField = findField(scaffold.props('sections'), 'current_tension_charge')
        expect(chargeField.options).toEqual(['neutral', 'negative'])
        expect(form.visibility).toBe('private')
        expect(form.content_classification).toBe('restricted')

        await scaffold.props('onSubmit')()

        expect(form.post).toHaveBeenCalledWith({ name: 'group-relationships.store', params: undefined })
    })

    it('builds the group relationship edit form and targets the update route', async () => {
        const { form, scaffold } = mountPage(GroupRelationshipEdit, {
            group: {
                id: 7,
                name: 'The Quiet Accord',
                relationship_type: 'alliance',
                current_tension_charge: 'volatile',
                is_active: true,
            },
            tensionCharges: ['volatile'],
        })

        expect(form.name).toBe('The Quiet Accord')
        expect(form.current_tension_charge).toBe('volatile')

        await scaffold.props('onSubmit')()

        expect(form.put).toHaveBeenCalledWith({ name: 'group-relationships.update', params: 7 })
    })

    it('builds the faction membership create form with seeded entity ids and store route', async () => {
        const { form, scaffold } = mountPage(FactionMembershipCreate, {
            factionEntities: [{ id: 4, name: 'Aster Court', entity_type: 'faction' }],
            entities: [{ id: 9, name: 'Neri Vale', entity_type: 'character' }],
            initialFactionEntityId: 4,
            initialMemberEntityId: 9,
        })

        expect(form.faction_entity_id).toBe(4)
        expect(form.member_entity_id).toBe(9)
        expect(form.public_membership_known).toBe(true)

        const factionField = findField(scaffold.props('sections'), 'faction_entity_id')
        expect(factionField.options).toEqual([
            { value: 4, label: 'Aster Court (#4 · Faction)' },
        ])

        await scaffold.props('onSubmit')()

        expect(form.post).toHaveBeenCalledWith({ name: 'faction-memberships.store', params: undefined })
    })

    it('builds the faction membership edit form with faction back-link context', async () => {
        const { form, scaffold } = mountPage(FactionMembershipEdit, {
            membership: {
                id: 12,
                faction: { id: 4, name: 'Aster Court' },
                rank_or_role: 'Archivist',
                membership_status: 'active',
                true_loyalty_entity_id: 6,
                is_undercover: true,
                public_membership_known: false,
            },
            entities: [{ id: 6, name: 'Hidden Chorus', entity_type: 'faction' }],
        })

        expect(scaffold.props('backHref')).toEqual({ name: 'entities.show', params: 4 })
        expect(scaffold.props('backLabel')).toBe('Aster Court')
        expect(form.true_loyalty_entity_id).toBe(6)

        await scaffold.props('onSubmit')()

        expect(form.put).toHaveBeenCalledWith({ name: 'faction-memberships.update', params: 12 })
    })

    it('builds the collection create form with parent collection options and store route', async () => {
        const { form, scaffold } = mountPage(CollectionCreate, {
            collections: [{ id: 3, name: 'Puppet Cycles', collection_type: 'custom' }],
            types: ['custom', 'smart'],
            modes: ['manual', 'smart'],
        })

        const parentField = findField(scaffold.props('sections'), 'parent_collection_id')
        expect(parentField.options).toEqual([
            { value: 3, label: 'Puppet Cycles (#3 · Custom)' },
        ])
        expect(form.visibility).toBe('private')
        expect(form.content_classification).toBe('restricted')

        await scaffold.props('onSubmit')()

        expect(form.post).toHaveBeenCalledWith({ name: 'collections.store', params: undefined })
    })

    it('builds the collection edit form and preserves rules for update', async () => {
        const rules = [{ field: 'entity_type', operator: 'equals', value: 'character' }]
        const { form, scaffold } = mountPage(CollectionEdit, {
            collection: {
                id: 5,
                name: 'Character Roster',
                collection_type: 'character_roster',
                collection_mode: 'smart',
                rules,
                completion_state: 'in_progress',
            },
            types: ['character_roster'],
            modes: ['smart'],
        })

        expect(form.rules).toEqual(rules)
        expect(form.completion_state).toBe('in_progress')

        await scaffold.props('onSubmit')()

        expect(form.put).toHaveBeenCalledWith({ name: 'collections.update', params: 5 })
    })

    it('builds the glossary create form and targets the store route', async () => {
        const { form, scaffold } = mountPage(GlossaryCreate)

        expect(form.definition).toBeNull()

        await scaffold.props('onSubmit')()

        expect(form.post).toHaveBeenCalledWith({ name: 'glossary.store', params: undefined })
    })

    it('builds the glossary edit form and targets the update route', async () => {
        const { form, scaffold } = mountPage(GlossaryEdit, {
            term: {
                id: 22,
                term: 'Grey Line',
                usage_context: 'meta',
                definition: { type: 'doc', content: [] },
                term_status: 'active',
            },
        })

        expect(form.term).toBe('Grey Line')
        expect(form.usage_context).toBe('meta')

        await scaffold.props('onSubmit')()

        expect(form.put).toHaveBeenCalledWith({ name: 'glossary.update', params: 22 })
    })

    it('builds the document create form with authorship options and store route', async () => {
        const { form, scaffold } = mountPage(DocumentCreate, {
            entities: [{ id: 8, name: 'Public Scribe', entity_type: 'character' }],
            documentTypes: ['research_notes'],
            documentStatuses: ['extant'],
            authenticityStates: ['disputed'],
        })

        const authorField = findField(scaffold.props('sections'), 'official_author_entity_id')
        expect(authorField.options).toEqual([
            { value: 8, label: 'Public Scribe (#8 · Character)' },
        ])

        await scaffold.props('onSubmit')()

        expect(form.post).toHaveBeenCalledWith({ name: 'documents.store', params: undefined })
    })

    it('builds the document edit form and targets the update route', async () => {
        const { form, scaffold } = mountPage(DocumentEdit, {
            document: {
                id: 14,
                title: 'Mirror Concordance',
                document_authenticity: 'disputed',
                document_status: 'suppressed',
                official_narrative: { type: 'doc', content: [] },
                true_content: { type: 'doc', content: [] },
                true_author_entity_id: 3,
                suppressed_by_entity_id: 5,
            },
            entities: [{ id: 3, name: 'Shadow Editor', entity_type: 'character' }],
            documentStatuses: ['suppressed'],
            authenticityStates: ['disputed'],
        })

        expect(form.title).toBe('Mirror Concordance')
        expect(form.suppressed_by_entity_id).toBe(5)

        await scaffold.props('onSubmit')()

        expect(form.put).toHaveBeenCalledWith({ name: 'documents.update', params: 14 })
    })

    it('builds the canon reference create form with parent options and store route', async () => {
        const { form, scaffold } = mountPage(CanonReferenceCreate, {
            parentReferences: [{ id: 11, title: 'Harry Potter Universe', level: 'universe', universe: 'Harry Potter' }],
            levels: ['universe', 'category'],
            categoryTypes: ['history'],
            elementTypes: ['character'],
            researchStatuses: ['developing'],
            universePriorities: ['primary'],
        })

        const parentField = findField(scaffold.props('sections'), 'parent_reference_id')
        expect(parentField.options).toEqual([
            { value: 11, label: 'Harry Potter Universe (#11 · Universe · Harry Potter)' },
        ])

        await scaffold.props('onSubmit')()

        expect(form.post).toHaveBeenCalledWith({ name: 'canon-references.store', params: undefined })
    })

    it('builds the canon reference edit form and targets the update route', async () => {
        const { form, scaffold } = mountPage(CanonReferenceEdit, {
            reference: {
                id: 19,
                title: 'WoT Politics',
                content: { type: 'doc', content: [] },
                research_status: 'solid',
                research_confidence: 'verified',
                canon_disputed: true,
                au_entity_id: 6,
            },
            entities: [{ id: 6, name: 'Pattern Echo', entity_type: 'concept' }],
            researchStatuses: ['solid'],
        })

        expect(form.canon_disputed).toBe(true)
        expect(form.au_entity_id).toBe(6)

        await scaffold.props('onSubmit')()

        expect(form.put).toHaveBeenCalledWith({ name: 'canon-references.update', params: 19 })
    })

    it('builds the crossover entry point create form and store route', async () => {
        const { form, scaffold } = mountPage(CrossoverEntryPointCreate, {
            statuses: ['theorized', 'established'],
        })

        const statusField = findField(scaffold.props('sections'), 'status')
        expect(statusField.options).toEqual(['theorized', 'established'])

        await scaffold.props('onSubmit')()

        expect(form.post).toHaveBeenCalledWith({ name: 'crossover-entry-points.store', params: undefined })
    })

    it('builds the crossover entry point edit form and targets the update route', async () => {
        const { form, scaffold } = mountPage(CrossoverEntryPointEdit, {
            entryPoint: {
                id: 27,
                entry_mechanism: { type: 'doc', content: [] },
                power_transition_rules: { type: 'doc', content: [] },
                return_rules: { type: 'doc', content: [] },
                status: 'documented',
            },
            statuses: ['documented'],
        })

        expect(form.status).toBe('documented')
        expect(form.return_rules).toEqual({ type: 'doc', content: [] })

        await scaffold.props('onSubmit')()

        expect(form.put).toHaveBeenCalledWith({ name: 'crossover-entry-points.update', params: 27 })
    })

    it('builds the character state create form with entity options and store route', async () => {
        const { form, scaffold } = mountPage(CharacterStateCreate, {
            entities: [{ id: 4, name: 'Seraphine', entity_type: 'character' }],
            timelineEntities: [{ id: 8, name: 'Grey Line', entity_type: 'timeline' }],
            eraEntities: [{ id: 11, name: 'Cycle 12', entity_type: 'era' }],
            stabilityLevels: ['stable', 'breaking'],
            maskIntegrityLevels: ['intact', 'shattered'],
            significanceLevels: ['minor', 'transformative'],
        })

        const entityField = findField(scaffold.props('sections'), 'entity_id')
        const timelineField = findField(scaffold.props('sections'), 'timeline_id')
        const eraField = findField(scaffold.props('sections'), 'era_entity_id')

        expect(entityField.options).toEqual([
            { value: 4, label: 'Seraphine (#4 · Character)' },
        ])
        expect(timelineField.options).toEqual([
            { value: 8, label: 'Grey Line (#8 · Timeline)' },
        ])
        expect(eraField.options).toEqual([
            { value: 11, label: 'Cycle 12 (#11 · Era)' },
        ])
        expect(form.timeline_position).toBe('')

        await scaffold.props('onSubmit')()

        expect(form.post).toHaveBeenCalledWith({ name: 'character-states.store', params: undefined })
    })

    it('builds the character state edit form and targets the update route', async () => {
        const { form, scaffold } = mountPage(CharacterStateEdit, {
            state: {
                id: 31,
                au_date: 'Year 0',
                source_date: '1998',
                snapshot_label: 'Aftermath',
                snapshot_significance: 'transformative',
                significance_reason: 'Everything breaks here.',
                current_stability_level: 'broken',
                mask_integrity: 'shattered',
                current_trauma_profile: 'Acute destabilization',
                active_psychological_patterns: 'Hypervigilance',
                core_wound: 'Abandonment',
                current_desire: 'Restore order',
                current_fear: 'Becoming the wound',
                current_power_tier_operating: 'cosmic',
                current_power_tier_influence: 'global',
                timeline_position: 25,
            },
            stabilityLevels: ['broken'],
            maskIntegrityLevels: ['shattered'],
            significanceLevels: ['transformative'],
        })

        expect(form.snapshot_label).toBe('Aftermath')
        expect(form.current_stability_level).toBe('broken')
        expect(form.timeline_position).toBe(25)

        await scaffold.props('onSubmit')()

        expect(form.put).toHaveBeenCalledWith({ name: 'character-states.update', params: 31 })
    })

    it('builds the concurrency group create form and store route', async () => {
        const { form, scaffold } = mountPage(ConcurrencyGroupCreate, {
            significanceLevels: ['minor', 'pivotal'],
        })

        const significanceField = findField(scaffold.props('sections'), 'narrative_significance')
        expect(significanceField.options).toEqual(['minor', 'pivotal'])
        expect(form.description).toBeNull()

        await scaffold.props('onSubmit')()

        expect(form.post).toHaveBeenCalledWith({ name: 'concurrency-groups.store', params: undefined })
    })

    it('builds the concurrency group edit form and targets the update route', async () => {
        const { form, scaffold } = mountPage(ConcurrencyGroupEdit, {
            group: {
                id: 44,
                name: 'Night of Falling',
                au_date: 'Year 0',
                description: { type: 'doc', content: [] },
                narrative_significance: 'pivotal',
            },
            significanceLevels: ['pivotal'],
        })

        expect(form.name).toBe('Night of Falling')
        expect(form.description).toEqual({ type: 'doc', content: [] })

        await scaffold.props('onSubmit')()

        expect(form.put).toHaveBeenCalledWith({ name: 'concurrency-groups.update', params: 44 })
    })

    it('builds the timeline create form with summary payload and store route', async () => {
        const { form, scaffold } = mountPage(TimelineCreate)

        const summaryField = findField(scaffold.props('sections'), 'summary')
        expect(summaryField.label).toBe('Summary')
        expect(form.summary).toBe('')
        expect(form.visibility).toBe('private')

        await scaffold.props('onSubmit')()

        expect(form.post).toHaveBeenCalledWith({ name: 'timelines.store', params: undefined })
    })

    it('builds the timeline edit form and targets the update route', async () => {
        const { form, scaffold } = mountPage(TimelineEdit, {
            timeline: {
                id: 48,
                name: 'Grey Line',
                summary: 'Tracks the fracture chronology.',
            },
        })

        expect(form.name).toBe('Grey Line')
        expect(form.summary).toBe('Tracks the fracture chronology.')

        await scaffold.props('onSubmit')()

        expect(form.put).toHaveBeenCalledWith({ name: 'timelines.update', params: 48 })
    })

    it('builds the timeline event edit form and targets the nested update route', async () => {
        const { form, scaffold } = mountPage(TimelineEventEdit, {
            timeline: {
                id: 48,
                name: 'Grey Line',
            },
            entry: {
                id: 91,
                entry_label: 'Archive Fire',
                au_date: 'Year 2000',
                source_date: '1998',
                timeline_position: 25,
                concurrency_group_id: 7,
                event_significance: 'major',
                is_atemporal: true,
                event_entity: { id: 12, name: 'Archive Fire Event' },
            },
            concurrencyGroups: [
                { id: 7, name: 'Night of Falling', au_date: 'Year 0' },
            ],
            eventSignificanceLevels: ['minor', 'major'],
        })

        const groupField = findField(scaffold.props('sections'), 'concurrency_group_id')
        expect(groupField.options).toEqual([
            { value: 7, label: 'Night of Falling · Year 0' },
        ])
        expect(form.entry_label).toBe('Archive Fire')
        expect(form.is_atemporal).toBe(true)

        await scaffold.props('onSubmit')()

        expect(form.put).toHaveBeenCalledWith({
            name: 'timelines.events.update',
            params: { timeline: 48, entry: 91 },
        })
    })

    it('builds the secret create form with entity multiselect options and store route', async () => {
        const { form, scaffold } = mountPage(SecretCreate, {
            entities: [{ id: 14, name: 'Mirror Council', entity_type: 'faction' }],
            secretTypes: ['plan'],
            exposureRisks: ['critical'],
        })

        const subjectField = findField(scaffold.props('sections'), 'subject_entity_ids')
        expect(subjectField.options).toEqual([
            { value: 14, label: 'Mirror Council (#14 · Faction)' },
        ])
        expect(form.secret_content).toBeNull()
        expect(form.status).toBe('')

        await scaffold.props('onSubmit')()

        expect(form.post).toHaveBeenCalledWith({ name: 'secrets.store', params: undefined })
    })

    it('builds the secret edit form and targets the update route', async () => {
        const { form, scaffold } = mountPage(SecretEdit, {
            secret: {
                id: 49,
                title: 'Puppet Cycle',
                secret_content: { type: 'doc', content: [] },
                exposure_risk: 'critical',
                revelation_trigger: 'Johnny sees the archive',
                status: 'active',
            },
            exposureRisks: ['critical'],
        })

        expect(form.title).toBe('Puppet Cycle')
        expect(form.revelation_trigger).toBe('Johnny sees the archive')

        await scaffold.props('onSubmit')()

        expect(form.put).toHaveBeenCalledWith({ name: 'secrets.update', params: 49 })
    })

    it('builds the knowledge-state create form with subject options and store route', async () => {
        const { form, scaffold } = mountPage(KnowledgeStateCreate, {
            entities: [{ id: 3, name: 'Johnny', entity_type: 'character' }],
            secrets: [{ id: 9, title: 'Puppet Cycle' }],
            relationships: [{ id: 12, relationship_type: 'conflict', from_entity: { name: 'Johnny' }, to_entity: { name: 'Seraphine' } }],
            groupRelationships: [{ id: 13, name: 'Night Council', relationship_type: 'alliance' }],
            eventEntries: [{ id: 17, entry_label: 'The Fracture', timeline: { name: 'Grey Line' }, event_entity: { name: 'Fracture Event' } }],
            knowledgeTypes: ['secret'],
            accuracyLevels: ['partial'],
            beliefStates: ['believed'],
            acquisitionMethods: ['observation'],
        })

        const knowerField = findField(scaffold.props('sections'), 'knower_entity_id')
        const eventField = findField(scaffold.props('sections'), 'subject_event_id')
        expect(knowerField.options).toEqual([
            { value: 3, label: 'Johnny (#3 · Character)' },
        ])
        expect(eventField.help).toBe('This links to a timeline entry, not a plain entity row.')
        expect(form.knowledge_content).toBeNull()

        await scaffold.props('onSubmit')()

        expect(form.post).toHaveBeenCalledWith({ name: 'knowledge-states.store', params: undefined })
    })

    it('builds the knowledge-state edit form and targets the update route', async () => {
        const { form, scaffold } = mountPage(KnowledgeStateEdit, {
            state: {
                id: 53,
                knowledge_content: { type: 'doc', content: [] },
                accuracy: 'partial',
                current_belief_state: 'believed',
            },
            beliefStates: ['believed'],
            accuracyLevels: ['partial'],
        })

        expect(form.accuracy).toBe('partial')
        expect(form.current_belief_state).toBe('believed')

        await scaffold.props('onSubmit')()

        expect(form.put).toHaveBeenCalledWith({ name: 'knowledge-states.update', params: 53 })
    })

    it('builds the perception-state create form with subject help and store route', async () => {
        const { form, scaffold } = mountPage(PerceptionStateCreate, {
            entities: [{ id: 5, name: 'Seraphine', entity_type: 'character' }],
            relationships: [{ id: 22, relationship_type: 'conflict', from_entity: { name: 'Seraphine' }, to_entity: { name: 'Johnny' } }],
            groupRelationships: [{ id: 23, name: 'Mirror Council', relationship_type: 'alliance' }],
            eventEntries: [{ id: 24, entry_label: 'Archive Fire', timeline: { name: 'Grey Line' }, event_entity: { name: 'Archive Fire Event' } }],
            documents: [{ id: 25, title: 'Mirror Concordance', document_type: 'research_notes' }],
            subjectTypes: ['relationship', 'event', 'document'],
            divergenceLevels: ['severe'],
            maintenanceMethods: ['ritual'],
            maintenanceEfforts: ['high'],
            revelationRisks: ['critical'],
        })

        const subjectTypeField = findField(scaffold.props('sections'), 'subject_type')
        const subjectField = findField(scaffold.props('sections'), 'subject_id')
        expect(subjectTypeField.options).toEqual(['relationship', 'event', 'document'])
        expect(subjectField.placeholder).toBe('Choose a subject type first...')
        expect(subjectField.help).toBe('')
        expect(form.maintained_by_entity_ids).toEqual([])

        await scaffold.props('onSubmit')()

        expect(form.post).toHaveBeenCalledWith({ name: 'perception-states.store', params: undefined })
    })

    it('builds the perception-state edit form and targets the update route', async () => {
        const { form, scaffold } = mountPage(PerceptionStateEdit, {
            state: {
                id: 58,
                true_state: { type: 'doc', content: [] },
                perceived_state: { type: 'doc', content: [] },
                divergence_level: 'severe',
                maintenance_effort: 'high',
                revelation_risk: 'critical',
            },
            maintenanceEfforts: ['high'],
            revelationRisks: ['critical'],
        })

        expect(form.divergence_level).toBe('severe')
        expect(form.revelation_risk).toBe('critical')

        await scaffold.props('onSubmit')()

        expect(form.put).toHaveBeenCalledWith({ name: 'perception-states.update', params: 58 })
    })

    it('builds the power interaction create form with entity options and store route', async () => {
        const { form, scaffold } = mountPage(PowerInteractionCreate, {
            entities: [
                { id: 2, name: 'Storm Binding', entity_type: 'magic_system' },
                { id: 5, name: 'Null Weave', entity_type: 'power_system' },
            ],
            effectTypes: ['suppresses', 'amplifies'],
            scaleTypes: ['local', 'cosmic'],
            dangerRatings: ['moderate', 'existential_risk'],
            knowledgeStates: ['established', 'unknown'],
            directionalityTypes: ['symmetrical', 'contextual'],
        })

        const systemField = findField(scaffold.props('sections'), 'system_a_entity_id')
        const effectField = findField(scaffold.props('sections'), 'effects')

        expect(systemField.options).toEqual([
            { value: 2, label: 'Storm Binding (#2 · magic_system)' },
            { value: 5, label: 'Null Weave (#5 · power_system)' },
        ])
        expect(effectField.help).toBe('Common effect types: suppresses, amplifies')
        expect(form.proximity_required).toBe(false)

        await scaffold.props('onSubmit')()

        expect(form.post).toHaveBeenCalledWith({ name: 'power-interactions.store', params: undefined })
    })

    it('builds the meta create form with default access values and entity options', async () => {
        const { form, scaffold } = mountPage(MetaCreate, {
            entities: [{ id: 12, name: 'Mirror Library', entity_type: 'location' }],
            categories: ['story'],
            noteTypes: ['active_task'],
            priorities: ['blocking'],
            actionStatuses: ['pending'],
            symbolScopes: ['local'],
        })

        const entityField = findField(scaffold.props('sections'), 'symbol_origin_entity_id')
        expect(entityField.options).toEqual([
            { value: 12, label: 'Mirror Library (#12 · Location)' },
        ])
        expect(form.visibility).toBe('private')
        expect(form.content_classification).toBe('restricted')

        await scaffold.props('onSubmit')()

        expect(form.post).toHaveBeenCalledWith({ name: 'meta.store', params: undefined })
    })

    it('builds the meta edit form and targets the update route', async () => {
        const { form, scaffold } = mountPage(MetaEdit, {
            note: {
                id: 63,
                title: 'Resolve archive thread',
                category: 'story',
                meta_note_type: 'active_task',
                content: { type: 'doc', content: [] },
                priority: 'blocking',
                action_status: 'pending',
                resolution_notes: { type: 'doc', content: [] },
                resolved_at: '2026-06-21 10:15:00',
                sense_sight: 'Silver light',
                emotional_register: 'Foreboding',
            },
            categories: ['story'],
            noteTypes: ['active_task'],
            priorities: ['blocking'],
            actionStatuses: ['pending'],
        })

        expect(form.title).toBe('Resolve archive thread')
        expect(form.resolved_at).toBe('2026-06-21 10:15:00')
        expect(form.emotional_register).toBe('Foreboding')

        await scaffold.props('onSubmit')()

        expect(form.put).toHaveBeenCalledWith({ name: 'meta.update', params: 63 })
    })

    it('builds the power interaction edit form and targets the update route', async () => {
        const { form, scaffold } = mountPage(PowerInteractionEdit, {
            interaction: {
                id: 52,
                interaction_name: 'Chaotic Pairing',
                description: { type: 'doc', content: [] },
                effects: [{ effect_type: 'suppresses' }],
                knowledge_state: 'unknown',
                danger_rating: 'existential_risk',
                unresolved_flag: true,
            },
            dangerRatings: ['existential_risk'],
            knowledgeStates: ['unknown'],
        })

        expect(form.interaction_name).toBe('Chaotic Pairing')
        expect(form.unresolved_flag).toBe(true)

        await scaffold.props('onSubmit')()

        expect(form.put).toHaveBeenCalledWith({ name: 'power-interactions.update', params: 52 })
    })

    it('builds the location containment create form and store route', async () => {
        const { form, scaffold } = mountPage(LocationContainmentCreate, {
            locationEntities: [{ id: 7, name: 'Mirror Library', entity_type: 'location' }],
            containmentTypes: ['physical', 'dimensional'],
        })

        const containmentField = findField(scaffold.props('sections'), 'containment_type')
        expect(containmentField.options).toEqual(['physical', 'dimensional'])
        expect(form.era_start).toBe('')

        await scaffold.props('onSubmit')()

        expect(form.post).toHaveBeenCalledWith({ name: 'location-containment.store', params: undefined })
    })

    it('builds the location containment edit form with contextual help and update route', async () => {
        const { form, scaffold } = mountPage(LocationContainmentEdit, {
            containment: {
                id: 61,
                era_end: 'Cycle 3',
                is_active: false,
                containment_type: 'dimensional',
                child_location: { name: 'Mirror Library' },
                parent_location: { name: 'Grey London' },
            },
        })

        const eraEndField = findField(scaffold.props('sections'), 'era_end')
        expect(eraEndField.help).toBe('Mirror Library -> Grey London (dimensional)')
        expect(form.is_active).toBe(false)

        await scaffold.props('onSubmit')()

        expect(form.put).toHaveBeenCalledWith({ name: 'location-containment.update', params: 61 })
    })

    it('builds the travel route create form and store route', async () => {
        const { form, scaffold } = mountPage(TravelRouteCreate, {
            locationEntities: [
                { id: 12, name: 'Grey London', entity_type: 'location' },
                { id: 14, name: 'Mirror Library', entity_type: 'location' },
            ],
            routeTypes: ['planar', 'temporal'],
        })

        const routeTypeField = findField(scaffold.props('sections'), 'route_type')
        expect(routeTypeField.options).toEqual(['planar', 'temporal'])
        expect(form.bidirectional).toBe(false)

        await scaffold.props('onSubmit')()

        expect(form.post).toHaveBeenCalledWith({ name: 'travel-routes.store', params: undefined })
    })

    it('builds the travel route edit form and targets the update route', async () => {
        const { form, scaffold } = mountPage(TravelRouteEdit, {
            routeRecord: {
                id: 70,
                standard_duration: 'Two nights',
                method_variants: [{ method_name: 'Gatewalk' }],
                hazards: [{ hazard_type: 'storm' }],
                is_active: true,
            },
        })

        expect(form.standard_duration).toBe('Two nights')
        expect(form.hazards).toEqual([{ hazard_type: 'storm' }])

        await scaffold.props('onSubmit')()

        expect(form.put).toHaveBeenCalledWith({ name: 'travel-routes.update', params: 70 })
    })

    it('builds the location control create form and store route', async () => {
        const { form, scaffold } = mountPage(LocationControlCreate, {
            locationEntities: [{ id: 21, name: 'Aster Province', entity_type: 'location' }],
            entities: [{ id: 22, name: 'New Accord', entity_type: 'faction' }],
            controlTypes: ['occupied', 'sovereign'],
            resistanceLevels: ['minor', 'active_conflict'],
        })

        const controllerField = findField(scaffold.props('sections'), 'controlling_entity_id')
        expect(controllerField.options).toEqual([
            { value: 22, label: 'New Accord (#22 · Faction)' },
        ])
        expect(form.control_start_era).toBe('')

        await scaffold.props('onSubmit')()

        expect(form.post).toHaveBeenCalledWith({ name: 'location-control.store', params: undefined })
    })

    it('builds the location control edit form and targets the update route', async () => {
        const { form, scaffold } = mountPage(LocationControlEdit, {
            record: {
                id: 83,
                resistance_level: 'active_conflict',
                control_end_era: 'Cycle 3',
                how_control_ended: { type: 'doc', content: [] },
                control_type: 'occupied',
                location: { name: 'Aster Province' },
                controlling_entity: { name: 'New Accord' },
            },
            resistanceLevels: ['active_conflict'],
        })

        const resistanceField = findField(scaffold.props('sections'), 'resistance_level')
        expect(resistanceField.help).toBe('Aster Province -> New Accord (occupied)')
        expect(form.control_end_era).toBe('Cycle 3')

        await scaffold.props('onSubmit')()

        expect(form.put).toHaveBeenCalledWith({ name: 'location-control.update', params: 83 })
    })

    it('builds the session log create form with focus options and store route', async () => {
        const { form, scaffold } = mountPage(SessionCreate, {
            entities: [{ id: 31, name: 'Seraphine', entity_type: 'character' }],
            groupRelationships: [{ id: 32, name: 'Night Council', relationship_type: 'alliance' }],
            collections: [{ id: 33, name: 'Current Arc', collection_type: 'custom' }],
            significanceLevels: ['major', 'foundational'],
        })

        const entityField = findField(scaffold.props('sections'), 'focus_entity_ids')
        const groupField = findField(scaffold.props('sections'), 'focus_group_relationship_ids')
        const collectionField = findField(scaffold.props('sections'), 'focus_collection_ids')

        expect(entityField.options).toEqual([
            { value: 31, label: 'Seraphine (#31 · Character)' },
        ])
        expect(groupField.options).toEqual([
            { value: 32, label: 'Night Council (#32 · Alliance)' },
        ])
        expect(collectionField.options).toEqual([
            { value: 33, label: 'Current Arc (#33 · Custom)' },
        ])
        expect(form.focus_entity_ids).toEqual([])

        await scaffold.props('onSubmit')()

        expect(form.post).toHaveBeenCalledWith({ name: 'session-logs.store', params: undefined })
    })

    it('builds the session log edit form and targets the update route', async () => {
        const { form, scaffold } = mountPage(SessionEdit, {
            session: {
                id: 95,
                title: 'Thread untangling',
                session_date: '2026-06-20',
                external_tool: 'claude',
                focus_description: 'Updated focus note.',
                decisions_made: { type: 'doc', content: [] },
                changes_applied: { type: 'doc', content: [] },
                open_threads: { type: 'doc', content: [] },
                session_significance: 'major',
                notes: { type: 'doc', content: [] },
            },
            significanceLevels: ['major'],
        })

        expect(form.title).toBe('Thread untangling')
        expect(form.external_tool).toBe('claude')
        expect(form.session_significance).toBe('major')

        await scaffold.props('onSubmit')()

        expect(form.put).toHaveBeenCalledWith({ name: 'session-logs.update', params: 95 })
    })
})

function mountPage(component, props = {}) {
    const wrapper = mount(component, {
        props,
        global: {
            config: {
                globalProperties: {
                    route: global.route,
                },
            },
            stubs: {
                ScaffoldFormPage: ScaffoldFormPageStub,
            },
        },
    })

    return {
        wrapper,
        form: formInstances.at(-1),
        scaffold: wrapper.getComponent(ScaffoldFormPageStub),
    }
}

function findField(sections, key) {
    return sections
        .flatMap((section) => section.fields)
        .find((field) => field.key === key)
}
