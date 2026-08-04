import { mount } from '@vue/test-utils'
import ProgressionPanel from '@/Pages/ConnectedRealms/ProgressionPanel.vue'

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

describe('Connected Realms progression panel', () => {
    beforeEach(() => {
        useFormMock.mockReset()
        useFormMock.mockImplementation((initial) => ({
            ...initial,
            errors: {},
            processing: false,
            post: vi.fn(),
            put: vi.fn(),
        }))

        global.route = vi.fn((name) => name)
    })

    it('keeps the achievement board focused without an archive mode', async () => {
        const wrapper = mountPanel()

        expect(wrapper.text()).toContain('Claim')
        expect(wrapper.text()).toContain('First Steps')
        expect(wrapper.text()).not.toContain('Locked Milestone 13')
        expect(wrapper.text()).not.toContain('Archive')

        await wrapper.findAll('button').find((button) => button.text().includes('Next')).trigger('click')

        expect(wrapper.text()).toContain('Locked Milestone 12')
        expect(wrapper.text()).not.toContain('Locked Milestone 25')

        await wrapper.findAll('button').find((button) => button.text().includes('Show More')).trigger('click')

        expect(wrapper.text()).toContain('Locked Milestone 20')
    })

    it('opens on the next milestone board when nothing is claimable', () => {
        const progression = progressionFixture()
        progression.achievements[0].claimed = true
        progression.achievements[0].can_claim = false

        const wrapper = mountPanel({ progression })

        expect(wrapper.text()).toContain('Closest locked milestones')
        expect(wrapper.text()).toContain('Locked Milestone 1')
        expect(wrapper.text()).not.toContain('Locked Milestone 13')
    })
})

function mountPanel(props = {}) {
    return mount(ProgressionPanel, {
        props: {
            progression: progressionFixture(),
            summary: {
                total_experience: 120,
            },
            ...props,
        },
        global: {
            config: {
                globalProperties: {
                    route: global.route,
                },
            },
        },
    })
}

function progressionFixture() {
    return {
        account_level: 3,
        next_account_level_experience: 500,
        achievements: [
            {
                key: 'first_steps',
                label: 'First Steps',
                description: 'Complete your first action.',
                category: 'Gathering',
                category_key: 'gathering',
                unlocked: true,
                claimed: false,
                can_claim: true,
                reward: {
                    title: 'Trailmarked',
                    gold: 15,
                },
            },
            ...Array.from({ length: 25 }, (_, index) => ({
                key: `locked_${index + 1}`,
                label: `Locked Milestone ${index + 1}`,
                description: 'A future achievement.',
                category: index % 2 === 0 ? 'Gathering' : 'Trade',
                category_key: index % 2 === 0 ? 'gathering' : 'trade',
                unlocked: false,
                claimed: false,
                can_claim: false,
                reward: {
                    title: `Title ${index + 1}`,
                    gold: index + 1,
                },
            })),
        ],
        claimed_rewards: [],
        reward_options: {
            titles: [],
        },
        reward_loadout: {
            title_claim_key: null,
            title_label: null,
            has_equipped: false,
        },
        stats: {
            total_activity: 1,
            actions: 1,
            trade_activity: 0,
        },
    }
}
