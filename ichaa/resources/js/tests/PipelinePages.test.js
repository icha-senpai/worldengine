import { reactive } from 'vue'
import { mount } from '@vue/test-utils'
import PipelineIndex from '@/Pages/Production/Pipeline/Index.vue'
import PipelineCreate from '@/Pages/Production/Pipeline/Create.vue'
import PipelineEdit from '@/Pages/Production/Pipeline/Edit.vue'
import PipelineShow from '@/Pages/Production/Pipeline/Show.vue'

const { formInstances, routerDeleteMock, routerGetMock, routerPostMock, useFormMock } = vi.hoisted(() => ({
    formInstances: [],
    routerDeleteMock: vi.fn(),
    routerGetMock: vi.fn(),
    routerPostMock: vi.fn(),
    useFormMock: vi.fn(),
}))

vi.mock('@inertiajs/vue3', async () => {
    const actual = await vi.importActual('@inertiajs/vue3')

    return {
        ...actual,
        router: {
            delete: routerDeleteMock,
            get: routerGetMock,
            post: routerPostMock,
        },
        useForm: useFormMock,
    }
})

describe('pipeline custom pages', () => {
    beforeEach(() => {
        formInstances.length = 0
        routerDeleteMock.mockReset()
        routerGetMock.mockReset()
        routerPostMock.mockReset()
        useFormMock.mockReset()
        useFormMock.mockImplementation((initial) => {
            const form = reactive({
                ...initial,
                errors: {},
                processing: false,
                isDirty: false,
                post: vi.fn(),
                put: vi.fn(),
            })

            formInstances.push(form)

            return form
        })

        global.route = vi.fn((name, params) => ({ name, params }))
        global.confirm = vi.fn(() => true)
    })

    it('renders the pipeline create scene branch with real options and submits to store', async () => {
        const { wrapper, form } = mountPage(PipelineCreate, {
            parentItems: [{ id: 3, title: 'Chapter 1', pipeline_type: 'chapter' }],
            characterEntities: [{ id: 4, name: 'Seraphine', entity_type: 'character' }],
            locationEntities: [{ id: 8, name: 'Mirror Library', entity_type: 'location' }],
            entities: [{ id: 11, name: 'Johnny', entity_type: 'character' }],
            pipelineTypes: ['scene', 'character_study'],
            pipelineStages: ['concept', 'outlined'],
        })

        expect(wrapper.text()).toContain('Select a type to continue')
        expect(form.pipeline_stage).toBe('concept')

        await clickButtonByText(wrapper, 'button', 'Scene')

        expect(form.pipeline_type).toBe('scene')
        expect(wrapper.text()).toContain('Scene Details')
        expect(wrapper.text()).toContain('Chapter 1 (#3 · Chapter)')
        expect(wrapper.text()).toContain('Seraphine (#4 · Character)')
        expect(wrapper.text()).toContain('Mirror Library (#8 · Location)')

        await clickButtonByText(wrapper, 'button', 'Outlined')
        expect(form.pipeline_stage).toBe('outlined')

        await clickButtonByText(wrapper, 'button', 'Confrontation')
        expect(form.emotional_beat).toBe('confrontation')

        await clickButtonByText(wrapper, 'button', 'Confrontation')
        expect(form.emotional_beat).toBe('')

        await wrapper.get('input[placeholder="Item title"]').setValue('Library breach')

        expect(getButtonByText(wrapper, 'button', 'Create Item').attributes('disabled')).toBeUndefined()

        await wrapper.get('form').trigger('submit.prevent')

        expect(form.post).toHaveBeenCalledWith({ name: 'pipeline.store', params: undefined })
    })

    it('renders the pipeline create character-study branch with tracked-entity options', async () => {
        const { wrapper, form } = mountPage(PipelineCreate, {
            entities: [{ id: 11, name: 'Johnny', entity_type: 'character' }],
            pipelineTypes: ['scene', 'character_study'],
            pipelineStages: ['concept'],
        })

        await clickButtonByText(wrapper, 'button', 'Character Study')

        expect(form.pipeline_type).toBe('character_study')
        expect(wrapper.text()).toContain('Arc Tracker')
        expect(wrapper.text()).toContain('Johnny (#11 · Character)')

        await clickButtonByText(wrapper, 'button', 'Transformation')
        expect(form.arc_stage).toBe('transformation')

        await clickButtonByText(wrapper, 'button', 'Transformation')
        expect(form.arc_stage).toBe('')

        await wrapper.get('input[placeholder="Item title"]').setValue('Johnny fracture arc')
        await wrapper.get('form').trigger('submit.prevent')

        expect(form.post).toHaveBeenCalledWith({ name: 'pipeline.store', params: undefined })
    })

    it('renders the pipeline edit character-study branch and submits updates', async () => {
        const { wrapper, form } = mountPage(PipelineEdit, {
            item: {
                id: 22,
                title: 'Johnny fracture arc',
                pipeline_type: 'character_study',
                pipeline_stage: 'drafted',
                content: 'Arc body',
                word_count: 1200,
                reading_time_minutes: 5,
                tracked_entity_id: 11,
                arc_stage: 'rising_pressure',
                arc_notes: 'He is almost there.',
                notes: 'Tighten act two.',
            },
            entities: [{ id: 11, name: 'Johnny', entity_type: 'character' }],
            pipelineTypes: ['scene', 'character_study'],
            pipelineStages: ['drafted', 'revised'],
        })

        expect(form.title).toBe('Johnny fracture arc')
        expect(form.pipeline_stage).toBe('drafted')
        expect(form.arc_stage).toBe('rising_pressure')
        expect(form.notes).toBe('Tighten act two.')
        expect(wrapper.text()).toContain('Arc Tracker')
        expect(wrapper.text()).toContain('Author Notes')

        await clickButtonByText(wrapper, 'button', 'Revised')
        expect(form.pipeline_stage).toBe('revised')

        await clickButtonByText(wrapper, 'button', 'Transformation')
        expect(form.arc_stage).toBe('transformation')

        await wrapper.get('form').trigger('submit.prevent')

        expect(form.put).toHaveBeenCalledWith({ name: 'pipeline.update', params: 22 })
    })

    it('renders the pipeline index rows and routes filter actions through inertia', async () => {
        const { wrapper } = mountPage(PipelineIndex, {
            items: {
                data: [
                    {
                        id: 31,
                        title: 'Library breach',
                        pipeline_type: 'scene',
                        pipeline_stage: 'drafted',
                        emotional_beat: 'confrontation',
                        word_count: 1400,
                        children_count: 2,
                        pov_character: { name: 'Seraphine' },
                        location: { name: 'Mirror Library' },
                    },
                ],
                total: 1,
                current_page: 1,
                last_page: 2,
                prev_page_url: null,
                next_page_url: '/pipeline?page=2',
            },
            filters: {
                type: 'scene',
                stage: '',
            },
            pipelineTypes: ['scene', 'character_study'],
            pipelineStages: ['drafted', 'revised'],
        })

        expect(wrapper.text()).toContain('Writing Pipeline')
        expect(wrapper.text()).toContain('filtered')
        expect(wrapper.text()).toContain('POV: Seraphine')
        expect(wrapper.text()).toContain('@ Mirror Library')
        expect(wrapper.text()).toContain('1,400w')

        await clickButtonByText(wrapper, 'button', 'Character Study')

        expect(routerGetMock).toHaveBeenCalledWith(
            { name: 'pipeline.index', params: undefined },
            { type: 'character_study', stage: '' },
            { preserveState: true, replace: true },
        )

        await clickButtonByText(wrapper, 'button', 'Clear')

        expect(routerGetMock).toHaveBeenLastCalledWith(
            { name: 'pipeline.index', params: undefined },
            {},
            { replace: true },
        )
    })

    it('renders the pipeline show character-study branch and routes advance and delete actions', async () => {
        const { wrapper } = mountPage(PipelineShow, {
            item: {
                id: 44,
                title: 'Johnny fracture arc',
                pipeline_type: 'character_study',
                pipeline_stage: 'revised',
                word_count: 2400,
                reading_time_minutes: 65,
                content: 'Arc body',
                tracked_entity: { id: 11, name: 'Johnny' },
                arc_stage: 'transformation',
                arc_notes: 'He lets the old pattern break.',
                notes: 'Check the emotional pacing.',
                visibility: 'private',
                content_classification: 'restricted',
                children: [
                    {
                        id: 45,
                        title: 'Aftershock note',
                        pipeline_type: 'note',
                        pipeline_stage: 'outlined',
                        word_count: 250,
                    },
                ],
            },
        })

        expect(wrapper.text()).toContain('Arc Tracker')
        expect(wrapper.text()).toContain('Johnny')
        expect(wrapper.text()).toContain('Transformation')
        expect(wrapper.text()).toContain('1h 5m')
        expect(wrapper.text()).toContain('Sub-Items (1)')
        expect(wrapper.text()).toContain('Check the emotional pacing.')

        await clickButtonByText(wrapper, 'button', 'Advance →')
        await clickButtonByText(wrapper, 'button', 'Move to Trash')

        expect(routerPostMock).toHaveBeenCalledWith({ name: 'pipeline.advance', params: 44 })
        expect(routerDeleteMock).toHaveBeenCalledWith({ name: 'pipeline.destroy', params: 44 })
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
                AuthenticatedLayout: {
                    template: '<div><slot name="header" /><slot /></div>',
                },
                Link: {
                    props: ['href'],
                    template: '<a :data-href="JSON.stringify(href)"><slot /></a>',
                },
            },
        },
    })

    return {
        wrapper,
        form: formInstances.at(-1),
    }
}

async function clickButtonByText(wrapper, selector, text) {
    const button = getButtonByText(wrapper, selector, text)

    expect(button).toBeTruthy()

    await button.trigger('click')
}

function getButtonByText(wrapper, selector, text) {
    return wrapper.findAll(selector).find((candidate) => candidate.text() === text)
}
