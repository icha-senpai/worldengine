import { mount } from '@vue/test-utils'
import CraftingPanel from '@/Pages/ConnectedRealms/CraftingPanel.vue'
import GatheringPanel from '@/Pages/ConnectedRealms/GatheringPanel.vue'
import SkillActivitiesPanel from '@/Pages/ConnectedRealms/SkillActivitiesPanel.vue'
import ShopPanel from '@/Pages/ConnectedRealms/ShopPanel.vue'
import { actionReloadProps, activityReloadProps } from '@/Pages/ConnectedRealms/reloadProps'

const { useFormMock } = vi.hoisted(() => ({
    useFormMock: vi.fn(),
}))

vi.mock('@inertiajs/vue3', async () => {
    const actual = await vi.importActual('@inertiajs/vue3')

    return {
        ...actual,
        useForm: useFormMock,
    }
})

describe('Connected Realms focused boards', () => {
    beforeEach(() => {
        useFormMock.mockReset()
        useFormMock.mockImplementation((initial) => ({
            ...initial,
            errors: {},
            processing: false,
            post: vi.fn(),
            delete: vi.fn(),
        }))

        global.route = vi.fn((name) => name)
    })

    afterEach(() => {
        vi.useRealTimers()
    })

    it('keeps locked gathering runs out of the default ready board', async () => {
        const wrapper = mount(GatheringPanel, {
            props: {
                actions: [
                    gatheringAction({ key: 'fish', label: 'Fish Moonwake Pier', is_unlocked: true }),
                    ...Array.from({ length: 14 }, (_, index) => gatheringAction({
                        key: `locked-${index + 1}`,
                        label: `Locked Run ${index + 1}`,
                        is_unlocked: false,
                    })),
                ],
                player: {
                    can_act_now: true,
                    next_action_at: null,
                },
                searchTerm: '',
            },
            global: routeGlobal(),
        })

        expect(wrapper.text()).toContain('Fish Moonwake Pier')
        expect(wrapper.text()).not.toContain('Locked Run 1')
        expect(wrapper.text()).not.toContain('Archive')

        await wrapper.findAll('button').find((button) => button.text().includes('Next')).trigger('click')

        expect(wrapper.text()).toContain('Locked Run 12')
        expect(wrapper.text()).not.toContain('Locked Run 14')

        await wrapper.findAll('button').find((button) => button.text().includes('Show More')).trigger('click')

        expect(wrapper.text()).toContain('Locked Run 14')

        wrapper.unmount()
    })

    it('keeps action reloads lean for fast repeated clicks', () => {
        expect(actionReloadProps).toEqual(['player', 'summary', 'last_result'])
        expect(activityReloadProps).toEqual(['player', 'summary', 'last_result'])
    })

    it('does not round a one-second gathering action lock up to two seconds', () => {
        vi.useFakeTimers()
        vi.setSystemTime(new Date('2026-08-06T12:00:00Z'))

        const wrapper = mount(GatheringPanel, {
            props: {
                actions: [
                    gatheringAction({ key: 'fish', label: 'Fishing' }),
                ],
                player: {
                    can_act_now: false,
                    next_action_at: new Date(Date.now() + 1600).toISOString(),
                },
                searchTerm: '',
            },
            global: routeGlobal(),
        })

        expect(wrapper.text()).toContain('0:01')
        expect(wrapper.text()).not.toContain('0:02')

        wrapper.unmount()
    })

    it('does not round a one-second skill activity lock up to two seconds', () => {
        vi.useFakeTimers()
        vi.setSystemTime(new Date('2026-08-06T12:00:00Z'))

        const wrapper = mount(SkillActivitiesPanel, {
            props: {
                activities: [
                    skillActivity({ key: 'fishing-starter', label: 'Candlemark Net Practice' }),
                ],
                player: {
                    can_act_now: false,
                    next_action_at: new Date(Date.now() + 1600).toISOString(),
                },
                searchTerm: '',
            },
            global: routeGlobal(),
        })

        expect(wrapper.text()).toContain('0:01')
        expect(wrapper.text()).not.toContain('0:02')

        wrapper.unmount()
    })

    it('keeps uncraftable recipe plans behind prepare', async () => {
        const wrapper = mount(CraftingPanel, {
            props: {
                recipes: [
                    craftingRecipe({ key: 'ready-stew', label: 'Ready Stew', can_craft: true, is_unlocked: true }),
                    craftingRecipe({ key: 'missing-bar', label: 'Missing Bar', can_craft: false, is_unlocked: true }),
                    craftingRecipe({ key: 'locked-forge', label: 'Locked Forge', can_craft: false, is_unlocked: false }),
                ],
                searchTerm: '',
            },
            global: routeGlobal(),
        })

        expect(wrapper.text()).toContain('Ready Stew')
        expect(wrapper.text()).not.toContain('Missing Bar')
        expect(wrapper.text()).not.toContain('Locked Forge')

        await wrapper.findAll('button').find((button) => button.text().includes('Prepare')).trigger('click')

        expect(wrapper.text()).toContain('Missing Bar')
        expect(wrapper.text()).not.toContain('Locked Forge')
    })

    it('keeps unaffordable shop offers behind the plan board', async () => {
        const wrapper = mount(ShopPanel, {
            props: {
                shop: {
                    offers: [
                        shopOffer({ key: 'ready-tool', label: 'Ready Tool', can_buy: true }),
                        shopOffer({ key: 'pricey-tool', label: 'Pricey Tool', can_buy: false }),
                    ],
                },
                searchTerm: '',
            },
            global: routeGlobal(),
        })

        expect(wrapper.text()).toContain('Ready Tool')
        expect(wrapper.text()).not.toContain('Pricey Tool')

        await wrapper.findAll('button').find((button) => button.text().includes('Plan')).trigger('click')

        expect(wrapper.text()).toContain('Pricey Tool')
    })
})

