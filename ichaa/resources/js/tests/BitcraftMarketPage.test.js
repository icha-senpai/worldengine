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
        window.history.pushState({}, '', '/')
        localStorage.clear()
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
        localStorage.clear()
        window.history.pushState({}, '', '/')
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

        expect(barterWrapper.find('[data-test="market-popup"]').exists()).toBe(false)
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

    it('keeps buy-order searches focused on items that actually have buy orders', () => {
        const wrapper = mountPage({
            filters: {
                hasBuyOrders: true,
                region: 'Marowik',
            },
            market: marketPayload({
                items: [
                    marketItem({
                        id: 11,
                        name: 'No Buy Order Item',
                        buyOrderCount: 0,
                        highestBuyPrice: null,
                    }),
                    marketItem({
                        id: 12,
                        name: 'Lower Buy Order Item',
                        buyOrderCount: 9,
                        buyOrderQuantity: 25,
                        highestBuyQuantity: 6,
                        highestBuyLineTotal: 2400,
                        highestBuyPrice: 400,
                    }),
                    marketItem({
                        id: 13,
                        name: 'Higher Buy Order Item',
                        buyOrderCount: 2,
                        buyOrderQuantity: 11,
                        highestBuyQuantity: 3,
                        highestBuyLineTotal: 2850,
                        highestBuyPrice: 950,
                    }),
                ],
            }),
        })

        const explorerTitles = wrapper
            .findAll('[data-test="market-item-card"] .index-record__title')
            .map((title) => title.text())

        expect(explorerTitles).toEqual([
            'Higher Buy Order Item',
            'Lower Buy Order Item',
        ])
        expect(wrapper.findAll('[data-test="market-item-card"]').length).toBe(2)
        expect(wrapper.text()).toContain('2 items found')
        expect(wrapper.text()).toContain('High quantity6')
        expect(wrapper.text()).toContain('High line2,400')
        expect(wrapper.text()).toContain('High qty 6')
        expect(wrapper.text()).not.toContain('No Buy Order Item')
    })

    it('fills main market card stats after hovering a card', async () => {
        global.fetch = vi.fn(async () => ({
            ok: true,
            json: async () => ({
                orderBook: orderBook({
                    stats: {
                        lowestSell: 1200,
                        highestBuy: 900,
                        highestBuyQuantity: 4,
                        highestBuyLineTotal: 3600,
                    },
                }),
            }),
        }))

        const wrapper = mountPage({
            market: marketPayload({
                items: [
                    marketItem({
                        lowestSellPrice: null,
                        highestBuyPrice: null,
                        highestBuyQuantity: null,
                        highestBuyLineTotal: null,
                    }),
                ],
            }),
        })

        expect(global.fetch).not.toHaveBeenCalled()
        expect(wrapper.text()).toContain('Lowest sell—')
        expect(wrapper.text()).toContain('Highest buy—')

        await wrapper.find('[data-test="market-item-card"]').trigger('pointerenter')

        await vi.waitFor(() => {
            expect(wrapper.text()).toContain('Lowest sell1,200')
            expect(wrapper.text()).toContain('Highest buy900')
            expect(wrapper.text()).toContain('High quantity4')
            expect(wrapper.text()).toContain('High line3,600')
        })
    })

    it('prefetches only hovered or focused market cards with orders', async () => {
        global.fetch = vi.fn(async () => ({
            ok: true,
            json: async () => ({
                orderBook: orderBook(),
            }),
        }))

        const wrapper = mountPage({
            market: marketPayload({
                items: [1, 2, 3, 4, 5].map((id) => marketItem({
                    id,
                    name: `Visible Item ${id}`,
                    sellOrderCount: 1,
                    buyOrderCount: 0,
                })),
            }),
        })

        await nextTick()

        expect(global.fetch).not.toHaveBeenCalled()

        const cards = wrapper.findAll('[data-test="market-item-card"]')

        await cards[0].trigger('pointerenter')
        await cards[4].trigger('focusin')

        await vi.waitFor(() => {
            expect(global.fetch).toHaveBeenCalledTimes(2)
        })

        expect(global.fetch.mock.calls.map(([request]) => request.params.itemId)).toEqual([1, 5])
    })

    it('prefetches only hovered or focused barter cards with orders', async () => {
        global.fetch = vi.fn(async () => ({
            ok: true,
            json: async () => ({
                listings: [
                    barterListing(),
                ],
            }),
        }))

        const wrapper = mountPage({
            tool: barterTool(),
            market: marketPayload({
                listings: [],
                items: [1, 2, 3, 4, 5].map((id) => marketItem({
                    id,
                    name: `Visible Barter Item ${id}`,
                    sellOrderCount: 1,
                    buyOrderCount: 0,
                })),
            }),
        })

        await nextTick()

        expect(global.fetch).not.toHaveBeenCalled()

        const cards = wrapper.findAll('[data-test="market-item-card"]')

        await cards[0].trigger('pointerenter')
        await cards[4].trigger('focusin')

        await vi.waitFor(() => {
            expect(global.fetch).toHaveBeenCalledTimes(2)
        })

        expect(global.fetch.mock.calls.map(([request]) => request.name)).toEqual([
            'bitcraft.barter-stalls.listings',
            'bitcraft.barter-stalls.listings',
        ])
        expect(global.fetch.mock.calls.map(([request]) => request.params.itemId)).toEqual([1, 5])
    })

    it('opens barter listings from the lazy card fetch', async () => {
        global.fetch = vi.fn(async () => ({
            ok: true,
            json: async () => ({
                listings: [
                    barterListing(),
                ],
            }),
        }))

        const wrapper = mountPage({
            tool: barterTool(),
            market: marketPayload({
                listings: [],
            }),
        })

        await wrapper.findAll('button').find((button) => button.text() === 'Sell 3').trigger('click')

        await vi.waitFor(() => {
            expect(global.fetch).toHaveBeenCalledTimes(1)
            expect(wrapper.find('[data-test="market-popup"]').exists()).toBe(true)
        })

        expect(global.fetch.mock.calls[0][0].name).toBe('bitcraft.barter-stalls.listings')
        expect(global.fetch.mock.calls[0][0].params.itemId).toBe(10)
    })

    it('sorts normal market explorer cards by buy price, count, and single-order quantity', async () => {
        const wrapper = mountPage({
            filters: {
                hasBuyOrders: true,
                region: 'Marowik',
            },
            market: marketPayload({
                items: [
                    marketItem({
                        id: 11,
                        name: 'High Price Item',
                        buyOrderCount: 1,
                        buyOrderQuantity: 25,
                        highestBuyPrice: 900,
                        lowestBuyPrice: 900,
                        highestBuyQuantity: 5,
                        highestBuyLineTotal: 4500,
                        lowestBuyQuantity: 5,
                        lowestBuyLineTotal: 4500,
                        largestBuyOrderPrice: 500,
                        largestBuyOrderQuantity: 20,
                        largestBuyOrderLineTotal: 10000,
                        smallestBuyOrderPrice: 900,
                        smallestBuyOrderQuantity: 5,
                        smallestBuyOrderLineTotal: 4500,
                    }),
                    marketItem({
                        id: 12,
                        name: 'High Count Item',
                        buyOrderCount: 6,
                        buyOrderQuantity: 30,
                        highestBuyPrice: 500,
                        lowestBuyPrice: 200,
                        highestBuyQuantity: 9,
                        highestBuyLineTotal: 4500,
                        lowestBuyQuantity: 2,
                        lowestBuyLineTotal: 400,
                        largestBuyOrderPrice: 500,
                        largestBuyOrderQuantity: 9,
                        largestBuyOrderLineTotal: 4500,
                        smallestBuyOrderPrice: 200,
                        smallestBuyOrderQuantity: 2,
                        smallestBuyOrderLineTotal: 400,
                    }),
                    marketItem({
                        id: 13,
                        name: 'High Quantity Item',
                        buyOrderCount: 2,
                        buyOrderQuantity: 44,
                        highestBuyPrice: 700,
                        lowestBuyPrice: 100,
                        highestBuyQuantity: 4,
                        highestBuyLineTotal: 2800,
                        lowestBuyQuantity: 40,
                        lowestBuyLineTotal: 4000,
                        largestBuyOrderPrice: 100,
                        largestBuyOrderQuantity: 40,
                        largestBuyOrderLineTotal: 4000,
                        smallestBuyOrderPrice: 700,
                        smallestBuyOrderQuantity: 4,
                        smallestBuyOrderLineTotal: 2800,
                    }),
                ],
            }),
        })

        expect(marketItemCardTitles(wrapper)).toEqual([
            'High Price Item',
            'High Quantity Item',
            'High Count Item',
        ])
        expect(marketItemCardRows(wrapper)[0]).toContain('Highest buy900')
        expect(marketItemCardRows(wrapper)[0]).toContain('High qty 5')
        expect(marketItemCardRows(wrapper)[0]).toContain('High line4,500')

        await sortButton(wrapper, 'Buy high').trigger('click')

        expect(marketItemCardTitles(wrapper)).toEqual([
            'High Quantity Item',
            'High Count Item',
            'High Price Item',
        ])
        expect(marketItemCardRows(wrapper)[0]).toContain('Highest buy700')
        expect(marketItemCardRows(wrapper)[0]).toContain('Low qty 40')
        expect(marketItemCardRows(wrapper)[0]).toContain('Low line4,000')

        await sortButton(wrapper, 'Quantity high').trigger('click')

        expect(marketItemCardTitles(wrapper)).toEqual([
            'High Quantity Item',
            'High Price Item',
            'High Count Item',
        ])
        expect(marketItemCardRows(wrapper)[0]).toContain('Highest buy700')
        expect(marketItemCardRows(wrapper)[0]).toContain('Qty high qty 40')
        expect(marketItemCardRows(wrapper)[0]).toContain('Qty high line4,000')

        await sortButton(wrapper, 'Quantity high').trigger('click')

        expect(marketItemCardTitles(wrapper)).toEqual([
            'High Count Item',
            'High Quantity Item',
            'High Price Item',
        ])
        expect(marketItemCardRows(wrapper)[0]).toContain('Highest buy500')
        expect(marketItemCardRows(wrapper)[0]).toContain('Qty low qty 2')
        expect(marketItemCardRows(wrapper)[0]).toContain('Qty low line400')

        await sortButton(wrapper, 'Count high').trigger('click')

        expect(marketItemCardTitles(wrapper)).toEqual([
            'High Count Item',
            'High Quantity Item',
            'High Price Item',
        ])
    })

    it('uses the normal market explorer cards for claim and empire buy-order searches', () => {
        const claimWrapper = mountPage({
            filters: {
                hasBuyOrders: true,
                claimQ: 'Jita',
            },
            market: marketPayload({
                items: [
                    marketItem({
                        id: 14,
                        name: 'Claim Buy Item',
                        buyOrderCount: 2,
                        highestBuyPrice: 750,
                    }),
                ],
            }),
        })

        expect(claimWrapper.find('[data-test="region-buy-order-item"]').exists()).toBe(false)
        expect(marketItemCardTitles(claimWrapper)).toEqual(['Claim Buy Item'])

        const empireWrapper = mountPage({
            filters: {
                hasBuyOrders: true,
                empire: 'Earth Kingdom',
            },
            market: marketPayload({
                items: [
                    marketItem({
                        id: 15,
                        name: 'Empire Buy Item',
                        buyOrderCount: 3,
                        highestBuyPrice: 650,
                    }),
                ],
            }),
        })

        expect(empireWrapper.find('[data-test="region-buy-order-item"]').exists()).toBe(false)
        expect(marketItemCardTitles(empireWrapper)).toEqual(['Empire Buy Item'])
    })

    it('uses the buy-order URL flag when filter props do not include it yet', () => {
        window.history.pushState({}, '', '/datacrypt/bitcraft/market?hasBuyOrders=1&region=Marowik')

        const wrapper = mountPage({
            filters: {
                region: 'Marowik',
            },
            market: marketPayload({
                items: [
                    marketItem({
                        id: 11,
                        name: 'No Buy Order Item',
                        buyOrderCount: 0,
                        highestBuyPrice: null,
                    }),
                    marketItem({
                        id: 12,
                        name: 'Actual Buy Order Item',
                        buyOrderCount: 1,
                        highestBuyPrice: 400,
                    }),
                ],
            }),
        })

        const explorerTitles = wrapper
            .findAll('[data-test="market-item-card"] .index-record__title')
            .map((title) => title.text())

        expect(explorerTitles).toEqual(['Actual Buy Order Item'])
        expect(wrapper.text()).toContain('1 item found')
        expect(wrapper.text()).not.toContain('No Buy Order Item')
    })

    it('does not render the removed market intelligence panel or watch controls', () => {
        const wrapper = mountPage({
            market: marketPayload({
                items: [
                    marketItem({
                        id: 21,
                        name: 'Profitable Plank',
                        lowestSellPrice: 100,
                        highestBuyPrice: 175,
                        sellOrderCount: 2,
                        buyOrderCount: 2,
                        sellOrderQuantity: 10,
                        buyOrderQuantity: 8,
                    }),
                    marketItem({
                        id: 23,
                        name: 'Phantom Bid Gap',
                        lowestSellPrice: 50,
                        highestBuyPrice: 500,
                        sellOrderCount: 0,
                        buyOrderCount: 4,
                        sellOrderQuantity: 0,
                        buyOrderQuantity: 25,
                    }),
                    marketItem({
                        id: 24,
                        name: 'Zero Price Trap',
                        lowestSellPrice: 0,
                        highestBuyPrice: 500,
                        sellOrderCount: 2,
                        buyOrderCount: 2,
                        sellOrderQuantity: 10,
                        buyOrderQuantity: 10,
                    }),
                    marketItem({
                        id: 22,
                        name: 'Busy Ore',
                        lowestSellPrice: 300,
                        highestBuyPrice: 250,
                        sellOrderQuantity: 120,
                        buyOrderQuantity: 40,
                        sellOrderCount: 4,
                        buyOrderCount: 3,
                    }),
                ],
                listings: [
                    {
                        entityId: 'listing-1',
                        itemId: 21,
                        itemType: 'item',
                        itemName: 'Profitable Plank',
                        side: 'sell',
                        price: 100,
                        quantity: 10,
                        claimEntityId: 'claim-1',
                        claimName: 'Jita',
                        ownerUsername: 'Trader',
                        regionName: 'Solmere',
                        updatedAt: '2026-08-08 13:15:00+00',
                    },
                ],
            }),
        })

        expect(wrapper.find('[data-test="market-dashboard"]').exists()).toBe(false)
        expect(wrapper.text()).not.toContain('Top Deals')
        expect(wrapper.text()).not.toContain('Most Listed')
        expect(wrapper.text()).not.toContain('Trade Hubs')
        expect(wrapper.text()).not.toContain('Recent Order Updates')
        expect(wrapper.find('[aria-label="Add Profitable Plank to watchlist"]').exists()).toBe(false)
        expect(localStorage.getItem('bitcraft:market:watchlist')).toBeNull()
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

function marketItem(overrides = {}) {
    return {
        id: 10,
        kind: 'item',
        type: 'item',
        name: 'Astralite Pickaxe',
        category: 'Tool',
        sellOrderCount: 3,
        buyOrderCount: 2,
        sellOrderQuantity: 6,
        buyOrderQuantity: 4,
        lowestSellPrice: 1200,
        highestBuyPrice: 900,
        lowestBuyPrice: 850,
        highestBuyQuantity: 4,
        highestBuyLineTotal: 3600,
        lowestBuyQuantity: 1,
        lowestBuyLineTotal: 850,
        largestBuyOrderPrice: 900,
        largestBuyOrderQuantity: 4,
        largestBuyOrderLineTotal: 3600,
        smallestBuyOrderPrice: 850,
        smallestBuyOrderQuantity: 1,
        smallestBuyOrderLineTotal: 850,
        ...overrides,
    }
}

function marketItemCardTitles(wrapper) {
    return wrapper
        .findAll('[data-test="market-item-card"] .index-record__title')
        .map((title) => title.text())
}

function marketItemCardRows(wrapper) {
    return wrapper
        .findAll('[data-test="market-item-card"]')
        .map((row) => row.text())
}

function sortButton(wrapper, text) {
    return wrapper
        .findAll('[data-test="region-buy-sort"]')
        .find((button) => button.text() === text)
}

function orderBook(overrides = {}) {
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
        ...overrides,
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
