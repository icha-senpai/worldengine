<template>
    <AppDrawer
        v-if="open"
        :title="title"
        :trail-items="trailItems"
        :animate-on-mount="shouldAnimateOnMount"
        :close-href="closeHref"
        @close="closeDrawer"
    >
        <Transition name="drawer-content-fade">
            <div v-if="showContent" key="content" class="drawer-content-shell">
                <slot />
            </div>
            <div v-else key="loading" class="drawer-loading" aria-hidden="true">
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
        </Transition>
    </AppDrawer>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AppDrawer from '@/Components/ui/AppDrawer.vue'
import { finishDrawerNavigation, matchesPendingDrawerHref } from '@/lib/drawerNavigation'

const MIN_LOADING_MS = 160

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

const shouldAnimateOnMount = computed(() => !(props.ready && matchesPendingDrawerHref(props.routeHref)))

const showContent = ref(Boolean(props.open && props.ready))
const openedAt = ref(0)
const pendingAtOpen = ref(false)
let revealTimer = null

const clearRevealTimer = () => {
    if (revealTimer !== null) {
        window.clearTimeout(revealTimer)
        revealTimer = null
    }
}

const revealContent = () => {
    clearRevealTimer()
    showContent.value = true
    if (props.ready) {
        finishDrawerNavigation(props.routeHref)
    }
}

const syncContentVisibility = () => {
    clearRevealTimer()

    if (!props.open) {
        showContent.value = false
        return
    }

    if (!props.ready) {
        showContent.value = false
        return
    }

    if (!pendingAtOpen.value) {
        revealContent()
        return
    }

    const elapsed = Date.now() - openedAt.value
    const remaining = Math.max(MIN_LOADING_MS - elapsed, 0)

    if (remaining === 0) {
        revealContent()
        return
    }

    showContent.value = false
    revealTimer = window.setTimeout(() => {
        revealContent()
    }, remaining)
}

watch(() => props.open, (open) => {
    if (open) {
        openedAt.value = Date.now()
        pendingAtOpen.value = matchesPendingDrawerHref(props.routeHref)
    } else {
        pendingAtOpen.value = false
    }

    syncContentVisibility()
}, { immediate: true })

watch(() => props.ready, () => {
    syncContentVisibility()
})

watch(() => props.routeHref, () => {
    if (props.open) {
        pendingAtOpen.value = matchesPendingDrawerHref(props.routeHref)
        syncContentVisibility()
    }
})

onBeforeUnmount(() => {
    clearRevealTimer()
})

const closeDrawer = () => {
    clearRevealTimer()
    finishDrawerNavigation(props.routeHref)
    router.visit(props.closeHref, {
        preserveScroll: props.closePreserveScroll,
        preserveState: props.closePreserveState,
        replace: true,
    })
}
</script>
