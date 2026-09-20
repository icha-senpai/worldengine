import { mount } from '@vue/test-utils'
import CraftingPanel from '@/Pages/ConnectedRealms/CraftingPanel.vue'
import EquipmentPanel from '@/Pages/ConnectedRealms/EquipmentPanel.vue'
import ExpeditionsPanel from '@/Pages/ConnectedRealms/ExpeditionsPanel.vue'
import GatheringPanel from '@/Pages/ConnectedRealms/GatheringPanel.vue'
import JobsPanel from '@/Pages/ConnectedRealms/JobsPanel.vue'
import SkillActivitiesPanel from '@/Pages/ConnectedRealms/SkillActivitiesPanel.vue'
import ShopPanel from '@/Pages/ConnectedRealms/ShopPanel.vue'
import { actionReloadProps, activityReloadProps, craftingReloadProps, equipmentReloadProps, expeditionReloadProps, jobReloadProps } from '@/Pages/ConnectedRealms/reloadProps'

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
        window.localStorage.clear()
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
        window.localStorage.clear()
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

    it('sorts gathering actions from lowest required level to highest', () => {
        const wrapper = mount(GatheringPanel, {
            props: {
                actions: [
                    gatheringAction({ key: 'rank-50', label: 'Rank 50 Route', required_level: 50 }),
                    gatheringAction({ key: 'rank-20', label: 'Rank 20 Route', required_level: 20 }),
                    gatheringAction({ key: 'rank-1', label: 'Rank 1 Route', required_level: 1 }),
                ],
                player: {
                    can_act_now: true,
                    next_action_at: null,
                },
                searchTerm: '',
            },
            global: routeGlobal(),
        })

        const actionLabels = wrapper.findAll('button')
            .map((button) => button.text())
            .filter((text) => text.includes('Rank'))
            .map((text) => text.match(/Rank \d+ Route/)?.[0])
            .filter(Boolean)

        expect(actionLabels).toEqual(['Rank 1 Route', 'Rank 20 Route', 'Rank 50 Route'])

        wrapper.unmount()
    })

    it('keeps action reloads lean for fast repeated clicks', () => {
        expect(actionReloadProps).toEqual(['player', 'summary', 'last_result'])
        expect(activityReloadProps).toEqual(['player', 'summary', 'last_result'])
        expect(craftingReloadProps).toEqual(['player', 'summary', 'last_result'])
        expect(jobReloadProps).toEqual(['player', 'summary', 'last_result'])
        expect(expeditionReloadProps).toEqual(['player', 'summary', 'last_result'])
        expect(equipmentReloadProps).toEqual([
            'player',
            'actions',
            'skill_activities',
            'equipment',
            'tool_inventory',
            'tool_rarity_upgrades',
            'tool_tier_upgrades',
            'summary',
            'last_result',
        ])
    })

    it('labels repair as needing materials when repair materials are missing', () => {
        const wrapper = mount(EquipmentPanel, {
            props: {
                equipment: [
                    equipmentTool({
                        tool_lifecycle: {
                            repair: {
                                missing_durability: 100,
                                gold_cost: 200,
                                materials: [
                                    ingredient({
                                        item_key: 'iron_ingot',
                                        item_name: 'Iron Ingot',
                                        quantity: 1,
                                        owned_quantity: 0,
                                        has_enough: false,
                                    }),
                                ],
                                can_repair: false,
                                has_gold: true,
                                has_materials: false,
                                missing_materials: true,
                                is_repairable: true,
                            },
                            salvage: {
                                can_salvage: false,
                                materials: [],
                            },
                            can_retire: true,
                        },
                    }),
                ],
                toolInventory: [],
                toolRarityUpgrades: { options: [], ready_count: 0 },
                toolTierUpgrades: { options: [], ready_count: 0 },
            },
            global: routeGlobal(),
        })

        expect(wrapper.text()).toContain('Need Materials')
        expect(wrapper.text()).toContain('0/1 Iron Ingot')

        const repairButton = wrapper.findAll('button').find((button) => button.text() === 'Need Materials')

        expect(repairButton.attributes('disabled')).toBeDefined()

        wrapper.unmount()
    })

    it('shows durability numbers and percent on the equipment durability bar', () => {
        const wrapper = mount(EquipmentPanel, {
            props: {
                equipment: [
                    equipmentTool({
                        durability: 110,
                        max_durability: 220,
                        durability_percent: 50,
                        is_broken: false,
                    }),
                ],
                toolInventory: [],
                toolRarityUpgrades: { options: [], ready_count: 0 },
                toolTierUpgrades: { options: [], ready_count: 0 },
            },
            global: routeGlobal(),
        })

        expect(wrapper.text()).toContain('110/220 · 50%')
        expect(wrapper.find('[aria-label="Durability 110/220 · 50%"]').exists()).toBe(true)

        wrapper.unmount()
    })

    it('shows current skill level and level progress in the gathering skill sidebar', () => {
        const wrapper = mount(GatheringPanel, {
            props: {
                actions: [
                    gatheringAction({
                        key: 'fish',
                        label: 'Fishing',
                        skill_level: 4,
                        skill_progress: {
                            skill: 'fishing',
                            skill_label: 'Fishing',
                            level: 4,
                            experience: 420,
                            current_level_experience: 367,
                            next_level_experience: 450,
                            experience_into_level: 53,
                            experience_to_next_level: 30,
                            max_level: 100,
                        },
                    }),
                ],
                player: {
                    can_act_now: true,
                    next_action_at: null,
                },
                searchTerm: '',
            },
            global: routeGlobal(),
        })

        const fishingFilter = wrapper.findAll('button').find((button) => button.text().includes('Fishing'))

        expect(fishingFilter.text()).toContain('Lv 4')
        expect(fishingFilter.find('.bg-focus').attributes('style')).toContain('width: 64%')

        wrapper.unmount()
    })

    it('restores the selected gathering skill sidebar filter from local storage', () => {
        window.localStorage.setItem('evergather.gathering-board-state', JSON.stringify({
            selectedFilter: 'Fishing',
            selectedBoard: 'ready',
        }))

        const wrapper = mount(GatheringPanel, {
            props: {
                actions: [
                    gatheringAction({
                        key: 'fish',
                        label: 'Fishing Route',
                        skill: 'fishing',
                        skill_label: 'Fishing',
                    }),
                    gatheringAction({
                        key: 'mine',
                        label: 'Mining Route',
                        skill: 'mining',
                        skill_label: 'Mining',
                    }),
                ],
                player: {
                    can_act_now: true,
                    next_action_at: null,
                },
                searchTerm: '',
            },
            global: routeGlobal(),
        })

        expect(wrapper.text()).toContain('Fishing Route')
        expect(wrapper.text()).not.toContain('Mining Route')

        wrapper.unmount()
    })

    it('does not show zero tool bonuses on gathering action cards', () => {
        const wrapper = mount(GatheringPanel, {
            props: {
                actions: [
                    gatheringAction({
                        key: 'fish',
                        label: 'Fishing',
                        equipped_tool: toolPayload({
                            item_name: 'Reed Rod',
                            signature_trait: 'Tidehook Memory',
                            experience_bonus: 0,
                            yield_bonus: 0,
                            is_broken: true,
                        }),
                    }),
                ],
                player: {
                    can_act_now: true,
                    next_action_at: null,
                },
                searchTerm: '',
            },
            global: routeGlobal(),
        })

        expect(wrapper.text()).toContain('Reed Rod')
        expect(wrapper.text()).toContain('Broken')
        expect(wrapper.text()).toContain('Repair Tool')
        expect(wrapper.text()).not.toContain('+0 XP')
        expect(wrapper.text()).not.toContain('+0 yield')
        expect(wrapper.findAll('button').find((button) => button.text().includes('Reed Rod')).attributes('disabled')).toBeDefined()

        wrapper.unmount()
    })

    it('updates gathering skill progress locally from the latest action result', async () => {
        const wrapper = mount(GatheringPanel, {
            props: {
                actions: [
                    gatheringAction({
                        key: 'fish',
                        label: 'Fishing',
                        skill_level: 1,
                        skill_progress: {
                            skill: 'fishing',
                            skill_label: 'Fishing',
                            level: 1,
                            experience: 0,
                            current_level_experience: 0,
                            next_level_experience: 200,
                            experience_into_level: 0,
                            experience_to_next_level: 200,
                            max_level: 100,
                        },
                    }),
                ],
                player: {
                    can_act_now: true,
                    next_action_at: null,
                },
                lastResult: null,
                searchTerm: '',
            },
            global: routeGlobal(),
        })

        await wrapper.setProps({
            lastResult: {
                action: 'fish',
                skill_progress: {
                    skill: 'fishing',
                    skill_label: 'Fishing',
                    level: 4,
                    experience: 420,
                    current_level_experience: 367,
                    next_level_experience: 450,
                    experience_into_level: 53,
                    experience_to_next_level: 30,
                    max_level: 100,
                },
            },
        })

        const fishingFilter = wrapper.findAll('button').find((button) => button.text().includes('Fishing'))

        expect(fishingFilter.text()).toContain('Lv 4')
        expect(fishingFilter.find('.bg-focus').attributes('style')).toContain('width: 64%')

        wrapper.unmount()
    })

    it('repeats a ready gathering action immediately when repeat is enabled', async () => {
        vi.useFakeTimers()

        const post = vi.fn()
        useFormMock.mockImplementation((initial) => ({
            ...initial,
            errors: {},
            processing: false,
            post,
            delete: vi.fn(),
        }))

        const wrapper = mount(GatheringPanel, {
            props: {
                actions: [
                    gatheringAction({ key: 'fish', label: 'Fishing Route' }),
                ],
                player: {
                    can_act_now: true,
                    next_action_at: null,
                },
                searchTerm: '',
            },
            global: routeGlobal(),
        })

        await wrapper.findAll('button').find((button) => button.text().includes('Fishing Route')).trigger('click')

        expect(post).toHaveBeenCalledTimes(1)

        await wrapper.findAll('button').find((button) => button.text().includes('Repeat Last')).trigger('click')
        vi.advanceTimersByTime(0)

        expect(post).toHaveBeenCalledTimes(2)

        wrapper.unmount()
    })

    it('queues zero-cooldown action clicks while the previous request is still finishing', async () => {
        vi.useFakeTimers()

        let form
        let lastOptions
        const post = vi.fn((url, options) => {
            form.processing = true
            lastOptions = options
        })
        useFormMock.mockImplementation((initial) => {
            form = {
                ...initial,
                errors: {},
                processing: false,
                post,
                delete: vi.fn(),
            }

            return form
        })

        const wrapper = mount(GatheringPanel, {
            props: {
                actions: [
                    gatheringAction({ key: 'fish', label: 'Fishing Route', cooldown_seconds: 0 }),
                ],
                player: {
                    can_act_now: true,
                    next_action_at: null,
                },
                searchTerm: '',
            },
            global: routeGlobal(),
        })

        const actionButton = wrapper.findAll('button').find((button) => button.text().includes('Fishing Route'))

        await actionButton.trigger('click')
        await actionButton.trigger('click')

        expect(post).toHaveBeenCalledTimes(1)

        form.processing = false
        lastOptions.onFinish()
        vi.advanceTimersByTime(0)

        expect(post).toHaveBeenCalledTimes(2)

        wrapper.unmount()
    })

    it('repeats a ready skill activity immediately when repeat is enabled', async () => {
        vi.useFakeTimers()

        const post = vi.fn()
        useFormMock.mockImplementation((initial) => ({
            ...initial,
            errors: {},
            processing: false,
            post,
            delete: vi.fn(),
        }))

        const wrapper = mount(SkillActivitiesPanel, {
            props: {
                activities: [
                    skillActivity({ key: 'fishing-starter', label: 'Fishing Practice' }),
                ],
                player: {
                    can_act_now: true,
                    next_action_at: null,
                },
                searchTerm: '',
            },
            global: routeGlobal(),
        })

        await wrapper.findAll('button').find((button) => button.text().includes('Fishing Practice')).trigger('click')

        expect(post).toHaveBeenCalledTimes(1)

        await wrapper.findAll('button').find((button) => button.text().includes('Repeat Last')).trigger('click')
        vi.advanceTimersByTime(0)

        expect(post).toHaveBeenCalledTimes(2)

        wrapper.unmount()
    })

    it('queues zero-cooldown skill activity clicks while the previous request is still finishing', async () => {
        vi.useFakeTimers()

        let form
        let lastOptions
        const post = vi.fn((url, options) => {
            form.processing = true
            lastOptions = options
        })
        useFormMock.mockImplementation((initial) => {
            form = {
                ...initial,
                errors: {},
                processing: false,
                post,
                delete: vi.fn(),
            }

            return form
        })

        const wrapper = mount(SkillActivitiesPanel, {
            props: {
                activities: [
                    skillActivity({ key: 'fishing-starter', label: 'Fishing Practice', cooldown_seconds: 0 }),
                ],
                player: {
                    can_act_now: true,
                    next_action_at: null,
                },
                searchTerm: '',
            },
            global: routeGlobal(),
        })

        const activityButton = wrapper.findAll('button').find((button) => button.text().includes('Fishing Practice'))

        await activityButton.trigger('click')
        await activityButton.trigger('click')

        expect(post).toHaveBeenCalledTimes(1)

        form.processing = false
        lastOptions.onFinish()
        vi.advanceTimersByTime(0)

        expect(post).toHaveBeenCalledTimes(2)

        wrapper.unmount()
    })

    it('keeps zero-cooldown skill activities visually ready while the request finishes', async () => {
        let form
        const post = vi.fn((url, options) => {
            form.processing = true
            options.onStart()
        })
        useFormMock.mockImplementation((initial) => {
            form = {
                ...initial,
                errors: {},
                processing: false,
                post,
                delete: vi.fn(),
            }

            return form
        })

        const wrapper = mount(SkillActivitiesPanel, {
            props: {
                activities: [
                    skillActivity({ key: 'fishing-starter', label: 'Fishing Practice', cooldown_seconds: 0 }),
                ],
                player: {
                    can_act_now: true,
                    next_action_at: null,
                },
                searchTerm: '',
            },
            global: routeGlobal(),
        })

        await wrapper.findAll('button').find((button) => button.text().includes('Fishing Practice')).trigger('click')

        expect(wrapper.text()).toContain('Start')
        expect(wrapper.text()).not.toContain('Starting...')

        wrapper.unmount()
    })

    it('does not wait for the repeat interval when an activity finish callback clears slightly late', async () => {
        vi.useFakeTimers()

        let form
        let lastOptions
        const post = vi.fn((url, options) => {
            form.processing = true
            lastOptions = options
        })
        useFormMock.mockImplementation((initial) => {
            form = {
                ...initial,
                errors: {},
                processing: false,
                post,
                delete: vi.fn(),
            }

            return form
        })

        const wrapper = mount(SkillActivitiesPanel, {
            props: {
                activities: [
                    skillActivity({ key: 'fishing-starter', label: 'Fishing Practice', cooldown_seconds: 0 }),
                ],
                player: {
                    can_act_now: true,
                    next_action_at: null,
                },
                searchTerm: '',
            },
            global: routeGlobal(),
        })

        await wrapper.findAll('button').find((button) => button.text().includes('Fishing Practice')).trigger('click')
        await wrapper.findAll('button').find((button) => button.text().includes('Repeat Last')).trigger('click')

        expect(post).toHaveBeenCalledTimes(1)

        lastOptions.onFinish()
        vi.advanceTimersByTime(0)

        expect(post).toHaveBeenCalledTimes(1)

        form.processing = false
        vi.advanceTimersByTime(16)

        expect(post).toHaveBeenCalledTimes(2)

        wrapper.unmount()
    })

    it('updates skill activity unlocks locally from the latest activity result', async () => {
        const wrapper = mount(SkillActivitiesPanel, {
            props: {
                activities: [
                    skillActivity({
                        key: 'starter',
                        label: 'Starter Drill',
                        skill: 'combat',
                        skill_label: 'Combat',
                        skill_level: 1,
                        required_level: 1,
                        is_unlocked: true,
                    }),
                    skillActivity({
                        key: 'advanced',
                        label: 'Advanced Drill',
                        skill: 'combat',
                        skill_label: 'Combat',
                        skill_level: 1,
                        required_level: 4,
                        is_unlocked: false,
                    }),
                ],
                player: {
                    can_act_now: true,
                    next_action_at: null,
                },
                lastResult: null,
                searchTerm: '',
            },
            global: routeGlobal(),
        })

        expect(wrapper.text()).not.toContain('Advanced Drill')

        await wrapper.setProps({
            lastResult: {
                type: 'skill_activity',
                activity: 'starter',
                skill_progress: {
                    skill: 'combat',
                    skill_label: 'Combat',
                    level: 4,
                    experience: 420,
                    current_level_experience: 367,
                    next_level_experience: 450,
                    experience_into_level: 53,
                    experience_to_next_level: 30,
                    max_level: 100,
                },
            },
        })

        expect(wrapper.text()).toContain('Advanced Drill')

        wrapper.unmount()
    })

    it('organizes the skill activity sidebar by skill', async () => {
        const wrapper = mount(SkillActivitiesPanel, {
            props: {
                activities: [
                    skillActivity({
                        key: 'fishing-starter',
                        label: 'Fishing Practice',
                        skill: 'fishing',
                        skill_label: 'Fishing',
                        skill_level: 4,
                        skill_progress: {
                            skill: 'fishing',
                            skill_label: 'Fishing',
                            level: 4,
                            experience: 420,
                            current_level_experience: 367,
                            next_level_experience: 450,
                            experience_into_level: 53,
                            experience_to_next_level: 30,
                            max_level: 100,
                        },
                    }),
                    skillActivity({
                        key: 'combat-starter',
                        label: 'Combat Practice',
                        skill: 'combat',
                        skill_label: 'Combat',
                        category: 'Combat',
                    }),
                ],
                player: {
                    can_act_now: true,
                    next_action_at: null,
                },
                searchTerm: '',
            },
            global: routeGlobal(),
        })

        const fishingFilter = wrapper.findAll('button').find((button) => button.text().includes('Fishing') && button.text().includes('Lv 4'))

        expect(fishingFilter.exists()).toBe(true)
        expect(fishingFilter.find('.bg-focus').attributes('style')).toContain('width: 64%')

        await fishingFilter.trigger('click')

        expect(wrapper.text()).toContain('Fishing Practice')
        expect(wrapper.text()).not.toContain('Combat Practice')

        wrapper.unmount()
    })

    it('restores the selected skill activity sidebar filter from local storage', () => {
        window.localStorage.setItem('evergather.skill-activities-board-state', JSON.stringify({
            selectedSkill: 'Fishing',
            selectedBand: 'All',
            selectedBoard: 'ready',
        }))

        const wrapper = mount(SkillActivitiesPanel, {
            props: {
                activities: [
                    skillActivity({
                        key: 'fishing-starter',
                        label: 'Fishing Practice',
                        skill: 'fishing',
                        skill_label: 'Fishing',
                    }),
                    skillActivity({
                        key: 'combat-starter',
                        label: 'Combat Practice',
                        skill: 'combat',
                        skill_label: 'Combat',
                    }),
                ],
                player: {
                    can_act_now: true,
                    next_action_at: null,
                },
                searchTerm: '',
            },
            global: routeGlobal(),
        })

        expect(wrapper.text()).toContain('Fishing Practice')
        expect(wrapper.text()).not.toContain('Combat Practice')

        wrapper.unmount()
    })

    it('does not show zero tool bonuses on skill activity cards', () => {
        const wrapper = mount(SkillActivitiesPanel, {
            props: {
                activities: [
                    skillActivity({
                        key: 'activity',
                        label: 'Fishing Drill',
                        equipped_tool: toolPayload({
                            item_name: 'Reed Rod',
                            signature_trait: 'Tidehook Memory',
                            experience_bonus: 0,
                            yield_bonus: 0,
                            is_broken: true,
                        }),
                    }),
                ],
                player: {
                    can_act_now: true,
                    next_action_at: null,
                },
                searchTerm: '',
            },
            global: routeGlobal(),
        })

        expect(wrapper.text()).toContain('Reed Rod')
        expect(wrapper.text()).toContain('Broken')
        expect(wrapper.text()).toContain('Repair Tool')
        expect(wrapper.text()).not.toContain('+0 XP')
        expect(wrapper.text()).not.toContain('+0 yield')
        expect(wrapper.findAll('button').find((button) => button.text().includes('Reed Rod')).attributes('disabled')).toBeDefined()

        wrapper.unmount()
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
                player: player(),
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

    it('restores the selected crafting category and skill filters from local storage', () => {
        window.localStorage.setItem('evergather.crafting-board-state', JSON.stringify({
            selectedFilter: 'Food',
            selectedSkillFilter: 'Cooking',
            selectedBoard: 'ready',
        }))

        const wrapper = mount(CraftingPanel, {
            props: {
                recipes: [
                    craftingRecipe({
                        key: 'stew',
                        label: 'Ready Stew',
                        category: 'Food',
                        skill: 'cooking',
                        skill_label: 'Cooking',
                        can_craft: true,
                    }),
                    craftingRecipe({
                        key: 'tonic',
                        label: 'Ready Tonic',
                        category: 'Food',
                        skill: 'alchemy',
                        skill_label: 'Alchemy',
                        can_craft: true,
                    }),
                ],
                player: player(),
                searchTerm: '',
            },
            global: routeGlobal(),
        })

        expect(wrapper.text()).toContain('Ready Stew')
        expect(wrapper.text()).not.toContain('Ready Tonic')

        wrapper.unmount()
    })

    it('marks craft recipes blocked by a broken tool as repair-only', () => {
        const wrapper = mount(CraftingPanel, {
            props: {
                recipes: [
                    craftingRecipe({
                        key: 'stew',
                        label: 'Ready Stew',
                        can_craft: false,
                        requires_tool_repair: true,
                        equipped_tool: toolPayload({
                            item_name: 'Cooking Spoon',
                            signature_trait: 'Hearth-Salt Balance',
                            experience_bonus: 0,
                            yield_bonus: 0,
                            is_broken: true,
                        }),
                    }),
                ],
                player: player(),
                searchTerm: '',
            },
            global: routeGlobal(),
        })

        const repairButton = wrapper.findAll('button').find((button) => button.text().includes('Repair Tool'))

        expect(wrapper.text()).toContain('Ready Stew')
        expect(repairButton.exists()).toBe(true)
        expect(repairButton.attributes('disabled')).toBeDefined()

        wrapper.unmount()
    })

    it('queues repeated recipe crafts and updates material counts locally from the latest result', async () => {
        vi.useFakeTimers()

        let form
        let lastOptions
        const post = vi.fn((url, options) => {
            form.processing = true
            lastOptions = options
        })
        useFormMock.mockImplementation((initial) => {
            form = {
                ...initial,
                errors: {},
                processing: false,
                post,
                delete: vi.fn(),
            }

            return form
        })

        const wrapper = mount(CraftingPanel, {
            props: {
                recipes: [
                    craftingRecipe({
                        key: 'stew',
                        label: 'Ready Stew',
                        can_craft: true,
                        ingredients: [
                            ingredient({ item_key: 'grain', item_name: 'Grain', quantity: 2, owned_quantity: 2, has_enough: true }),
                        ],
                    }),
                ],
                player: player(),
                lastResult: null,
                searchTerm: '',
            },
            global: routeGlobal(),
        })

        const craftButton = wrapper.findAll('button').find((button) => button.text().includes('Craft'))

        await craftButton.trigger('click')
        await craftButton.trigger('click')

        expect(post).toHaveBeenCalledTimes(1)

        await wrapper.setProps({
            lastResult: {
                type: 'crafting',
                recipe_key: 'stew',
                skill: 'cooking',
                items_consumed: [
                    { item_key: 'grain', quantity: 2 },
                ],
                items_created: [],
                skill_progress: skillProgress({ skill: 'cooking', skill_label: 'Cooking', level: 2 }),
            },
        })

        expect(wrapper.text()).toContain('0 / 2')

        form.processing = false
        lastOptions.onFinish()
        vi.advanceTimersByTime(0)

        expect(post).toHaveBeenCalledTimes(1)

        wrapper.unmount()
    })

    it('updates job requirements locally from the latest completion result', async () => {
        const wrapper = mount(JobsPanel, {
            props: {
                jobs: [
                    jobContract({
                        key: 'stew-job',
                        label: 'Stew Delivery',
                        requirements: [
                            ingredient({ item_key: 'stew', item_name: 'Stew', quantity: 1, owned_quantity: 1, has_enough: true }),
                        ],
                        can_complete: true,
                    }),
                ],
                lastResult: null,
                searchTerm: '',
            },
            global: routeGlobal(),
        })

        await wrapper.setProps({
            lastResult: {
                type: 'job',
                job_key: 'stew-job',
                skill: 'cooking',
                items_delivered: [
                    { item_key: 'stew', quantity: 1 },
                ],
                remaining_completions: 2,
                skill_progress: skillProgress({ skill: 'cooking', skill_label: 'Cooking', level: 2 }),
            },
        })

        expect(wrapper.text()).toContain('0 / 1')

        wrapper.unmount()
    })

    it('restores the selected job skill filter from local storage', () => {
        window.localStorage.setItem('evergather.jobs-board-state', JSON.stringify({
            selectedFilter: 'Fishing',
            selectedBoard: 'ready',
        }))

        const wrapper = mount(JobsPanel, {
            props: {
                jobs: [
                    jobContract({
                        key: 'fish-job',
                        label: 'Fishing Delivery',
                        skill: 'fishing',
                        skill_label: 'Fishing',
                        can_complete: true,
                    }),
                    jobContract({
                        key: 'cook-job',
                        label: 'Cooking Delivery',
                        skill: 'cooking',
                        skill_label: 'Cooking',
                        can_complete: true,
                    }),
                ],
                lastResult: null,
                searchTerm: '',
            },
            global: routeGlobal(),
        })

        expect(wrapper.text()).toContain('Fishing Delivery')
        expect(wrapper.text()).not.toContain('Cooking Delivery')

        wrapper.unmount()
    })

    it('updates expedition supplies locally from the latest expedition result', async () => {
        const wrapper = mount(ExpeditionsPanel, {
            props: {
                expeditions: [
                    expeditionRoute({
                        key: 'trail',
                        label: 'Trail Route',
                        supplies: [
                            ingredient({ item_key: 'ration', item_name: 'Ration', quantity: 1, owned_quantity: 1, has_enough: true }),
                        ],
                        can_start: true,
                    }),
                ],
                lastResult: null,
                searchTerm: '',
            },
            global: routeGlobal(),
        })

        await wrapper.setProps({
            lastResult: {
                type: 'expedition',
                expedition_key: 'trail',
                skill: 'exploration',
                supplies_consumed: [
                    { item_key: 'ration', quantity: 1 },
                ],
                items_awarded: [],
                skill_progress: skillProgress({ skill: 'exploration', skill_label: 'Exploration', level: 2 }),
            },
        })

        expect(wrapper.text()).toContain('0 / 1')

        wrapper.unmount()
    })

    it('restores the selected expedition skill filter from local storage', () => {
        window.localStorage.setItem('evergather.expeditions-board-state', JSON.stringify({
            selectedFilter: 'Fishing',
            selectedBoard: 'ready',
        }))

        const wrapper = mount(ExpeditionsPanel, {
            props: {
                expeditions: [
                    expeditionRoute({
                        key: 'fish-route',
                        label: 'Fishing Route',
                        skill: 'fishing',
                        skill_label: 'Fishing',
                        can_start: true,
                    }),
                    expeditionRoute({
                        key: 'explore-route',
                        label: 'Exploration Route',
                        skill: 'exploration',
                        skill_label: 'Exploration',
                        can_start: true,
                    }),
                ],
                lastResult: null,
                searchTerm: '',
            },
            global: routeGlobal(),
        })

        expect(wrapper.text()).toContain('Fishing Route')
        expect(wrapper.text()).not.toContain('Exploration Route')

        wrapper.unmount()
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

    it('restores the selected shop offer group from local storage', () => {
        window.localStorage.setItem('evergather.shop-board-state', JSON.stringify({
            selectedFilter: 'Materials',
            selectedBoard: 'buyable',
        }))

        const wrapper = mount(ShopPanel, {
            props: {
                shop: {
                    offers: [
                        shopOffer({ key: 'tool-offer', label: 'Ready Tool', category: 'Tools', kind: 'tool', can_buy: true }),
                        shopOffer({ key: 'ore-offer', label: 'Ready Ore', category: 'Materials', kind: 'item', can_buy: true }),
                    ],
                },
                searchTerm: '',
            },
            global: routeGlobal(),
        })

        expect(wrapper.text()).toContain('Ready Ore')
        expect(wrapper.text()).not.toContain('Ready Tool')

        wrapper.unmount()
    })

    it('restores the selected equipment category from local storage', () => {
        window.localStorage.setItem('evergather.equipment-board-state', JSON.stringify({
            selectedCategory: 'Crafting',
        }))

        const wrapper = mount(EquipmentPanel, {
            props: {
                equipment: [
                    equipmentTool({ tool_id: 1, item_name: 'Fishing Rod', category: 'Gathering' }),
                    equipmentTool({ tool_id: 2, item_name: 'Cooking Spoon', category: 'Crafting' }),
                ],
                toolInventory: [],
                toolRarityUpgrades: { options: [], ready_count: 0 },
                toolTierUpgrades: { options: [], ready_count: 0 },
            },
            global: routeGlobal(),
        })

        expect(wrapper.text()).toContain('Cooking Spoon')
        expect(wrapper.text()).not.toContain('Fishing Rod')

        wrapper.unmount()
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
        skill: 'fishing',
        skill_label: 'Fishing',
        skill_level: 1,
        skill_progress: {
            skill: 'fishing',
            skill_label: 'Fishing',
            level: 1,
            experience: 0,
            current_level_experience: 0,
            next_level_experience: 200,
            experience_into_level: 0,
            experience_to_next_level: 200,
            max_level: 100,
        },
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
        skill: 'fishing',
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
        skill: 'cooking',
        category: 'Cooking',
        skill_label: 'Cooking',
        required_level: 1,
        skill_level: 1,
        skill_progress: skillProgress({ skill: 'cooking', skill_label: 'Cooking' }),
        can_craft: false,
        is_unlocked: true,
        experience: 10,
        gold_cost: 0,
        ingredients: [],
        outputs: [],
        ...overrides,
    }
}

function jobContract(overrides = {}) {
    return {
        key: 'job',
        label: 'Job',
        category: 'Cooking',
        skill: 'cooking',
        skill_label: 'Cooking',
        required_level: 1,
        skill_level: 1,
        skill_progress: skillProgress({ skill: 'cooking', skill_label: 'Cooking' }),
        is_unlocked: true,
        experience: 10,
        gold: 5,
        rotation: 'daily',
        completed_in_rotation: 0,
        completion_cap: 3,
        remaining_completions: 3,
        is_demand_available: true,
        requires_acceptance: false,
        is_accepted: false,
        can_accept: false,
        progress_quantity: 0,
        progress_required: 1,
        progress_percent: 0,
        requirements: [],
        rewards: [],
        can_complete: false,
        ...overrides,
    }
}

function expeditionRoute(overrides = {}) {
    return {
        key: 'expedition',
        label: 'Expedition',
        region: 'Moonwake',
        skill: 'exploration',
        skill_label: 'Exploration',
        required_level: 1,
        skill_level: 1,
        skill_progress: skillProgress({ skill: 'exploration', skill_label: 'Exploration' }),
        is_unlocked: true,
        experience: 10,
        gold: 5,
        supplies: [],
        rewards: [],
        can_start: false,
        ...overrides,
    }
}

function ingredient(overrides = {}) {
    return {
        item_key: 'item',
        item_name: 'Item',
        quantity: 1,
        owned_quantity: 0,
        has_enough: false,
        rarity: 'common',
        quality: 'standard',
        item_class: 'material',
        material_family: 'General',
        total_weight: 1,
        tags: [],
        ...overrides,
    }
}

function equipmentTool(overrides = {}) {
    return {
        tool_id: 1,
        slot: 'tool_fishing',
        slot_label: 'Fishing Tool',
        skill: 'fishing',
        skill_label: 'Fishing',
        category: 'Gathering',
        item_key: 'fishing_tool',
        item_name: 'Fishing Tool',
        rarity: 'common',
        durability: 0,
        is_broken: true,
        experience_bonus: 0,
        yield_bonus: 0,
        rarity_progress: 0,
        origin: 'crafted',
        origin_label: 'Crafted',
        maker_name: null,
        tier_level: 1,
        upgrade_count: 0,
        tier_upgrade_count: 0,
        rarity_upgrade_attempts: 0,
        signature_trait: null,
        discipline: null,
        perks: [],
        tool_lifecycle: {
            repair: {
                missing_durability: 0,
                gold_cost: 0,
                materials: [],
                can_repair: false,
            },
            salvage: {
                can_salvage: false,
                materials: [],
            },
            can_retire: true,
        },
        ...overrides,
    }
}

function toolPayload(overrides = {}) {
    return {
        item_key: 'tool',
        item_name: 'Tool',
        signature_trait: 'Tool Trait',
        experience_bonus: 1,
        yield_bonus: 1,
        is_broken: false,
        perks: [],
        ...overrides,
    }
}

function skillProgress(overrides = {}) {
    return {
        skill: 'fishing',
        skill_label: 'Fishing',
        level: 1,
        experience: 0,
        current_level_experience: 0,
        next_level_experience: 200,
        experience_into_level: 0,
        experience_to_next_level: 200,
        max_level: 100,
        ...overrides,
    }
}

function player(overrides = {}) {
    return {
        gold: 100,
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
