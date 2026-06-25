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

        <slot name="toolbar" />

        <div v-if="items.length" class="index-surface">
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
                class="index-record index-record--interactive"
            >
                <div class="index-record__layout">
                    <div class="index-record__copy">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="index-record__title prose-wrap">{{ item.title }}</span>
                            <span
                                v-for="badge in item.badges ?? []"
                                :key="badge.label + badge.value"
                                class="chip"
                            >
                                {{ badge.label }}: {{ badge.value }}
                            </span>
                        </div>

                        <p v-if="item.subtitle" class="index-record__subtitle prose-wrap">
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

                    <div v-if="item.stats?.length" class="index-record__side">
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

        <div v-if="hasPagination" class="index-pagination">
            <span class="text-muted-3 text-sm font-ui">
                Page {{ resolvedPagination.current_page }} of {{ resolvedPagination.last_page }}
            </span>
            <div class="flex items-center gap-1">
                <Link
                    v-if="resolvedPagination.prev_page_url"
                    :href="resolvedPagination.prev_page_url"
                    class="index-pagination__link"
                >
                    ← Prev
                </Link>
                <Link
                    v-if="resolvedPagination.next_page_url"
                    :href="resolvedPagination.next_page_url"
                    class="index-pagination__link"
                >
                    Next →
                </Link>
            </div>
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
import { Link, usePage } from '@inertiajs/vue3'
import NotionSyncButton from '@/Components/NotionSyncButton.vue'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
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
    pagination: { type: Object, default: null },
    emptyTitle: { type: String, default: 'No records found' },
    emptyCtaHref: { type: String, default: '' },
    emptyCtaLabel: { type: String, default: 'Create one ->' },
    emptyCtaPreserveScroll: { type: Boolean, default: false },
    emptyCtaPreserveState: { type: Boolean, default: false },
})

const page = usePage()

const showCreateDrawer = computed(() =>
    Boolean(props.createHref && props.createCloseHref)
    && (props.createDrawerOpen || matchesPendingDrawerHref(props.createHref))
)

const resolvedCreateDrawerTitle = computed(() => props.createDrawerTitle || props.createLabel)

const isPaginationObject = (value) =>
    value
    && typeof value === 'object'
    && Array.isArray(value.data)
    && typeof value.current_page === 'number'
    && typeof value.last_page === 'number'

const inferredPagination = computed(() =>
    Object.values(page.props ?? {}).find(isPaginationObject) ?? null
)

const resolvedPagination = computed(() => props.pagination ?? inferredPagination.value)

const hasPagination = computed(() => (resolvedPagination.value?.last_page ?? 1) > 1)
</script>
