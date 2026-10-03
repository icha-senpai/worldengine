<script setup>
import "./guideTextWrapping.css";
import { onBeforeUnmount, watch } from "vue";
import { EditorContent, useEditor } from "@tiptap/vue-3";
import { normalizeRichDocument } from "/src/bitcraft-ui/lib/tiptap/documents";
import { richTextRenderExtensions } from "/src/bitcraft-ui/lib/tiptap/extensions";
import { guideItemExtensions } from "./bitcraftItem";

const props = defineProps({ content: { type: Object, default: null } });
const editor = useEditor({
  editable: false,
  content: normalizeRichDocument(props.content),
  extensions: [
    ...richTextRenderExtensions.map((extension) =>
      extension.name === "image"
        ? extension.extend({
            addNodeView() {
              return null;
            },
          })
        : extension,
    ),
    ...guideItemExtensions,
  ],
  editorProps: {
    attributes: { class: "prose prose-invert max-w-none", role: "document" },
  },
});
watch(
  () => props.content,
  (value) => {
    editor.value?.commands.setContent(normalizeRichDocument(value), {
      emitUpdate: false,
    });
  },
);
onBeforeUnmount(() => editor.value?.destroy());
</script>

<template>
  <EditorContent class="rich-document-value guide-content" :editor="editor" />
</template>

<style scoped>
.guide-content :deep(.ProseMirror) {
  outline: none;
}
.guide-content :deep(.ProseMirror-selectednode) {
  outline: none;
}
</style>
