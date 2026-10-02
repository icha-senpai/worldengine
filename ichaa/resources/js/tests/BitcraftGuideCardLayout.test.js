import { Editor } from '@tiptap/core'
import { closeHistory } from '@tiptap/pm/history'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { buildRichTextEditorExtensions } from '@/lib/tiptap/extensions'
import { guideItemExtensions } from '@/Pages/Bitcraft/Guides/bitcraftItem'
import GuideCardLayoutTools from '@/Pages/Bitcraft/Guides/GuideCardLayoutTools.vue'
import { guideCardLayoutHtml } from '@/Pages/Bitcraft/Guides/guideCardLayout'

const cards = [
    { type: 'bitcraftItem', attrs: { id: 123, kind: 'item', name: 'Ore' } },
    { type: 'bitcraftActivity', attrs: { activity: 'crafting', recipeId: 7, itemId: 123, kind: 'item', name: 'Crush ore', settings: { quantity: 5 } } },
    { type: 'image', attrs: { src: '/bitcraft-assets/sprites/Items/CrushedCopperOre.webp', alt: 'Ore' } },
]

describe('Guide card layout', () => {
    it.each(cards)('saves layout, supports undo, and restores HTML for $type', async (card) => {
        const editor = new Editor({
            extensions: [...buildRichTextEditorExtensions(), ...guideItemExtensions],
            content: { type: 'doc', content: [card, { type: 'paragraph' }] },
        })
        editor.commands.setTextSelection(editor.state.doc.content.size - 1)
        const tools = mount(GuideCardLayoutTools, { props: { editor } })
        try {
            expect(tools.find('[role="group"]').exists()).toBe(false)
            editor.commands.setNodeSelection(0)
            await nextTick()
            expect(tools.text()).toContain(card.type === 'image' ? '100%' : 'Auto')
            await tools.get('select').setValue('right')
            await tools.get('input[type="range"]').setValue('50')
            const expectedWidth = card.type === 'image' ? '50%' : 50
            expect(editor.getJSON().content[0].attrs).toMatchObject({ align: 'right', width: expectedWidth })
            const html = editor.getHTML()
            expect(html).toContain(card.type === 'image' ? 'data-align="right"' : 'data-card-align="right"')
            expect(html).toContain(card.type === 'image' ? 'data-width="50%"' : 'data-card-width="50"')
            expect(html).toContain('width: 50%')
            editor.view.dispatch(closeHistory(editor.state.tr))
            await tools.get('button').trigger('click')
            expect(editor.getJSON().content[0].attrs.width).toBe(card.type === 'image' ? '100%' : null)
            editor.commands.undo()
            expect(editor.getJSON().content[0].attrs.width).toBe(expectedWidth)
            editor.commands.setContent(html)
            expect(editor.getJSON().content[0].attrs).toMatchObject({ align: 'right', width: expectedWidth })
            if (card.type === 'bitcraftActivity') expect(editor.getJSON().content[0].attrs.settings).toEqual(card.attrs.settings)
            editor.commands.setTextSelection(editor.state.doc.content.size - 1)
            await nextTick()
            expect(tools.find('[role="group"]').exists()).toBe(false)
        } finally {
            tools.unmount()
            editor.destroy()
        }
    })

    it('ignores unsafe sizes and alignment when generating card styles', () => {
        expect(guideCardLayoutHtml({ align: 'right; background:red', width: '100%; position:fixed' }))
            .toEqual({ 'data-card-align': 'left', 'data-card-wrap': 'false', style: 'margin-left: 0; margin-right: auto;' })
        expect(guideCardLayoutHtml({ align: 'center', width: 101 })).not.toHaveProperty('data-card-width')
        expect(guideCardLayoutHtml({ align: 'center', width: 25 })).toMatchObject({
            'data-card-align': 'center', 'data-card-width': '25', style: 'width: 25%; margin-left: auto; margin-right: auto;',
        })
    })

    it.each(cards)('saves optional text wrapping and disables it for centered $type', async (card) => {
        const editor = new Editor({
            extensions: [...buildRichTextEditorExtensions(), ...guideItemExtensions],
            content: { type: 'doc', content: [card, { type: 'paragraph' }] },
        })
        const tools = mount(GuideCardLayoutTools, { props: { editor } })
        try {
            await tools.get('input[type="checkbox"]').setValue(true)
            expect(editor.getJSON().content[0].attrs).toMatchObject({ wrap: true, width: card.type === 'image' ? '50%' : 50 })
            expect(tools.get('input[type="range"]').attributes('max')).toBe('70')
            const html = editor.getHTML()
            editor.commands.setContent(html)
            expect(editor.getJSON().content[0].attrs.wrap).toBe(true)
            editor.commands.setNodeSelection(0)
            await nextTick()
            await tools.get('select').setValue('center')
            expect(editor.getJSON().content[0].attrs.wrap).toBe(false)
            expect(tools.get('input[type="checkbox"]').attributes()).toHaveProperty('disabled')
            expect(editor.getHTML()).toContain(card.type === 'image' ? 'data-wrap="false"' : 'data-card-wrap="false"')
        } finally {
            tools.unmount()
            editor.destroy()
        }
    })
})
