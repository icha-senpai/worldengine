import { mount } from '@vue/test-utils'
import Dashboard from '@/Pages/Dashboard.vue'

describe('Dashboard page', () => {
    beforeEach(() => {
        global.route = vi.fn((name, params) => ({ name, params }))
    })

    it('renders overview cards and the key dashboard panels', () => {
        const wrapper = mountPage({
            recentPipeline: [
                {
                    id: 1,
                    title: 'Library breach',
                    pipeline_type: 'scene',
                    pipeline_stage: 'drafted',
                    word_count: 1400,
                },
            ],
            sessionStats: {
                session_count: 3,
                major_count: 1,
                tools_used: ['claude', 'chatgpt'],
            },
            latentTension: [
                {
                    id: 2,
                    knowledge_type: 'secret',
                    current_belief_state: 'believes',
                    subject_name: 'Seraphine',
                    knower: { id: 9, name: 'Johnny' },
                },
            ],
            exposureRisk: [
                {
                    id: 3,
                    title: 'Puppet Cycle',
                    exposure_risk: 'critical',
                    holder_count: 1,
                    known_by_count: 2,
                    is_leaking: true,
                },
            ],
            perceptionGaps: [
                {
                    id: 4,
                    subject_type: 'entity',
                    subject_id: 12,
                    revelation_risk: 'high',
                    immune_count: 1,
                    maintainer_count: 1,
                    tension_ratio: 1,
                },
            ],
            blockingQuestions: [
                {
                    id: 5,
                    question: 'Who taught Seraphine the fracture ritual?',
                    priority: 'critical',
                    entity: { id: 12, name: 'Seraphine' },
                },
            ],
            blockingContradictions: [
                {
                    id: 6,
                    title: 'Johnny cannot know both versions of the breach.',
                    meta_note_type: 'continuity_issue',
                    action_status: 'pending',
                },
            ],
            unresolvedInteractions: [
                {
                    id: 7,
                    interaction_name: 'Storm and Null',
                    knowledge_state: 'rumored',
                    danger_rating: 'high',
                    system_a_name: 'Storm Binding',
                    system_b_name: 'Null Weave',
                },
            ],
            deprecatedCanonStates: [
                {
                    id: 8,
                    version_label: 'Old Seraphine',
                    version_number: 3,
                    entity: { id: 12, name: 'Seraphine' },
                    replacement_label: 'Fracture Seraphine',
                },
            ],
        })

        expect(wrapper.text()).toContain('Overview')
        expect(wrapper.text()).toContain('Sessions / 30d')
        expect(wrapper.text()).toContain('Recent Writing')
        expect(wrapper.text()).toContain('Library breach')
        expect(wrapper.text()).toContain('Latent Tension')
        expect(wrapper.text()).toContain('Johnny')
        expect(wrapper.text()).toContain('Puppet Cycle')
        expect(wrapper.text()).toContain('leaking')
        expect(wrapper.text()).toContain('Perception Gaps')
        expect(wrapper.text()).toContain('entity #12')
        expect(wrapper.text()).toContain('Blocking Questions')
        expect(wrapper.text()).toContain('Who taught Seraphine the fracture ritual?')
        expect(wrapper.text()).toContain('Blocking Contradictions')
        expect(wrapper.text()).toContain('Johnny cannot know both versions of the breach.')
        expect(wrapper.text()).toContain('Unresolved Interactions')
        expect(wrapper.text()).toContain('Storm and Null')
        expect(wrapper.text()).toContain('Deprecated Canon States')
        expect(wrapper.text()).toContain('Fracture Seraphine')
    })

    it('shows the empty-state messaging when dashboard lists are empty', () => {
        const wrapper = mountPage()

        expect(wrapper.text()).toContain('No pipeline items yet.')
        expect(wrapper.text()).toContain('No blocking questions.')
        expect(wrapper.text()).toContain('No blocking contradictions.')
        expect(wrapper.text()).toContain('No unresolved interactions.')
        expect(wrapper.text()).toContain('No deprecated canon states.')
        expect(wrapper.text()).toContain('No latent tension recorded.')
        expect(wrapper.text()).toContain('No high-risk secrets.')
        expect(wrapper.text()).not.toContain('Perception Gaps')
    })
})

function mountPage(props = {}) {
    return mount(Dashboard, {
        props: {
            recentPipeline: [],
            sessionStats: { session_count: 0, major_count: 0, tools_used: [] },
            latentTension: [],
            exposureRisk: [],
            perceptionGaps: [],
            blockingQuestions: [],
            blockingContradictions: [],
            unresolvedInteractions: [],
            deprecatedCanonStates: [],
            ...props,
        },
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
            },
        },
    })
}
