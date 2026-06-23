<template>
    <div class="editor-tool-group">
        <button
            type="button"
            class="editor-tool editor-tool--select editor-tooltip-target"
            :class="`editor-tooltip-target--${tooltipPlacement}`"
            :data-tooltip="tooltip || null"
            :aria-label="tooltip || label"
            @mousedown.prevent
            @click="emit('toggle')"
        >
            <span>{{ label }}</span>
            <span>{{ open ? '˄' : '˅' }}</span>
        </button>

        <div v-if="open" class="editor-popover editor-type-menu">
            <button
                v-for="option in options"
                :key="option.label"
                type="button"
                class="editor-menu-item"
                :class="{ 'is-active': option.active }"
                :disabled="option.disabled"
                @mousedown.prevent
                @click="option.action"
            >
                <span v-if="option.short">{{ option.short }}</span>
                <span :class="{ 'ml-7': !option.short }">{{ option.label }}</span>
            </button>
        </div>
    </div>
</template>

<script setup>
const emit = defineEmits(['toggle'])

defineProps({
    label: { type: String, required: true },
    open: { type: Boolean, default: false },
    options: { type: Array, default: () => [] },
    tooltip: { type: String, default: '' },
    tooltipPlacement: { type: String, default: 'bottom' },
})
</script>
