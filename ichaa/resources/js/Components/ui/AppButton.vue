<script setup>
import { computed, useAttrs } from 'vue'
import { Link } from '@inertiajs/vue3'

defineOptions({
    inheritAttrs: false,
})

const props = defineProps({
    href: { type: String, default: '' },
    method: { type: String, default: '' },
    type: { type: String, default: 'button' },
    variant: {
        type: String,
        default: 'primary',
        validator: (value) => ['primary', 'ghost', 'danger', 'success', 'sync', 'select', 'select-solid', 'select-danger'].includes(value),
    },
    size: {
        type: String,
        default: 'md',
        validator: (value) => ['md', 'sm'].includes(value),
    },
    selected: { type: Boolean, default: false },
    selectedTone: {
        type: String,
        default: 'accent',
        validator: (value) => ['accent', 'danger'].includes(value),
    },
    disabled: { type: Boolean, default: false },
    preserveScroll: { type: Boolean, default: false },
    preserveState: { type: Boolean, default: false },
    block: { type: Boolean, default: false },
})

const attrs = useAttrs()

const isLink = computed(() => Boolean(props.href))

const componentTag = computed(() => (isLink.value ? Link : 'button'))

const componentAttrs = computed(() => ({
    ...attrs,
    ...(isLink.value
        ? {
            href: props.href,
            method: props.method || undefined,
            preserveScroll: props.preserveScroll || undefined,
            preserveState: props.preserveState || undefined,
        }
        : {
            type: props.type,
            disabled: props.disabled,
            'aria-pressed': props.selected || undefined,
        }),
}))

const buttonClasses = computed(() => [
    'app-btn',
    `app-btn--${props.variant}`,
    `app-btn--${props.size}`,
    {
        'w-full': props.block,
        [`app-btn--selected-${props.selectedTone}`]: props.selected,
    },
])
</script>

<template>
    <component
        :is="componentTag"
        v-bind="componentAttrs"
        :class="buttonClasses"
    >
        <slot />
    </component>
</template>
