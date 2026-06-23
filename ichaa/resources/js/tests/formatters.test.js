import {
    formatEntityOptionLabel,
    formatLabel,
    hasRichDocumentContent,
    isRichDocument,
    prettyJson,
    summarizeValue,
    toRelationshipOptions,
} from '@/Components/scaffold/formatters'

describe('scaffold formatters', () => {
    it('formats snake_case labels for display', () => {
        expect(formatLabel('source_universes')).toBe('Source Universes')
    })

    it('summarizes arrays, booleans, and named objects consistently', () => {
        expect(summarizeValue([{ name: 'Seraphine' }, true, 'open'])).toBe('Seraphine, Yes, open')
        expect(summarizeValue({ title: 'Chronicle' })).toBe('Chronicle')
        expect(summarizeValue(false)).toBe('No')
    })

    it('pretty prints structured values without touching plain strings', () => {
        expect(prettyJson({ notes: ['alpha', 'beta'] })).toContain('"notes"')
        expect(prettyJson('already formatted')).toBe('already formatted')
    })

    it('detects rich text documents and ignores empty shells', () => {
        expect(isRichDocument({ type: 'doc', content: [] })).toBe(true)
        expect(hasRichDocumentContent({ type: 'doc', content: [] })).toBe(false)
        expect(hasRichDocumentContent({
            type: 'doc',
            content: [
                {
                    type: 'paragraph',
                    content: [{ type: 'text', text: 'Rendered text' }],
                },
            ],
        })).toBe(true)
        expect(isRichDocument([{ type: 'paragraph' }])).toBe(false)
    })

    it('builds relationship labels from both snake_case and camelCase payloads', () => {
        expect(formatEntityOptionLabel({ id: 4, name: 'Seraphine', entity_type: 'character' }))
            .toBe('Seraphine (#4 · Character)')

        expect(toRelationshipOptions([
            {
                id: 7,
                relationship_type: 'blood_oath',
                fromEntity: { name: 'A' },
                to_entity: { name: 'B' },
            },
        ])).toEqual([
            {
                value: 7,
                label: 'A -> B (#7 · Blood Oath)',
            },
        ])
    })
})
