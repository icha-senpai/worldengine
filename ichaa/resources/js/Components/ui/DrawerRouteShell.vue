<template>
    <AppDrawer
        v-if="open"
        :title="title"
        :trail-items="trailItems"
        :close-href="closeHref"
        @close="closeDrawer"
    >
        <slot v-if="ready" />

        <div v-else class="drawer-loading" aria-hidden="true">
            <div class="drawer-loading__panel">
                <div class="drawer-loading__label" />
                <div class="drawer-loading__line drawer-loading__line--lg" />
                <div class="drawer-loading__label" />
                <div class="drawer-loading__line" />
            </div>

            <div class="drawer-loading__panel">
                <div class="drawer-loading__label" />
                <div class="drawer-loading__line" />
                <div class="drawer-loading__line drawer-loading__line--sm" />
            </div>
        </div>
    </AppDrawer>
</template>

<script setup>
import { computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AppDrawer from '@/Components/ui/AppDrawer.vue'
import { finishDrawerNavigation } from '@/lib/drawerNavigation'

const props = defineProps({
    open: { type: Boolean, default: false },
    ready: { type: Boolean, default: false },
    title: { type: String, required: true },
    routeHref: { type: String, default: '' },
    closeHref: { type: String, required: true },
    backHref: { type: String, default: '' },
    backLabel: { type: String, default: '' },
    closePreserveScroll: { type: Boolean, default: true },
    closePreserveState: { type: Boolean, default: true },
})

const trailItems = computed(() => {
    if (props.backHref && props.backLabel) {
        return [
            { label: props.backLabel, href: props.backHref },
            { label: props.title },
        ]
    }

    return [{ label: props.title }]
})

watch(() => props.ready, (ready) => {
    if (ready) {
        finishDrawerNavigation(props.routeHref)
    }
}, { immediate: true })

const closeDrawer = () => {
    finishDrawerNavigation(props.routeHref)
    router.visit(props.closeHref, {
        preserveScroll: props.closePreserveScroll,
        preserveState: props.closePreserveState,
        replace: true,
    })
}
</script>