function routeGlobal() {
    return {
        config: {
            globalProperties: {
                route: global.route,
            },
        },
    }
}

function gatheringAction(overrides = {}) {
    return {
        key: 'action',
        label: 'Action',
        skill_label: 'Fishing',
        skill_level: 1,
        required_level: 1,
        is_unlocked: true,
        location: 'Moonwake',
        loot_preview: [],
        equipped_tool: null,
        ...overrides,
    }
}

function skillActivity(overrides = {}) {
    return {
        key: 'activity',
        label: 'Activity',
        track: 'Practice',
        category: 'Gathering',
        skill_label: 'Fishing',
        skill_level: 1,
        required_level: 1,
        is_unlocked: true,
        band: '1-30',
        location: 'Moonwake',
        description: 'Practice the route.',
        experience: {
            min: 10,
            max: 20,
        },
        gold: {
            min: 1,
            max: 3,
        },
        loot_preview: [],
        equipped_tool: null,
        ...overrides,
    }
}

function craftingRecipe(overrides = {}) {
    return {
        key: 'recipe',
        label: 'Recipe',
        category: 'Cooking',
        skill_label: 'Cooking',
        required_level: 1,
        skill_level: 1,
        can_craft: false,
        is_unlocked: true,
        experience: 10,
        gold_cost: 0,
        ingredients: [],
        outputs: [],
        ...overrides,
    }
}

function shopOffer(overrides = {}) {
    return {
        key: 'offer',
        label: 'Offer',
        kind: 'tool',
        category: 'Tools',
        skill_label: 'Fishing',
        item_name: 'Offer',
        rarity: 'common',
        quality: 'standard',
        item_class: 'tool',
        material_family: 'Tool',
        price: 10,
        weight: 1,
        vendor_value: 5,
        required_level: 1,
        skill_level: 1,
        is_unlocked: true,
        can_buy: false,
        is_equipped: false,
        is_downgrade: false,
        ownership_status: 'Available',
        bonuses: {
            experience: 1,
            yield: 1,
        },
        ...overrides,
    }
}
