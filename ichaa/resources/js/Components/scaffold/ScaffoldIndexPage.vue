<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="page-hero">
                <div class="page-hero__copy">
                    <div class="page-hero__eyebrow">
                        <span>{{ count }} {{ countLabel }}</span>
                    </div>
                    <h1 class="page-hero__title page-hero__title--lg">{{ title }}</h1>
                </div>
                <div v-if="syncResource || createHref" class="page-hero__actions">
                    <NotionSyncButton
                        v-if="syncResource"
                        :resource="syncResource"
                        :label="syncLabel"
                    />
                    <AppButton
                        v-if="createHref"
                        :href="createHref"
                        :preserve-scroll="createPreserveScroll"
                        :preserve-state="createPreserveState"
                        :opens-drawer="Boolean(createHref && createCloseHref)"
                        variant="primary"
                    >
                        {{ createLabel }}
                    </AppButton>
                </div>
            </div>
        </template>

        <div v-if="items.length" class="surface-list">
            <component
                :is="item.href ? DrawerLink : 'div'"
                v-for="item in items"
                :key="item.id ?? item.title"
                v-bind="item.href ? {
                    href: item.href,
                    preserveScroll: item.preserveScroll ?? false,
                    preserveState: item.preserveState ?? false,
                    opensDrawer: item.opensDrawer ?? false,
                } : {}"
                class="record-card record-card--interactive"
            >
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="prose-wrap text-primary text-lg font-light leading-snug">{{ item.title }}</span>
                            <span
                                v-for="badge in item.badges ?? []"
                                :key="badge.label + badge.value"
                                class="chip"
                            >
                                {{ badge.label }}: {{ badge.value }}
                            </span>
                        </div>

                        <p v-if="item.subtitle" class="prose-wrap mt-2 text-muted-2 text-sm leading-relaxed">
                            {{ item.subtitle }}
                        </p>

                        <div v-if="item.meta?.length" class="mt-4 flex flex-wrap gap-2.5">
                            <span
                                v-for="meta in item.meta"
                                :key="meta.label + meta.value"
                                class="meta-tag"
                            >
                                {{ meta.label }}: {{ meta.value }}
                            </span>
                        </div>
                    </div>

                    <div v-if="item.stats?.length" class="flex flex-wrap items-center gap-2 lg:max-w-[16rem] lg:flex-col lg:items-end lg:justify-start">
                        <span
                            v-for="stat in item.stats"
                            :key="stat.label + stat.value"
                            class="stat-pill"
                        >
                            {{ stat.label }} {{ stat.value }}
                        </span>
                    </div>
                </div>
            </component>
        </div>

        <div v-else class="empty-state-panel">
            <p class="empty-title mb-2">{{ emptyTitle }}</p>
            <DrawerLink
                v-if="emptyCtaHref"
                :href="emptyCtaHref"
                :preserve-scroll="emptyCtaPreserveScroll"
                :preserve-state="emptyCtaPreserveState"
                :opens-drawer="Boolean(emptyCtaHref && emptyCtaHref === createHref && createCloseHref)"
                class="text-cyan text-sm font-ui uppercase tracking-[0.12em] hover:underline"
            >
                {{ emptyCtaLabel }}
            </DrawerLink>
        </div>

        <DrawerRouteShell
            v-if="showCreateDrawer"
            :open="showCreateDrawer"
            :ready="createDrawerOpen"
            :title="resolvedCreateDrawerTitle"
            :route-href="createHref"
            :close-href="createCloseHref"
            :back-label="title"
            :back-href="createCloseHref"
            :close-preserve-scroll="createPreserveScroll"
            :close-preserve-state="createPreserveState"
        >
            <slot name="create-drawer" />
        </DrawerRouteShell>
    </AuthenticatedLayout>
</template>

<script setup>
import { computed } from 'vue'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import NotionSyncButton from '@/Components/NotionSyncButton.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import DrawerRouteShell from '@/Components/ui/DrawerRouteShell.vue'
import DrawerLink from '@/Components/ui/DrawerLink.vue'
import { matchesPendingDrawerHref } from '@/lib/drawerNavigation'

const props = defineProps({
    title: { type: String, required: true },
    count: { type: Number, default: 0 },
    countLabel: { type: String, default: 'records' },
    syncResource: { type: String, default: '' },
    syncLabel: { type: String, default: 'Sync from Notion' },
    createHref: { type: String, default: '' },
    createLabel: { type: String, default: 'Create' },
    createPreserveScroll: { type: Boolean, default: false },
    createPreserveState: { type: Boolean, default: false },
    createDrawerOpen: { type: Boolean, default: false },
    createDrawerTitle: { type: String, default: '' },
    createCloseHref: { type: String, default: '' },
    items: { type: Array, default: () => [] },
    emptyTitle: { type: String, default: 'No records found' },
    emptyCtaHref: { type: String, default: '' },
    emptyCtaLabel: { type: String, default: 'Create one ->' },
    emptyCtaPreserveScroll: { type: Boolean, default: false },
    emptyCtaPreserveState: { type: Boolean, default: false },
})

const showCreateDrawer = computed(() =>
    Boolean(props.createHref && props.createCloseHref)
    && (props.createDrawerOpen || matchesPendingDrawerHref(props.createHref))
)

const resolvedCreateDrawerTitle = computed(() => props.createDrawerTitle || props.createLabel)
</script>
