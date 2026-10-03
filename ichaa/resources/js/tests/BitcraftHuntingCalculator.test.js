import { mount } from '@vue/test-utils'
import HuntingCalculator from '@/Pages/Bitcraft/HuntingCalculator.vue'
import { calculateHuntingXp } from '@/Pages/Bitcraft/huntingXp'

const mocks = vi.hoisted(() => ({ reload: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({ router: { reload: mocks.reload } }))
vi.mock('@/Layouts/AuthenticatedLayout.vue', () => ({ default: { template: '<div><slot name="header" /><slot /></div>' } }))

const levels = [
    { level: 37, xp: 227130 }, { level: 38, xp: 253930 },
    { level: 39, xp: 283840 }, { level: 40, xp: 317220 },
]
const settings = { currentLevel: 37, currentXp: 5080, targetLevel: 40, killXp: 386.1, processingXp: 198, bonus: 0, xpMode: 'base' }
const calculate = changes => calculateHuntingXp(levels, { ...settings, ...changes })

describe('Hunting XP planner', () => {
    it('subtracts current progress once and compares hired hunters with doing both stages', () => {
        const result = calculate()
        expect(result.remainingXp).toBe(85010)
        expect(result.breakdown.map(row => row.xp)).toEqual([21720, 29910, 33380])
        expect(result.scenarios.map(row => row.count)).toEqual([146, 430, 221])
    })

    it('applies XP bonuses only to base estimates', () => {
        expect(calculate({ bonus: 20 }).scenarios[1].count).toBe(358)
        const observed = calculate({ xpMode: 'observed', processingXp: 237.6, bonus: 20 })
        expect(observed.scenarios[1].xp).toBe(237.6)
        expect(observed.scenarios[1].count).toBe(358)
    })

    it('rounds up whole animals without requiring an extra animal at an exact boundary', () => {
        expect(calculate({ targetLevel: 38, currentXp: 0, processingXp: 268 }).scenarios[1].count).toBe(100)
        expect(calculate({ targetLevel: 38, currentXp: 0, processingXp: 267 }).scenarios[1].count).toBe(101)
    })

    it('shows unavailable counts for zero XP and zero animals for an achieved target', () => {
        expect(calculate({ processingXp: 0 }).scenarios[1].count).toBeNull()
        expect(calculate({ targetLevel: 37, processingXp: 0 }).scenarios.map(row => row.count)).toEqual([0, 0, 0])
    })

    it.each([
        { currentXp: 26800 }, { currentLevel: 36 }, { currentLevel: 37.5 },
        { currentXp: -1 }, { currentXp: '' }, { killXp: Infinity },
        { targetLevel: 36 }, { bonus: -5 }, { bonus: '' }, { bonus: 1e308 },
    ])('rejects invalid input %j', input => {
        expect(calculate(input).error).toBeTruthy()
    })

    it('rejects missing or non-increasing thresholds', () => {
        expect(calculateHuntingXp(levels.filter(row => row.level !== 38), settings).error).toBeTruthy()
        expect(calculateHuntingXp([{ level: 37, xp: 100 }, { level: 38, xp: 90 }], { ...settings, targetLevel: 38 }).error).toBeTruthy()
    })

    it('updates results through the real input controls', async () => {
        const wrapper = mount(HuntingCalculator, { props: { levels } })
        expect(wrapper.get('[data-test="remaining-xp"]').text()).toBe('85,010')
        expect(wrapper.get('[data-test="animal-count"]').text()).toBe('146')
        await wrapper.findAll('select')[0].setValue('processing')
        expect(wrapper.get('[data-test="animal-count"]').text()).toBe('430')
        await wrapper.findAll('input')[3].setValue('20')
        expect(wrapper.get('[data-test="animal-count"]').text()).toBe('358')
        await wrapper.findAll('select')[1].setValue('observed')
        expect(wrapper.findAll('input')[3].attributes('disabled')).toBeDefined()
        expect(wrapper.get('[data-test="animal-count"]').text()).toBe('430')
        await wrapper.findAll('input')[5].setValue('250')
        expect(wrapper.get('[data-test="animal-count"]').text()).toBe('341')
        await wrapper.findAll('input')[1].setValue('')
        expect(wrapper.get('[role="alert"]').text()).toContain('valid')
        expect(wrapper.find('[data-test="animal-count"]').exists()).toBe(false)
        wrapper.unmount()
    })

    it('offers a reload when level data is unavailable', async () => {
        const wrapper = mount(HuntingCalculator, { props: { levels: [], error: 'Thresholds unavailable' } })
        expect(wrapper.get('[role="alert"]').text()).toBe('Thresholds unavailable')
        await wrapper.get('button[class*="mt-3"]').trigger('click')
        expect(mocks.reload).toHaveBeenCalledWith({ only: ['levels', 'error'] })
        wrapper.unmount()
    })
})
