import { mount, flushPromises } from '@vue/test-utils'
import { reactive } from 'vue'
import OpenCrafts from '@/Pages/Bitcraft/OpenCrafts.vue'
import SitePlayerPicker from '@/Pages/Bitcraft/Components/SitePlayerPicker.vue'
import axios from 'axios'

const mocks = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn(), page: { props: { bitcraft: { player: null } } } }))
vi.mock('@inertiajs/vue3', () => ({
    router: { get: mocks.get },
    usePage: () => mocks.page,
    useForm: (values) => ({ ...values, processing: false, errors: {}, clearErrors: vi.fn(), put: mocks.put }),
    Head: { template: '<span />' },
}))
vi.mock('axios', () => ({ default: { get: vi.fn() } }))

describe('Open crafts and site player', () => {
    beforeEach(() => {
        mocks.get.mockReset()
        mocks.put.mockReset()
        axios.get.mockReset()
        mocks.page.props = reactive({ bitcraft: { player: null } })
        global.route = vi.fn((name) => name)
    })

    it('shows both XP estimates and distinct whole-craft and remaining projections', async () => {
        const wrapper = mountCrafts()
        expect(wrapper.text()).toContain('120')
        expect(wrapper.text()).toContain('200')
        expect(wrapper.text()).toContain('+2 levels')
        await wrapper.get('button[title="Craft details"]').trigger('click')
        expect(wrapper.text()).toContain('Level 1 to 4 (+3)')
        expect(wrapper.text()).toContain('Not met')
        wrapper.unmount()
    })

    it('keeps unknown projections unavailable and reports errors separately from empty results', () => {
        const wrapper = mountCrafts({ crafts: [], error: 'Provider unavailable' })
        expect(wrapper.get('[role="alert"]').text()).toBe('Provider unavailable')
        expect(wrapper.text()).not.toContain('No open crafts match')
        wrapper.unmount()
    })

    it('passes pagination and toggle filters through the existing Inertia router', async () => {
        mocks.page.props.bitcraft.player = { entityId: '123', username: 'Juice' }
        const wrapper = mountCrafts()
        await wrapper.findAll('input[type="checkbox"]')[0].setValue(true)
        expect(mocks.get).toHaveBeenLastCalledWith('bitcraft.open-crafts', expect.objectContaining({ levelUps: 1, page: 1 }), expect.any(Object))
        await wrapper.findAll('button').find((button) => button.text().includes('Next')).trigger('click')
        expect(mocks.get).toHaveBeenLastCalledWith('bitcraft.open-crafts', expect.objectContaining({ page: 2 }), expect.any(Object))
        wrapper.unmount()
    })

    it('searches players only on submission and submits the selected identity', async () => {
        axios.get.mockResolvedValue({ data: { players: [{ entityId: '123', username: 'Juice' }] } })
        const wrapper = mountPicker()
        await wrapper.get('button').trigger('click')
        await wrapper.get('input').setValue('Juice')
        expect(axios.get).not.toHaveBeenCalled()
        await wrapper.get('form').trigger('submit')
        await flushPromises()
        expect(axios.get).toHaveBeenCalledWith('bitcraft.players.search', { params: { q: 'Juice' } })
        await wrapper.get('.player-result').trigger('click')
        expect(mocks.put).toHaveBeenCalledWith('bitcraft.player.update', expect.objectContaining({ preserveState: 'errors' }))
        wrapper.unmount()
    })

    it('shows provider failures in the picker', async () => {
        axios.get.mockRejectedValue({ response: { data: { error: 'Search delayed' } } })
        const wrapper = mountPicker()
        await wrapper.get('button').trigger('click')
        await wrapper.get('input').setValue('Juice')
        await wrapper.get('form').trigger('submit')
        await flushPromises()
        expect(wrapper.get('[role="alert"]').text()).toBe('Search delayed')
        wrapper.unmount()
    })
})

function mountPicker() {
    return mount(SitePlayerPicker, { global: { stubs: { Modal: { props: ['show'], template: '<div v-if="show"><slot /></div>' } } } })
}

function mountCrafts(overrides = {}) {
    return mount(OpenCrafts, {
        props: {
            filters: { q: '', skill: '', region: '', sort: 'xp', page: 1, levelUps: false, meetsLevel: false, mine: false },
            crafts: [{ id: '1', name: 'Rough Plank', outputs: [], claim: 'Juice Town', owner: 'Juice', region: 8, count: 10, skill: 'Carpentry', progressPercent: 40, remainingXp: 120, fullXp: 200, currentXp: 90, currentLevel: 1, afterLevel: 3, fullLevel: 4, levelsGained: 2, requirements: [{ skill: 'Carpentry', level: 2 }], meetsLevel: false, toolRequirements: [] }],
            pagination: { page: 1, lastPage: 2, total: 30, all: 30 }, skillOptions: [], regionOptions: [], refresh: {}, error: null, playerError: null,
            ...overrides,
        },
        global: { stubs: { AuthenticatedLayout: { template: '<div><slot name="header" /><slot /></div>' } } },
    })
}
