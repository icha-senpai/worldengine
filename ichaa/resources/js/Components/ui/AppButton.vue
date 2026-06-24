<script setup>
import { computed, useAttrs } from 'vue'
import { router } from '@inertiajs/vue3'
import { beginDrawerNavigation } from '@/lib/drawerNavigation'

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
    opensDrawer: { type: Boolean, default: false },
    block: { type: Boolean, default: false },
})

const attrs = useAttrs()

const isLink = computed(() => Boolean(props.href))
const isInternalLink = computed(() => {
    if (!props.href || typeof window === 'undefined') {
        return false
    }

    try {
        return new URL(props.href, window.location.origin).origin === window.location.origin
    } catch {
        return false
    }
})

const componentTag = computed(() => (isLink.value ? 'a' : 'button'))

const componentAttrs = computed(() => ({
    ...attrs,
    ...(isLink.value
        ? {
            href: props.href,
            'aria-disabled': props.disabled || undefined,
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

const shouldStartDrawerNavigation = computed(() => isLink.value && props.opensDrawer)

function handleClick(event) {
    if (!isLink.value || props.disabled) {
        return
    }

    if (
        event.defaultPrevented ||
        event.button !== 0 ||
        event.metaKey ||
        event.ctrlKey ||
        event.shiftKey ||
        event.altKey
    ) {
        return
    }

    if (!isInternalLink.value) {
        return
    }

    event.preventDefault()

    const visit = () => {
        router.visit(props.href, {
            method: props.method || 'get',
            preserveScroll: props.preserveScroll,
            preserveState: props.preserveState,
        })
    }

    if (!shouldStartDrawerNavigation.value) {
        visit()
        return
    }

    beginDrawerNavigation({
        href: props.href,
    })

    window.requestAnimationFrame(visit)
}
</script>

<template>
    <component
        :is="componentTag"
        v-bind="componentAttrs"
        :class="buttonClasses"
        @click="handleClick"
    >
        <slot />
    </component>
</template>
