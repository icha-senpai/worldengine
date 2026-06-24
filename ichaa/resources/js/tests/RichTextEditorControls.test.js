import { ref } from 'vue'
import { mount } from '@vue/test-utils'
import EditorColorPanel from '@/Components/scaffold/EditorColorPanel.vue'
import { useRichTextEditorControls } from '@/lib/tiptap/useRichTextEditorControls'

function createEditorDouble({ attributes = {}, active = {} } = {}) {
    const calls = []

    const editorValue = {
        state: {
            selection: { from: 2, to: 5 },
        },
        can() {
            return {
                chain() {
                    return {
                        focus() {
                            return {
                                undo() {
                                    return { run: () => true }
                                },
                                redo() {
                                    return { run: () => true }
                                },
                            }
                        },
                    }
                },
            }
        },
        storage: {
            characterCount: {
                characters() {
                    return 128
                },
                words() {
                    return 24
                },
            },
        },
        chain() {
            const chain = {
                focus() {
                    calls.push(['focus'])
                    return chain
                },
                setTextSelection(selection) {
                    calls.push(['setTextSelection', selection])
                    return chain
                },
                setColor(color) {
                    calls.push(['setColor', color])
                    return chain
                },
                setHighlight(payload) {
                    calls.push(['setHighlight', payload])
                    return chain
                },
                setFieldColor(color) {
                    calls.push(['setFieldColor', color])
                    return chain
                },
                setTextAlign(value) {
                    calls.push(['setTextAlign', value])
                    return chain
                },
                unsetFontFamily() {
                    calls.push(['unsetFontFamily'])
                    return chain
                },
                setFontFamily(value) {
                    calls.push(['setFontFamily', value])
                    return chain
                },
                unsetFontSize() {
                    calls.push(['unsetFontSize'])
                    return chain
                },
                setFontSize(value) {
                    calls.push(['setFontSize', value])
                    return chain
                },
                updateAttributes(name, payload) {
                    calls.push(['updateAttributes', name, payload])
                    return chain
                },
                setParagraph() {
                    calls.push(['setParagraph'])
                    return chain
                },
                toggleHeading(payload) {
                    calls.push(['toggleHeading', payload])
                    return chain
                },
                toggleMark(mark) {
                    calls.push(['toggleMark', mark])
                    return chain
                },
                toggleBulletList() {
                    calls.push(['toggleBulletList'])
                    return chain
                },
                toggleOrderedList() {
                    calls.push(['toggleOrderedList'])
                    return chain
                },
                toggleTaskList() {
                    calls.push(['toggleTaskList'])
                    return chain
                },
                toggleBlockquote() {
                    calls.push(['toggleBlockquote'])
                    return chain
                },
                setHorizontalRule() {
                    calls.push(['setHorizontalRule'])
                    return chain
                },
                toggleCodeBlock() {
                    calls.push(['toggleCodeBlock'])
                    return chain
                },
                toggleSubscript() {
                    calls.push(['toggleSubscript'])
                    return chain
                },
                toggleSuperscript() {
                    calls.push(['toggleSuperscript'])
                    return chain
                },
                unsetColor() {
                    calls.push(['unsetColor'])
                    return chain
                },
                unsetHighlight() {
                    calls.push(['unsetHighlight'])
                    return chain
                },
                unsetFieldColor() {
                    calls.push(['unsetFieldColor'])
                    return chain
                },
                clearNodes() {
                    calls.push(['clearNodes'])
                    return chain
                },
                unsetAllMarks() {
                    calls.push(['unsetAllMarks'])
                    return chain
                },
                insertTable(payload) {
                    calls.push(['insertTable', payload])
                    return chain
                },
                addRowAfter() {
                    calls.push(['addRowAfter'])
                    return chain
                },
                addColumnAfter() {
                    calls.push(['addColumnAfter'])
                    return chain
                },
                deleteTable() {
                    calls.push(['deleteTable'])
                    return chain
                },
                extendMarkRange(name) {
                    calls.push(['extendMarkRange', name])
                    return chain
                },
                setLink(payload) {
                    calls.push(['setLink', payload])
                    return chain
                },
                unsetLink() {
                    calls.push(['unsetLink'])
                    return chain
                },
                setImage(payload) {
                    calls.push(['setImage', payload])
                    return chain
                },
                run() {
                    calls.push(['run'])
                    return true
                },
            }

            return chain
        },
        getAttributes(name) {
            return attributes[name] ?? {}
        },
        isActive(nameOrAttributes, payload = undefined) {
            if (typeof nameOrAttributes === 'string') {
                if (nameOrAttributes === 'heading') {
                    return active.headingLevel === payload?.level
                }

                return Boolean(active[nameOrAttributes])
            }

            if (nameOrAttributes?.textAlign) {
                return active.textAlign === nameOrAttributes.textAlign
            }

            return false
        },
    }

    return {
        editor: ref(editorValue),
        calls,
    }
}

