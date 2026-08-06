import { mount } from '@vue/test-utils'
import BarterListingsPopup from '@/Pages/Bitcraft/Components/BarterListingsPopup.vue'
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

    it('sorts barter listings by price, quantity, and line total in both directions', async () => {
        const wrapper = mount(BarterListingsPopup, {
            props: {
                show: true,
                item: { name: 'Vibrant Plank' },
                side: '',
                sideOptions: [],
                listings: [
                    barterListing('highest-price', 'Highest Price', 300, 1),
                    barterListing('highest-quantity', 'Highest Quantity', 20, 20),
                    barterListing('middle', 'Middle Listing', 200, 5),
                ],
            },
            global: {
                stubs: {
                    PopupCard: PopupCardStub,
                },
            },
        })

        expect(recordTitles(wrapper)).toEqual(['Highest Price', 'Middle Listing', 'Highest Quantity'])
        expect(wrapper.text()).toContain('Price high')

        await clickButton(wrapper, 'Qty high')
        expect(recordTitles(wrapper)).toEqual(['Highest Quantity', 'Middle Listing', 'Highest Price'])
        expect(wrapper.text()).toContain('Qty high')

        await clickButton(wrapper, 'Qty high')
        expect(recordTitles(wrapper)).toEqual(['Highest Price', 'Middle Listing', 'Highest Quantity'])
        expect(wrapper.text()).toContain('Qty low')

        await clickButton(wrapper, 'Line total high')
        expect(recordTitles(wrapper)).toEqual(['Middle Listing', 'Highest Quantity', 'Highest Price'])
        expect(wrapper.text()).toContain('1,000')

        await clickButton(wrapper, 'Line total high')
        expect(recordTitles(wrapper)).toEqual(['Highest Price', 'Highest Quantity', 'Middle Listing'])
        expect(wrapper.text()).toContain('Line total low')
    })
})

function marketOrder(entityId, claimName, price, quantity) {
    return {
        entityId,
        claimName,
        price,
        quantity,
    }
}

function barterListing(entityId, itemName, price, quantity) {
    return {
        entityId,
        itemName,
        side: 'sell',
        price,
        quantity,
        priceCurrency: 'Hex Coin',
    }
}

function recordTitles(wrapper) {
    return wrapper.findAll('.index-record__title').map((title) => title.text())
}

async function clickButton(wrapper, label) {
    await wrapper.findAll('button').find((button) => button.text() === label).trigger('click')
}
