<template>
    <div
        v-if="renderedHtml"
        class="rich-document-value prose prose-invert max-w-none"
        v-html="renderedHtml"
    />
    <span v-else class="text-muted-3 text-sm font-ui">—</span>
</template>

<script setup>
import { computed } from 'vue'
import { generateHTML } from '@tiptap/html'
import { hasRichDocumentContent, isRichDocument } from '@/Components/scaffold/formatters'
import { richTextRenderExtensions } from '@/lib/tiptap/extensions'

const props = defineProps({
    content: { type: Object, default: null },
})

const renderedHtml = computed(() => {
    if (!isRichDocument(props.content) || !hasRichDocumentContent(props.content)) {
        return ''
    }

    try {
        return generateHTML(props.content, richTextRenderExtensions)
    } catch {
        return ''
    }
})
</script>
