import { Editor } from '@tiptap/core'
import { generateHTML } from '@tiptap/html'
import { mount } from '@vue/test-utils'
import RichDocumentValue from '@/Components/scaffold/RichDocumentValue.vue'
import { buildRichTextEditorExtensions, richTextRenderExtensions } from '@/lib/tiptap/extensions'
import { guideItemExtensions } from '@/Pages/Bitcraft/Guides/bitcraftItem'

const item = {
    id: 123,
    kind: 'item',
    name: 'Crushed Copper Ore',
    category: 'Ore',
    tier: 2,
    rarity: 'Common',
    iconAssetName: 'Items/CrushedCopperOre',
}
const cardDocument = (attrs = item) => ({
    type: 'doc', content: [{ type: 'bitcraftItem', attrs }],
})

describe('Bitcraft guide item cards', () => {
    it('renders an item-only guide with its local icon and metadata', () => {
        const wrapper = mount(RichDocumentValue, {
            props: { content: cardDocument(), extensions: guideItemExtensions },
        })
        expect(wrapper.get('.guide-item-card').text()).toContain(item.name)
        expect(wrapper.text()).toContain('Tier 2')
        expect(wrapper.text()).toContain('Common')
        expect(wrapper.get('img').attributes('src')).toBe('/bitcraft-assets/sprites/Items/CrushedCopperOre.webp')
    })

    it('escapes catalog text and ignores non-local icon URLs', () => {
        const html = generateHTML(cardDocument({ ...item, name: '<script>alert(1)</script>', iconAssetName: 'https://example.com/item.png' }), [...richTextRenderExtensions, ...guideItemExtensions])
        const root = document.createElement('div')
        root.innerHTML = html
        expect(root.querySelector('script')).toBeNull()
        expect(root.querySelector('img')).toBeNull()
        expect(root.querySelector('strong').textContent).toBe('<script>alert(1)</script>')
    })

    it('preserves cards through editor insertion, undo, and HTML copy/paste', () => {
        const editor = new Editor({
            extensions: [...buildRichTextEditorExtensions(), ...guideItemExtensions],
            content: '<p>Before</p><p>After</p>',
        })
        try {
            editor.commands.insertContentAt(8, [{ type: 'bitcraftItem', attrs: item }, { type: 'paragraph' }])
            expect(editor.getJSON().content.some((node) => node.type === 'bitcraftItem')).toBe(true)
            const savedHtml = editor.getHTML()
            editor.commands.undo()
            expect(editor.getJSON().content.some((node) => node.type === 'bitcraftItem')).toBe(false)
            editor.commands.setContent(savedHtml)
            const restored = editor.getJSON().content.find((node) => node.type === 'bitcraftItem')
            expect(restored.attrs.name).toBe(item.name)
            expect(Number(restored.attrs.id)).toBe(item.id)
            expect(restored.attrs.kind).toBe('item')
            expect(editor.getText()).toContain('Before')
            expect(editor.getText()).toContain('After')
        } finally {
            editor.destroy()
        }
    })
})
