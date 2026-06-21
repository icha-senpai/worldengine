import { asArray, badge, buildMeta, countRecords, sectionEntry } from '@/Pages/scaffold/pageBuilders'

describe('scaffold page builders', () => {
    it('normalizes plain arrays and paginated payloads', () => {
        expect(asArray([{ id: 1 }])).toEqual([{ id: 1 }])
        expect(asArray({ data: [{ id: 2 }] })).toEqual([{ id: 2 }])
        expect(asArray(null)).toEqual([])
    })

    it('prefers paginator totals when counting records', () => {
        expect(countRecords({ total: 40, data: [{ id: 1 }] })).toBe(40)
        expect(countRecords([{ id: 1 }, { id: 2 }])).toBe(2)
    })

    it('filters empty meta rows and summarizes the remaining values', () => {
        expect(buildMeta([
            { label: 'Universe', value: 'Harry Potter' },
            { label: 'Hidden', value: '' },
            { label: 'Published', value: true },
        ])).toEqual([
            { label: 'Universe', value: 'Harry Potter' },
            { label: 'Published', value: 'Yes' },
        ])
    })

    it('builds small display helpers without mutating the payload', () => {
        expect(badge('Status', 'active')).toEqual({ label: 'Status', value: 'active' })
        expect(sectionEntry('Summary', 'Short note', { tone: 'muted' })).toEqual({
            label: 'Summary',
            value: 'Short note',
            tone: 'muted',
        })
    })
})
