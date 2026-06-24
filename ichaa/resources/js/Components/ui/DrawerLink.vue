<script setup>
import { computed, useAttrs } from 'vue'
import { router } from '@inertiajs/vue3'
import { beginDrawerNavigation } from '@/lib/drawerNavigation'

defineOptions({
    inheritAttrs: false,
})

const props = defineProps({
    href: { type: String, required: true },
    preserveScroll: { type: Boolean, default: false },
    preserveState: { type: Boolean, default: false },
    opensDrawer: { type: Boolean, default: false },
    title: { type: String, default: '' },
})

const attrs = useAttrs()

const shouldStartDrawerNavigation = computed(() => props.opensDrawer)
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

function handleClick(event) {
    if (!isInternalLink.value) {
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

    event.preventDefault()
    const visit = () => {
        router.visit(props.href, {
            preserveScroll: props.preserveScroll,
            preserveState: props.preserveState,
        })
    }

    if (!shouldStartDrawerNavigation.value) {
        visit()
        return
    }

    beginDrawerNavigation({ href: props.href })
    window.requestAnimationFrame(visit)
}
</script>

<template>
    <a
        v-bind="attrs"
        :href="href"
        @click="handleClick"
    >
        <slot />
    </a>
</template>
