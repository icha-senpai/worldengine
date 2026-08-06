import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import Market from '@/Pages/Bitcraft/Market.vue'

const { routerGetMock } = vi.hoisted(() => ({
    routerGetMock: vi.fn(),
}))

vi.mock('@inertiajs/vue3', async () => {
    const actual = await vi.importActual('@inertiajs/vue3')

    return {
        ...actual,
        router: {
            get: routerGetMock,
        },
        Link: {
            template: '<a><slot /></a>',
        },
    }
})

describe('Bitcraft market page popups', () => {
    beforeEach(() => {
        routerGetMock.mockReset()
        global.route = vi.fn((name, params) => ({ name, params }))
        vi.stubGlobal('fetch', vi.fn(async () => ({
            ok: true,
            json: async () => ({
                orderBook: orderBook(),
            }),
        })))
    })

    afterEach(() => {
        vi.unstubAllGlobals()
    })

    it('does not reopen market or barter popups from item filters on refresh', () => {
        const marketWrapper = mountPage({
            filters: {
                q: 'Pickaxe',
                itemId: 10,
                itemKind: 'item',
            },
            market: marketPayload({
                orderBook: orderBook(),
            }),
        })

        expect(marketWrapper.find('[data-test="market-popup"]').exists()).toBe(false)

        const barterWrapper = mountPage({
            tool: barterTool(),
            filters: {
                q: 'Pickaxe',
                itemId: 10,
                itemKind: 'item',
                side: 'sell',
            },
            market: marketPayload({
                listings: [
                    barterListing(),
                ],
            }),
        })

        expect(barterWrapper.find('[data-test="barter-popup"]').exists()).toBe(false)
    })

    it('opens a market popup from a clicked item without writing the item to the page URL', async () => {
        const wrapper = mountPage()
        await nextTick()

        await wrapper.findAll('button').find((button) => button.text() === 'Sell 3').trigger('click')
        await nextTick()

        expect(routerGetMock).not.toHaveBeenCalled()
        expect(global.fetch).toHaveBeenCalledWith(
            { name: 'bitcraft.market.order-book', params: { itemId: 10, itemKind: 'item' } },
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            },
        )
        expect(wrapper.find('[data-test="market-popup"]').exists()).toBe(true)
    })
})

function mountPage(overrides = {}) {
    return mount(Market, {
        props: {
            filters: {},
            regions: [],
            market: marketPayload(),
            tool: marketTool(),
            error: null,
            cache: {
                updatedAt: null,
                sources: [],
            },
            ...overrides,
        },
        global: {
            stubs: {
                AuthenticatedLayout: {
                    template: '<div><slot name="header" /><slot /></div>',
                },
                AppButton: {
                    template: '<button><slot /></button>',
                },
                SelectInput: {
                    template: '<select><slot /></select>',
                },
                TextInput: {
                    props: ['modelValue'],
                    emits: ['update:modelValue'],
                    template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
                },
                MarketOrderBookPopup: {
                    props: ['show'],
                    template: '<div v-if="show" data-test="market-popup" />',
                },
                BarterListingsPopup: {
                    props: ['show'],
                    template: '<div v-if="show" data-test="barter-popup" />',
                },
            },
        },
    })
}

function marketPayload(overrides = {}) {
    return {
        items: [
            marketItem(),
        ],
        categories: [],
        claims: [],
        tradeBuildings: [],
        listings: [],
        empires: [],
        orderBook: null,
        metrics: {},
        ...overrides,
    }
}

function marketItem() {
    return {
        id: 10,
        kind: 'item',
        type: 'item',
        name: 'Astralite Pickaxe',
        category: 'Tool',
        sellOrderCount: 3,
        buyOrderCount: 2,
        lowestSellPrice: 1200,
        highestBuyPrice: 900,
    }
}

function orderBook() {
    return {
        item: marketItem(),
        stats: {},
        sellOrders: [
            {
                entityId: 'sell-1',
                claimName: 'Jita',
                price: 1200,
                quantity: 3,
            },
        ],
        buyOrders: [],
    }
}

function barterListing() {
    return {
        entityId: 'barter-1',
        itemId: 10,
        itemType: 'item',
        itemName: 'Astralite Pickaxe',
        side: 'sell',
        price: 1200,
        quantity: 3,
    }
}

function marketTool() {
    return {
        key: 'market',
        routeName: 'bitcraft.market',
        title: 'Market Finder',
        subtitle: 'Find market trades by item, claim, and region.',
        claimIdLabel: 'Claim / market ID',
        claimSearchLabel: 'Claim search',
        claimSectionTitle: 'Markets',
        claimSectionSubtitle: 'Matching claims',
        claimEmptyLabel: 'Search a name or region to find claims.',
        clearLabel: 'Clear claim',
    }
}

function barterTool() {
    return {
        ...marketTool(),
        key: 'barter-stalls',
        routeName: 'bitcraft.barter-stalls',
        title: 'Barter Stall Finder',
    }
}
