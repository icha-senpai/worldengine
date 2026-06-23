<template>
    <div class="editor-tool-group">
        <button
            type="button"
            class="editor-tool editor-tool--select editor-tooltip-target editor-tooltip-target--bottom"
            data-tooltip="Choose text style"
            aria-label="Choose text style"
            @mousedown.prevent
            @click="controls.toggleTypeMenu"
        >
            <span>{{ controls.currentTypeLabel.value }}</span>
            <span>{{ controls.showTypeMenu.value ? '˄' : '˅' }}</span>
        </button>

        <div v-if="controls.showTypeMenu.value" class="editor-popover editor-type-menu">
            <button
                v-for="option in controls.typeOptions.value"
                :key="option.label"
                type="button"
                class="editor-menu-item"
                :class="{ 'is-active': option.active }"
                @mousedown.prevent
                @click="option.action"
            >
                <span>{{ option.short }}</span>
                <span>{{ option.label }}</span>
            </button>
        </div>
    </div>
</template>

<script setup>
defineProps({
    controls: { type: Object, required: true },
})
</script>
