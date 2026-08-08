import { mount } from '@vue/test-utils'
import MarketOrderBookPopup from '@/Pages/Bitcraft/Components/MarketOrderBookPopup.vue'

const PopupCardStub = {
    props: ['show', 'title', 'subtitle', 'eyebrow', 'maxWidth'],
    template: `
        <section>
            <header>
                <slot name="actions" />
            </header>
            <main>
                <slot />
            </main>
        </section>
    `,
}

describe('Bitcraft popups', () => {
    it('sorts market order rows by price, quantity, and line total in both directions', async () => {
        const wrapper = mount(MarketOrderBookPopup, {
            props: {
                show: true,
                claimLinkHref: () => '#',
                orderBook: {
                    item: { name: 'Astralite Pickaxe', category: 'Tool' },
                    stats: {},
                    sellOrders: [
                        marketOrder('highest-price', 'Highest Price', 300, 1),
                        marketOrder('highest-quantity', 'Highest Quantity', 20, 20),
                        marketOrder('middle', 'Middle Order', 200, 5),
                    ],
                    buyOrders: [],
                },
            },
            global: {
                stubs: {
                    Link: { template: '<a><slot /></a>' },
                    PopupCard: PopupCardStub,
                },
            },
        })

        expect(recordTitles(wrapper)).toEqual(['Highest Price', 'Middle Order', 'Highest Quantity'])
        expect(wrapper.text()).toContain('Price high')
        expect(wrapper.text()).not.toContain('Package Orders')

        await clickButton(wrapper, 'Qty high')
        expect(recordTitles(wrapper)).toEqual(['Highest Quantity', 'Middle Order', 'Highest Price'])
        expect(wrapper.text()).toContain('Qty high')

        await clickButton(wrapper, 'Qty high')
        expect(recordTitles(wrapper)).toEqual(['Highest Price', 'Middle Order', 'Highest Quantity'])
        expect(wrapper.text()).toContain('Qty low')

        await clickButton(wrapper, 'Line total high')
        expect(recordTitles(wrapper)).toEqual(['Middle Order', 'Highest Quantity', 'Highest Price'])

        await clickButton(wrapper, 'Line total high')
        expect(recordTitles(wrapper)).toEqual(['Highest Price', 'Highest Quantity', 'Middle Order'])
        expect(wrapper.text()).toContain('Line total low')
    })

    it('shows related package orders when Bitjita includes package market data', () => {
        const wrapper = mount(MarketOrderBookPopup, {
            props: {
                show: true,
                claimLinkHref: () => '#',
                orderBook: {
                    item: { name: 'Ferralith Ingot', category: 'Ingot' },
                    stats: {},
                    sellOrders: [],
                    buyOrders: [],
                    packageInfo: {
                        cargoName: 'Ferralith Ingot Package',
                        itemName: 'Ferralith Ingot',
                        ratio: 100,
                    },
                    packageSellOrders: [
                        marketOrder('package-sell', 'Package Seller', 2800, 2),
                    ],
                    packageBuyOrders: [
                        marketOrder('package-buy', 'Package Buyer', 2300, 64),
                    ],
                },
            },
            global: {
                stubs: {
                    Link: { template: '<a><slot /></a>' },
                    PopupCard: PopupCardStub,
                },
            },
        })

        expect(wrapper.text()).toContain('1 package sell')
        expect(wrapper.text()).toContain('1 package buy')
        expect(wrapper.text()).toContain('Package Orders')
        expect(wrapper.text()).toContain('Ferralith Ingot Package · 100x Ferralith Ingot')
        expect(wrapper.text()).toContain('Package Sell Orders')
        expect(wrapper.text()).toContain('Package Buy Orders')
        expect(wrapper.text()).toContain('Package price')
        expect(wrapper.text()).toContain('Packages')
        expect(recordTitles(wrapper)).toContain('Package Seller')
        expect(recordTitles(wrapper)).toContain('Package Buyer')
    })

    it('shows order book summary, filters table rows, calculates buy cost, and groups regions', async () => {
        const wrapper = mount(MarketOrderBookPopup, {
            props: {
                show: true,
                claimLinkHref: () => '#',
                orderBook: {
                    item: { name: 'Rathium Ore Package', category: 'Package' },
                    stats: {
                        lowestSell: 100,
                        highestBuy: 80,
                        lastUpdated: '2026-08-08 13:15:00+00',
                        recentSellOrders: 1,
                        recentBuyOrders: 2,
                    },
                    sellOrders: [
                        marketOrder('sell-1', 'Jita', 100, 5, {
                            ownerUsername: 'Alice',
                            regionName: 'Solmere',
                            updatedAt: '2026-08-08 13:15:00+00',
                        }),
                        marketOrder('sell-2', 'Notsolis', 120, 10, {
                            ownerUsername: 'Bob',
                            regionName: 'Lumethis',
                            updatedAt: '2026-08-08 12:15:00+00',
                        }),
                    ],
                    buyOrders: [
                        marketOrder('buy-1', 'Buyer Hub', 80, 7, {
                            ownerUsername: 'Cara',
                            side: 'buy',
                            regionName: 'Solmere',
                            updatedAt: '2026-08-08 11:15:00+00',
                        }),
                    ],
                },
            },
            global: {
                stubs: {
                    Link: { template: '<a><slot /></a>' },
                    PopupCard: PopupCardStub,
                },
            },
        })

        expect(wrapper.find('[data-test="order-book-summary"]').text()).toContain('Best sell')
        expect(wrapper.text()).toContain('Best buy')
        expect(wrapper.text()).toContain('Spread')
        expect(wrapper.text()).toContain('Liquidity')
        expect(wrapper.text()).toContain('By Region')
        expect(wrapper.text()).toContain('Solmere')
        expect(wrapper.text()).toContain('Lumethis')

        await wrapper.find('input[aria-label="Filter seller"]').setValue('Alice')

        expect(recordTitles(wrapper)).toContain('Jita')
        expect(recordTitles(wrapper)).not.toContain('Notsolis')

        await wrapper.find('input[placeholder="quantity"]').setValue('6')

        expect(wrapper.find('[data-test="cost-to-buy"]').text()).toContain('500 hex')
        expect(wrapper.find('[data-test="order-book-detail"]').text()).toContain('Largest sell order')
    })

    it('uses the shared order book popup for barter stall sell and buy listings', async () => {
        const wrapper = mount(MarketOrderBookPopup, {
            props: {
                show: true,
                claimLinkHref: () => '#',
                orderBook: {
                    item: { name: 'Vibrant Plank', category: 'Plank' },
                    stats: {},
                    sellOrders: [
                        barterOrder('highest-price', 'Highest Price', 300, 1),
                        barterOrder('highest-quantity', 'Highest Quantity', 20, 20),
                        barterOrder('middle', 'Middle Listing', 200, 5, {
                            offerSummary: '1 Vibrant Plank',
                            requiredSummary: '200 Hex Coin',
                            stallBuildingName: 'Barter Stall',
                            stallEntityId: 'stall-100',
                            claimEntityId: 'claim-100',
                            priceCurrency: 'Hex Coin',
                        }),
                    ],
                    buyOrders: [
                        barterOrder('buy-order', 'Buying Stall', 50, 8, { side: 'buy' }),
                    ],
                },
            },
            global: {
                stubs: {
                    Link: { template: '<a><slot /></a>' },
                    PopupCard: PopupCardStub,
                },
            },
        })

        expect(recordTitles(wrapper).slice(0, 3)).toEqual(['Highest Price', 'Middle Listing', 'Highest Quantity'])
        expect(wrapper.text()).toContain('Price high')

        await clickButton(wrapper, 'Qty high')
        expect(recordTitles(wrapper).slice(0, 3)).toEqual(['Highest Quantity', 'Middle Listing', 'Highest Price'])
        expect(wrapper.text()).toContain('Qty high')

        await clickButton(wrapper, 'Qty high')
        expect(recordTitles(wrapper).slice(0, 3)).toEqual(['Highest Price', 'Middle Listing', 'Highest Quantity'])
        expect(wrapper.text()).toContain('Qty low')

        await clickButton(wrapper, 'Line total high')
        expect(recordTitles(wrapper).slice(0, 3)).toEqual(['Middle Listing', 'Highest Quantity', 'Highest Price'])
        expect(wrapper.text()).toContain('1,000')
        expect(wrapper.text()).toContain('Buy Orders')
        expect(wrapper.text()).toContain('Buying Stall')
        expect(wrapper.text()).toContain('Barter Stall · stall-100 · Claim claim-100')
        expect(wrapper.text()).toContain('Offers: 1 Vibrant Plank')

        await clickButton(wrapper, 'Line total high')
        expect(recordTitles(wrapper).slice(0, 3)).toEqual(['Highest Price', 'Highest Quantity', 'Middle Listing'])
        expect(wrapper.text()).toContain('Line total low')
    })

    it('shows an infinite barter quantity as an infinite line total', () => {
        const wrapper = mount(MarketOrderBookPopup, {
            props: {
                show: true,
                claimLinkHref: () => '#',
                orderBook: {
                    item: { name: 'Vibrant Plank', category: 'Plank' },
                    stats: {},
                    sellOrders: [
                        barterOrder('infinite-sell', 'Unlimited Stall', 7, 2147483647, {
                            regionName: 'Solmere',
                        }),
                    ],
                    buyOrders: [],
                },
            },
            global: {
                stubs: {
                    Link: { template: '<a><slot /></a>' },
                    PopupCard: PopupCardStub,
                },
            },
        })

        expect(wrapper.text()).toContain('∞')
        expect(wrapper.find('[data-test="order-book-summary"]').text()).toContain('Liquidity∞∞ for sale')
        expect(wrapper.find('[data-test="order-book-regions"]').text()).toContain('Solmere')
        expect(wrapper.find('[data-test="order-book-regions"]').text()).toContain('∞')
        expect(wrapper.find('[data-test="order-book-detail"]').text()).toContain('Sell available∞')
        expect(wrapper.text()).not.toContain('15,032,385,529')
    })
})

function marketOrder(entityId, claimName, price, quantity, overrides = {}) {
    return {
        entityId,
        claimName,
        side: 'sell',
        price,
        quantity,
        ...overrides,
    }
}

function barterOrder(entityId, locationName, price, quantity, overrides = {}) {
    return {
        entityId,
        locationName,
        side: 'sell',
        price,
        quantity,
        priceCurrency: 'Hex Coin',
        ...overrides,
    }
}

function recordTitles(wrapper) {
    return wrapper.findAll('.index-record__title').map((title) => title.text())
}

async function clickButton(wrapper, label) {
    await wrapper.findAll('button').find((button) => button.text() === label).trigger('click')
}
