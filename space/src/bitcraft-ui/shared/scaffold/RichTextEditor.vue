<template>
  <div class="editor-shell">
    <RichTextToolbar :controls="controls" />
    <slot name="tools" :editor="editor" />
    <RichTextHighlightBubble :editor="editor" :controls="controls" />

    <div class="editor-canvas">
      <EditorContent v-if="editor" :editor="editor" />
      <p v-else class="editor-canvas__loading">Loading editor...</p>
    </div>

    <input
      ref="imageUploadInput"
      type="file"
      accept="image/*"
      class="sr-only"
      @change="controls.handleImageUpload"
    />
  </div>
</template>

<script setup>
import { onBeforeUnmount, ref, watch } from "vue";
import { EditorContent, useEditor } from "@tiptap/vue-3";
import RichTextHighlightBubble from "/src/bitcraft-ui/shared/scaffold/RichTextHighlightBubble.vue";
import RichTextToolbar from "/src/bitcraft-ui/shared/scaffold/RichTextToolbar.vue";
import { normalizeRichDocument } from "/src/bitcraft-ui/lib/tiptap/documents";
import { buildRichTextEditorExtensions } from "/src/bitcraft-ui/lib/tiptap/extensions";
import { useRichTextEditorControls } from "/src/bitcraft-ui/lib/tiptap/useRichTextEditorControls";

defineOptions({ name: "RichTextEditor" });

const props = defineProps({
  modelValue: { type: [Object, String], default: null },
  placeholder: { type: String, default: "Write here..." },
  inputId: { type: String, default: "" },
  ariaLabel: { type: String, default: "" },
  describedBy: { type: String, default: "" },
  extensions: { type: Array, default: () => [] },
});

const emit = defineEmits(["update:modelValue"]);

const imageUploadInput = ref(null);

const editor = useEditor({
  content: normalizeRichDocument(props.modelValue),
  extensions: [
    ...buildRichTextEditorExtensions(props.placeholder),
    ...props.extensions,
  ],
  editorProps: {
    attributes: {
      class: "tiptap-editor__content",
      id: props.inputId || undefined,
      "aria-label": props.ariaLabel || undefined,
      "aria-describedby": props.describedBy || undefined,
    },
  },
  onUpdate: ({ editor: instance }) => {
    emit("update:modelValue", instance.getJSON());
  },
});

const controls = useRichTextEditorControls(editor, imageUploadInput);

watch(
  () => props.modelValue,
  (value) => {
    const instance = editor.value;

    if (!instance) {
      return;
    }

    const incoming = normalizeRichDocument(value);
    const current = instance.getJSON();

    if (JSON.stringify(current) === JSON.stringify(incoming)) {
      return;
    }

    instance.commands.setContent(incoming, { emitUpdate: false });
  },
);

onBeforeUnmount(() => {
  editor.value?.destroy();
});
</script>