describe('useRichTextEditorControls', () => {
    it('restores the saved selection before applying a color choice', () => {
        const { editor, calls } = createEditorDouble()
        const controls = useRichTextEditorControls(editor, ref(null))

        controls.toggleColorMode('text')
        controls.applyColorSelection('#FF00AA')

        expect(calls).toContainEqual(['focus'])
        expect(calls).toContainEqual(['setTextSelection', { from: 2, to: 5 }])
        expect(calls).toContainEqual(['setColor', '#FF00AA'])
    })

    it('uses selector actions for alignment instead of cycling values', () => {
        const { editor, calls } = createEditorDouble({
            attributes: {
                paragraph: { textAlign: 'left' },
            },
        })
        const controls = useRichTextEditorControls(editor, ref(null))

        controls.toggleAlignmentMenu()
        controls.alignmentOptions.value.find((option) => option.value === 'center').action()

        expect(calls).toContainEqual(['setTextAlign', 'center'])
        expect(controls.showAlignmentMenu.value).toBe(false)
    })

    it('applies quick highlight colors without reopening the toolbar color picker', () => {
        const { editor, calls } = createEditorDouble()
        const controls = useRichTextEditorControls(editor, ref(null))

        controls.openColorMode('highlight')
        controls.applyHighlightColor('#FCD34D')

        expect(controls.activeColorMode.value).toBe('highlight')
        expect(calls).toContainEqual(['setTextSelection', { from: 2, to: 5 }])
        expect(calls).toContainEqual(['setHighlight', { color: '#FCD34D' }])
    })

    it('disables image selectors when no image node is active', () => {
        const { editor } = createEditorDouble({ active: { image: false } })
        const controls = useRichTextEditorControls(editor, ref(null))

        expect(controls.imageAlignmentOptions.value.every((option) => option.disabled)).toBe(true)
        expect(controls.imageWidthOptions.value.every((option) => option.disabled)).toBe(true)
    })

    it('exposes installed task list, subscript, superscript, and count controls', () => {
        const { editor, calls } = createEditorDouble()
        const controls = useRichTextEditorControls(editor, ref(null))

        controls.toggleTaskList()
        controls.toggleSubscript()
        controls.toggleSuperscript()

        expect(calls).toContainEqual(['toggleTaskList'])
        expect(calls).toContainEqual(['toggleSubscript'])
        expect(calls).toContainEqual(['toggleSuperscript'])
        expect(controls.characterCount.value).toBe(128)
        expect(controls.wordCount.value).toBe(24)
    })

    it('uses the shared prompt flow for inserting links', async () => {
        const { editor, calls } = createEditorDouble()
        const promptForValue = vi.fn().mockResolvedValue('https://example.com/story')
        const controls = useRichTextEditorControls(editor, ref(null), { promptForValue })

        await controls.setLink()

        expect(promptForValue).toHaveBeenCalledWith(expect.objectContaining({
            title: 'Insert Link',
            inputLabel: 'Link URL',
        }))
        expect(calls).toContainEqual(['extendMarkRange', 'link'])
        expect(calls).toContainEqual(['setLink', {
            href: 'https://example.com/story',
            target: '_blank',
            rel: 'noopener noreferrer',
        }])
    })

    it('removes links when the prompt returns an empty value', async () => {
        const { editor, calls } = createEditorDouble({
            attributes: {
                link: { href: 'https://example.com/current' },
            },
        })
        const controls = useRichTextEditorControls(editor, ref(null), {
            promptForValue: vi.fn().mockResolvedValue(''),
        })

        await controls.setLink()

        expect(calls).toContainEqual(['unsetLink'])
    })
})

describe('EditorColorPanel', () => {
    it('emits applied colors from the custom hue track', async () => {
        const wrapper = mount(EditorColorPanel, {
            props: {
                mode: 'text',
                modelValue: '#112233',
                presets: ['#112233', '#445566'],
            },
            global: {
                stubs: {
                    teleport: true,
                },
            },
        })

        const hueTrack = wrapper.find('.editor-color-panel__hue-track')

        Object.defineProperty(hueTrack.element, 'getBoundingClientRect', {
            value: () => ({
                left: 0,
                top: 0,
                width: 100,
                height: 16,
                right: 100,
                bottom: 16,
            }),
        })

        await hueTrack.trigger('mousedown', {
            clientX: 50,
            clientY: 8,
        })

        expect(wrapper.emitted('apply')).toBeTruthy()
    })
})
