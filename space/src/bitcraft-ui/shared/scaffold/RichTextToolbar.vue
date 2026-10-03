<template>
  <div class="editor-toolbar">
    <RichTextToolbarPrimary :controls="controls" />
    <RichTextToolbarAdvanced :controls="controls" />

    <EditorColorPanel
      v-if="controls.activeColorMode.value"
      :mode="controls.activeColorMode.value"
      :model-value="
        controls.currentColorForMode(controls.activeColorMode.value)
      "
      :presets="controls.colorPresets"
      storage-key="dataverse.editor.saved-colors"
      @apply="controls.applyColorSelection"
      @close="controls.activeColorMode.value = ''"
    />
  </div>
</template>

<script setup>
import EditorColorPanel from "/src/bitcraft-ui/shared/scaffold/EditorColorPanel.vue";
import RichTextToolbarAdvanced from "/src/bitcraft-ui/shared/scaffold/RichTextToolbarAdvanced.vue";
import RichTextToolbarPrimary from "/src/bitcraft-ui/shared/scaffold/RichTextToolbarPrimary.vue";

defineProps({
  controls: { type: Object, required: true },
});
</script>
