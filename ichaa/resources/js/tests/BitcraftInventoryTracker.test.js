import { mount } from '@vue/test-utils'
import InventoryTracker from '@/Pages/Bitcraft/InventoryTracker.vue'

const { routerGetMock } = vi.hoisted(() => ({
    routerGetMock: vi.fn(),
}))

vi.mock('@inertiajs/vue3', () => ({
    router: {
        get: routerGetMock,
    },
}))

describe('Bitcraft inventory tracker', () => {
    beforeEach(() => {
        routerGetMock.mockReset()
        localStorage.clear()
        window.history.pushState({}, '', '/')
    })

    afterEach(() => {
        localStorage.clear()
        window.history.pushState({}, '', '/')
    })

    it('uses Brico tier badges and tier color variables for tracked items', () => {
        const wrapper = mountTracker()
        const trackedItems = wrapper.findAll('.inventory-tracker-widget__tracked-item')

        expect(trackedItems[0].find('.bitcraft-tier-badge img').attributes('src'))
            .toBe('/bitcraft-assets/UI/Badges/badge-tier-number-5.webp')
        expect(trackedItems[0].find('.bitcraft-tier-badge img').attributes('alt')).toBe('T5')
        expect(trackedItems[0].attributes('style')).toContain('#814F87')

        expect(trackedItems[1].find('.bitcraft-tier-badge__text').text()).toBe('0')
        expect(trackedItems[1].attributes('style')).toContain('#413A64')

        expect(trackedItems[2].find('.bitcraft-tier-badge__text').text()).toBe('-1')
        expect(trackedItems[2].attributes('style')).toContain('#413A64')
    })
})

function mountTracker(overrides = {}) {
    return mount(InventoryTracker, {
        props: {
            filters: {},
            snapshotUrl: '/inventory-snapshot',
            snapshot: {
                tracker: {
                    items: [
                        trackerItem({
                            key: 'item:10',
                            name: 'Astralite Pickaxe',
                            tier: 5,
                            rarity: 'Rare',
                        }),
                        trackerItem({
                            key: 'item:11',
                            name: 'Tier Zero Material',
                            tier: 0,
                            rarity: 'Default',
                        }),
                        trackerItem({
                            key: 'item:12',
                            name: 'Untiered Material',
                            tier: -1,
                            rarity: 'Common',
                        }),
                    ],
                },
                options: [],
                error: null,
                sampledAt: null,
            },
            ...overrides,
        },
        global: {
            stubs: {
                WidgetPageShell: {
                    template: '<div><slot /></div>',
                },
                WidgetThemeControls: {
                    template: '<div />',
                },
            },
        },
    })
}

function trackerItem(overrides = {}) {
    return {
        key: 'item:1',
        name: 'Tracked Item',
        kind: 'item',
        tag: 'tracked_item',
        rarity: 'Common',
        tier: 1,
        quantity: 4,
        need: 10,
        remaining: 6,
        progressPercent: 40,
        sources: [],
        ...overrides,
    }
}
