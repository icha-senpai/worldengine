import { flushPromises, mount } from '@vue/test-utils'
import GuideContent from '@/Pages/Bitcraft/Guides/GuideContent.vue'
import RichTextEditor from '@/Components/scaffold/RichTextEditor.vue'
import GuideMarketPrices from '@/Pages/Bitcraft/Guides/GuideMarketPrices.vue'
import { guideItemExtensions } from '@/Pages/Bitcraft/Guides/bitcraftItem'
import { estimateGathering, gatheringDefaults } from '@/Pages/Bitcraft/gatheringEstimates'

const item = { id: 123, kind: 'item', name: 'Copper Ore', quantity: 2 }
const crafting = {
    item,
    recipes: [{ id: 7, recipeName: 'Crush ore', outputQuantity: 2, timeRequirement: 4,
        craftedItems: [item], consumedItems: [{ id: 456, kind: 'item', name: 'Raw Ore', quantity: 3 }] }],
    snapshot: { generatedAt: '2026-10-02T12:00:00Z' },
}
const entry = {
    id: 8, name: 'Mine ore', timeRequirement: 10, resource: { maxHealth: 15 },
    outputs: [{ ...item, quantity: 1, probability: 0.5 }],
    consumedItems: [], experiencePerProgress: { quantity: 2 },
}
const documentFor = (activity = 'crafting', settings = { quantity: 1 }) => ({
    type: 'doc', content: [{ type: 'bitcraftActivity', attrs: {
        activity, recipeId: activity === 'crafting' ? 7 : 8, itemId: activity === 'crafting' ? 123 : null,
        kind: 'item', name: activity === 'crafting' ? 'Crush ore' : 'Mine ore', settings,
    } }],
})
const response = (data) => ({ ok: true, json: async () => data })

beforeEach(() => {
    vi.stubGlobal('route', (name, parameters) => `/${name}?${new URLSearchParams(parameters).toString()}`)
    vi.stubGlobal('fetch', vi.fn(async () => response(crafting)))
})
afterEach(() => vi.unstubAllGlobals())

describe('interactive guide cards', () => {
    it('scales complete crafting batches without modifying reader defaults', async () => {
        const content = documentFor()
        const wrapper = mount(GuideContent, { props: { content } })
        try {
            await flushPromises()
            expect(wrapper.text()).toContain('1 batches')
            expect(wrapper.get('.guide-activity-card').classes()).not.toContain('is-selected')
            await wrapper.get('input[type="number"]').setValue(5)
            expect(wrapper.text()).toContain('3 batches')
            expect(wrapper.text()).toContain('Need 9')
            await wrapper.get('input[aria-label="Owned Raw Ore"]').setValue(4)
            expect(wrapper.text()).toContain('Need 5')
            expect(content.content[0].attrs.settings.quantity).toBe(1)
        } finally { wrapper.unmount() }
    })

    it('saves author defaults through editor updates', async () => {
        const wrapper = mount(RichTextEditor, {
            props: { modelValue: documentFor(), extensions: guideItemExtensions },
            global: { stubs: { RichTextToolbar: true, RichTextHighlightBubble: true, MediaLibraryModal: true } },
        })
        try {
            await flushPromises()
            await wrapper.get('.guide-activity-card input[type="number"]').setValue(6)
            await flushPromises()
            expect(fetch).toHaveBeenCalledTimes(1)
            const updates = wrapper.emitted('update:modelValue')
            expect(updates.at(-1)[0].content[0].attrs.settings.quantity).toBe(6)
        } finally { wrapper.unmount() }
    })

    it('shows a failed recipe lookup and recovers on retry', async () => {
        fetch.mockResolvedValueOnce({ ok: false, status: 404 })
        const wrapper = mount(GuideContent, { props: { content: documentFor() } })
        try {
            await flushPromises()
            expect(wrapper.text()).toContain('not available in the current game snapshot')
            await wrapper.get('button').trigger('click')
            await flushPromises()
            expect(wrapper.text()).toContain('Raw Ore')
        } finally { wrapper.unmount() }
    })

    it('only requests market prices on demand and clears quotes when the region changes', async () => {
        fetch.mockResolvedValue(response({
            orderBook: { stats: { lowestSell: 10, highestBuy: 8, sellOrderCount: 2, buyOrderCount: 3 } },
            refresh: { delayed: true, updatedAt: '2026-10-02T12:00:00Z' },
        }))
        const wrapper = mount(GuideMarketPrices, { props: { items: [item], region: '' } })
        try {
            await flushPromises()
            expect(fetch).not.toHaveBeenCalled()
            await wrapper.get('button').trigger('click')
            await flushPromises()
            expect(wrapper.text()).toContain('Refresh delayed')
            expect(wrapper.get('time').attributes('datetime')).toBe('2026-10-02T12:00:00Z')
            await wrapper.setProps({ region: 'New region' })
            expect(wrapper.find('dl').exists()).toBe(false)
        } finally { wrapper.unmount() }
    })
})

describe('shared gathering estimates', () => {
    it('caps single-resource yield and keeps crit output separate from base XP', () => {
        const settings = { ...gatheringDefaults, power: 10, minutes: 1, mode: 'single', gatheringSpeed: 0 }
        const normal = estimateGathering(entry, settings)
        expect(normal.actionsUsed).toBe(2)
        expect(normal.outputProgress).toBe(15)
        expect(normal.outputs[0].expected).toBe(7.5)
        expect(normal.experience).toBe(30)
        const critical = estimateGathering(entry, { ...settings, critChance: 100, critMultiplier: 2 })
        expect(critical.actionsUsed).toBe(1)
        expect(critical.outputProgress).toBe(15)
        expect(critical.experience).toBe(20)
    })

    it('scales sustained yield and consumption by action time and probabilities', () => {
        const result = estimateGathering({ ...entry, consumedItems: [{ id: 20, quantity: 2, consumptionChance: 0.25 }] }, {
            ...gatheringDefaults, power: 10, minutes: 1, gatheringSpeed: 100, mode: 'sustained',
        })
        expect(result.actionsUsed).toBe(12)
        expect(result.outputs[0].expected).toBe(60)
        expect(result.inputs[0].expected).toBe(6)
        expect(result.experience).toBe(240)
    })
})
