<template>
    <div class="editor-shell">
        <RichTextToolbar :controls="controls" />
        <RichTextHighlightBubble :editor="editor" :controls="controls" />

        <div class="editor-canvas">
            <EditorContent v-if="editor" :editor="editor" />
            <p v-else class="editor-canvas__loading">Loading editor...</p>
        </div>

        <input ref="imageUploadInput" type="file" accept="image/*" class="sr-only" @change="controls.handleImageUpload">

        <MediaLibraryModal
            :show="showMediaLibrary"
            media-type="image"
            @close="showMediaLibrary = false"
            @select="insertSelectedMedia"
        />
    </div>
</template>

<script setup>
import { onBeforeUnmount, ref, watch } from 'vue'
import { EditorContent, useEditor } from '@tiptap/vue-3'
import MediaLibraryModal from '@/Components/scaffold/MediaLibraryModal.vue'
import RichTextHighlightBubble from '@/Components/scaffold/RichTextHighlightBubble.vue'
import RichTextToolbar from '@/Components/scaffold/RichTextToolbar.vue'
import { normalizeRichDocument } from '@/lib/tiptap/documents'
import { buildRichTextEditorExtensions } from '@/lib/tiptap/extensions'
import { useRichTextEditorControls } from '@/lib/tiptap/useRichTextEditorControls'

defineOptions({ name: 'RichTextEditor' })

const props = defineProps({
    modelValue: { type: [Object, String], default: null },
    placeholder: { type: String, default: 'Write here...' },
    inputId: { type: String, default: '' },
    ariaLabel: { type: String, default: '' },
    describedBy: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue'])

const imageUploadInput = ref(null)
const showMediaLibrary = ref(false)

const editor = useEditor({
    content: normalizeRichDocument(props.modelValue),
    extensions: buildRichTextEditorExtensions(props.placeholder),
    editorProps: {
        attributes: {
            class: 'tiptap-editor__content',
            id: props.inputId || undefined,
            'aria-label': props.ariaLabel || undefined,
            'aria-describedby': props.describedBy || undefined,
        },
    },
    onUpdate: ({ editor: instance }) => {
        emit('update:modelValue', instance.getJSON())
    },
})

const controls = useRichTextEditorControls(editor, imageUploadInput, {
    onChooseMedia: () => {
        showMediaLibrary.value = true
    },
})

const insertSelectedMedia = (media) => {
    const instance = editor.value

    if (!instance || !media?.insert_url) {
        return
    }

    instance
        .chain()
        .focus()
        .setImage({
            src: media.insert_url,
            alt: media.title || '',
            title: media.title || '',
            align: 'left',
            width: '50%',
        })
        .run()

    showMediaLibrary.value = false
}

watch(() => props.modelValue, (value) => {
    const instance = editor.value

    if (!instance) {
        return
    }

    const incoming = normalizeRichDocument(value)
    const current = instance.getJSON()

    if (JSON.stringify(current) === JSON.stringify(incoming)) {
        return
    }

    instance.commands.setContent(incoming, false)
})

onBeforeUnmount(() => {
    editor.value?.destroy()
})
</script>
