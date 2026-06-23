<template>
    <div class="editor-toolbar__row">
        <RichTextTypeMenu :controls="controls" />

        <div class="editor-tool-group">
            <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--bottom" data-tooltip="Bold" aria-label="Bold" :class="{ 'is-active': controls.isActive('bold') }" @mousedown.prevent @click="controls.toggleMark('bold')">B</button>
            <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--bottom" data-tooltip="Italic" aria-label="Italic" :class="{ 'is-active': controls.isActive('italic') }" @mousedown.prevent @click="controls.toggleMark('italic')">I</button>
            <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--bottom" data-tooltip="Underline" aria-label="Underline" :class="{ 'is-active': controls.isActive('underline') }" @mousedown.prevent @click="controls.toggleMark('underline')">U</button>
            <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--bottom" data-tooltip="Strikethrough" aria-label="Strikethrough" :class="{ 'is-active': controls.isActive('strike') }" @mousedown.prevent @click="controls.toggleMark('strike')">S</button>
        </div>

        <div class="editor-tool-group">
            <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--bottom" data-tooltip="Bulleted list" aria-label="Bulleted list" :class="{ 'is-active': controls.isActive('bulletList') }" @mousedown.prevent @click="controls.toggleList('bulletList')">• List</button>
            <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--bottom" data-tooltip="Numbered list" aria-label="Numbered list" :class="{ 'is-active': controls.isActive('orderedList') }" @mousedown.prevent @click="controls.toggleList('orderedList')">1. List</button>
            <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--bottom" data-tooltip="Task list" aria-label="Task list" :class="{ 'is-active': controls.isActive('taskList') }" @mousedown.prevent @click="controls.toggleTaskList">[] List</button>
            <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--bottom" data-tooltip="Add or edit link" aria-label="Add or edit link" :class="{ 'is-active': controls.isActive('link') }" @mousedown.prevent @click="controls.setLink">Link</button>
        </div>

        <div class="editor-tool-group">
            <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--bottom" data-tooltip="Undo" aria-label="Undo" :disabled="!controls.canUndo.value" @mousedown.prevent @click="controls.runChain((chain) => chain.undo())">Undo</button>
            <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--bottom" data-tooltip="Redo" aria-label="Redo" :disabled="!controls.canRedo.value" @mousedown.prevent @click="controls.runChain((chain) => chain.redo())">Redo</button>
        </div>

        <div class="editor-tool-group">
            <button type="button" class="editor-tool editor-tool--accent editor-tooltip-target editor-tooltip-target--bottom" data-tooltip="Open media library" aria-label="Open media library" @mousedown.prevent @click="controls.chooseMedia">Media</button>
        </div>

        <div class="editor-toolbar__spacer" />

        <button
            type="button"
            class="editor-tool editor-tooltip-target editor-tooltip-target--bottom"
            :data-tooltip="controls.showAdvanced.value ? 'Hide advanced tools' : 'Show advanced tools'"
            :aria-label="controls.showAdvanced.value ? 'Hide advanced tools' : 'Show advanced tools'"
            @mousedown.prevent
            @click="controls.toggleAdvanced"
        >
            {{ controls.showAdvanced.value ? 'Less' : 'More' }}
        </button>
    </div>
</template>

<script setup>
import RichTextTypeMenu from '@/Components/scaffold/RichTextTypeMenu.vue'

defineProps({
    controls: { type: Object, required: true },
})
</script>
