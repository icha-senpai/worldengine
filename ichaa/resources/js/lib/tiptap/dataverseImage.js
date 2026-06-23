import Image from '@tiptap/extension-image'
import { VueNodeViewRenderer } from '@tiptap/vue-3'
import DataverseImageNodeView from '@/Components/scaffold/DataverseImageNodeView.vue'

export const DataverseImage = Image.extend({
    addAttributes() {
        return {
            ...this.parent?.(),
            align: {
                default: 'left',
                parseHTML: (element) => element.getAttribute('data-align') || 'left',
                renderHTML: (attributes) => ({
                    'data-align': attributes.align || 'left',
                }),
            },
            width: {
                default: '100%',
                parseHTML: (element) => element.getAttribute('data-width') || '100%',
                renderHTML: (attributes) => ({
                    'data-width': attributes.width || '100%',
                    style: attributes.width ? `width: ${attributes.width};` : null,
                }),
            },
        }
    },

    addNodeView() {
        return VueNodeViewRenderer(DataverseImageNodeView)
    },
})
