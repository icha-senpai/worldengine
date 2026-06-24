import { buildRichTextEditorExtensions } from '@/lib/tiptap/extensions'

describe('tiptap extensions', () => {
    it('does not register duplicate extension names', () => {
        const names = buildRichTextEditorExtensions('Write here...').map((extension) => extension.name)
        const duplicates = names.filter((name, index) => names.indexOf(name) !== index)

        expect(duplicates).toEqual([])
    })
})
