<template>
    <div>
    <AuthenticatedLayout>

        <template #header>
            <div class="page-hero">
                <div class="page-hero__copy">
                    <div class="page-hero__eyebrow">
                        <span>{{ entities.total }} total</span>
                    </div>
                    <h1 class="page-hero__title page-hero__title--lg">Entities</h1>
                </div>
                <div class="page-hero__actions">
                    <NotionSyncButton resource="entities" label="Sync from Notion" />
                    <AppButton
                        :href="route('entities.create')"
                        :preserve-scroll="true"
                        :preserve-state="true"
                        opens-drawer
                        variant="primary"
                    >
                        + New Entity
                    </AppButton>
                </div>
            </div>
        </template>

        <div class="index-toolbar">

            <div class="relative w-full sm:flex-1 sm:min-w-48 sm:max-w-72">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-muted-3" viewBox="0 0 16 16" fill="none">
                    <circle cx="6.5" cy="6.5" r="5" stroke="currentColor" stroke-width="1.5"/>
                    <path d="M10.5 10.5L14 14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
                <TextInput
                    v-model="filterForm.q"
                    type="text"
                    placeholder="Search entities..."
                    class="pl-8 w-full"
                    @keydown.enter="applyFilters"
                />
            </div>

            <SelectInput v-model="filterForm.type" class="w-full sm:w-auto" @change="applyFilters">
                <option value="">All types</option>
                <template v-for="(types, category) in entityTypes" :key="category">
                    <option :value="typeCategoryValue(category)">{{ formatLabel(category) }}</option>
                    <option v-for="t in types" :key="t" :value="t">{{ typeOptionLabel(t) }}</option>
                </template>
            </SelectInput>

            <SelectInput v-model="filterForm.status" class="w-full sm:w-auto" @change="applyFilters">
                <option value="">All statuses</option>
                <option v-for="s in statuses" :key="s" :value="s">{{ formatLabel(s) }}</option>
            </SelectInput>

            <SelectInput v-model="filterForm.universe" class="w-full sm:w-auto" @change="applyFilters">
                <option value="">All universes</option>
                <option v-for="u in universes" :key="u" :value="u">{{ u }}</option>
            </SelectInput>

            <AppButton
                v-if="hasActiveFilters"
                type="button"
                @click="clearFilters"
                variant="select-danger"
            >
                Clear filters ×
            </AppButton>
        </div>

        <div class="index-surface">

            <div class="hidden md:grid grid-cols-entity-list items-center px-4 py-3 border-b border-border bg-surface">
                <span class="col-label">Entity</span>
                <span class="col-label">Type</span>
                <span class="col-label">Status</span>
                <span class="col-label">Universe</span>
                <span class="col-label text-right">Complete</span>
            </div>

            <div v-if="entities.data.length === 0" class="px-4 py-16 text-center">
                <p class="text-muted-3 text-sm font-ui uppercase tracking-widest">No entities found</p>
                <DrawerLink
                    :href="route('entities.create')"
                    :preserve-scroll="true"
                    :preserve-state="true"
                    opens-drawer
                    class="mt-3 inline-block text-focus text-sm font-ui hover:underline"
                >
                    Create the first one →
                </DrawerLink>
            </div>

            <Link
                v-for="entity in entities.data"
                :key="entity.id"
                :href="route('entities.show', entity.id)"
                class="grid-cols-entity-list flex flex-col gap-3 px-4 py-4 border-b border-border last:border-b-0 hover:bg-surface transition-colors group md:grid md:items-start md:gap-x-0 md:gap-y-2"
            >
                <div class="min-w-0 md:pr-4">
                    <div class="flex items-center gap-2">
                        <span class="text-primary text-base font-light group-hover:text-focus transition-colors prose-wrap">
                            {{ entity.name }}
                        </span>
                        <span v-if="entity.public_title" class="text-muted-3 text-xs font-ui prose-wrap hidden lg:block">
                            · {{ entity.public_title }}
                        </span>
                    </div>
                </div>

                <div class="flex items-center justify-between gap-3 md:block">
                    <span class="mobile-label md:hidden">Type</span>
                    <span class="type-badge" :class="typeBadgeClass(entity.entity_type)">
                        {{ formatLabel(entity.entity_type) }}
                    </span>
                </div>

                <div class="flex items-center justify-between gap-3 md:block">
                    <span class="mobile-label md:hidden">Status</span>
                    <div class="flex items-center md:block">
                        <span class="status-dot" :class="statusDotClass(entity.status)" />
                        <span class="text-muted-2 text-sm font-ui">{{ formatLabel(entity.status) }}</span>
                    </div>
                </div>

                <div class="flex items-center justify-between gap-3 md:block">
                    <span class="mobile-label md:hidden">Universe</span>
                    <div class="prose-wrap text-muted-3 text-sm font-ui">
                        {{ formatUniverses(entity.source_universes) }}
                    </div>
                </div>

                <div class="flex items-center justify-between gap-3 md:justify-end md:self-start">
                    <span class="mobile-label md:hidden">Complete</span>
                    <div class="flex items-center gap-2">
                        <div class="w-16 h-1 bg-surface rounded-full overflow-hidden">
                            <div
                                class="h-full rounded-full transition-all"
                                :class="completionBarClass(entity.completion_score)"
                                :style="{ width: entity.completion_score + '%' }"
                            />
                        </div>
                        <span class="text-muted-3 text-xs font-ui w-8 text-right">
                            {{ entity.completion_score }}%
                        </span>
                    </div>
                </div>

                <p v-if="entity.summary" class="prose-wrap text-muted-3 text-sm leading-snug md:col-span-5">
                    {{ richDocumentToPlainText(entity.summary) }}
                </p>
            </Link>
        </div>

        <div v-if="entities.last_page > 1" class="index-pagination">
            <span class="text-muted-3 text-sm font-ui">
                Page {{ entities.current_page }} of {{ entities.last_page }}
            </span>
            <div class="flex items-center gap-1">
                <Link
                    v-if="entities.prev_page_url"
                    :href="entities.prev_page_url"
                    class="index-pagination__link"
                >
                    ← Prev
                </Link>
                <Link
                    v-if="entities.next_page_url"
                    :href="entities.next_page_url"
                    class="index-pagination__link"
                >
                    Next →
                </Link>
            </div>
        </div>

    </AuthenticatedLayout>

    <DrawerRouteShell
        v-if="showCreateDrawer"
        :open="showCreateDrawer"
        :ready="Boolean(createDrawer)"
        title="New Entity"
        :close-href="route('entities.index')"
        back-label="Entities"
        :back-href="route('entities.index')"
    >
        <CreateEntity
            v-if="createDrawer"
            embedded
            :entity-types="createDrawer.entityTypes"
        />
    </DrawerRouteShell>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import DrawerRouteShell from '@/Components/ui/DrawerRouteShell.vue'
import DrawerLink from '@/Components/ui/DrawerLink.vue'
import CreateEntity from '@/Pages/Entities/Create.vue'
import NotionSyncButton from '@/Components/NotionSyncButton.vue'
import SelectInput from '@/Components/SelectInput.vue'
import TextInput from '@/Components/TextInput.vue'
import { formatLabel, richDocumentToPlainText } from '@/Components/scaffold/formatters'
import { matchesPendingDrawerHref } from '@/lib/drawerNavigation'

const props = defineProps({
    entities:    { type: Object, required: true },
    filters:     { type: Object, default: () => ({}) },
    entityTypes: { type: Object, default: () => ({}) },
    statuses:    { type: Array,  default: () => [] },
    universes:   { type: Array,  default: () => [] },
    createDrawer:{ type: Object, default: null },
})

const filterForm = ref({
    q:        props.filters.q        ?? '',
    type:     props.filters.type     ?? '',
    status:   props.filters.status   ?? '',
    universe: props.filters.universe ?? '',
})

const hasActiveFilters = computed(() =>
    Object.values(filterForm.value).some(v => v !== '')
)

const showCreateDrawer = computed(() =>
    Boolean(props.createDrawer) || matchesPendingDrawerHref(route('entities.create'))
)

let searchDebounce = null
let suppressSearchWatch = false

const applyFilters = () => {
    if (searchDebounce) {
        clearTimeout(searchDebounce)
        searchDebounce = null
    }

    const params = {}
    Object.entries(filterForm.value).forEach(([k, v]) => {
        if (v !== '') params[k] = v
    })

    router.get(route('entities.index'), params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onFinish: () => {
            suppressSearchWatch = false
        },
    })
}

const clearFilters = () => {
    suppressSearchWatch = true

    if (searchDebounce) {
        clearTimeout(searchDebounce)
        searchDebounce = null
    }

    filterForm.value = { q: '', type: '', status: '', universe: '' }
    router.get(route('entities.index'), {}, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onFinish: () => {
            suppressSearchWatch = false
        },
    })
}

watch(() => filterForm.value.q, () => {
    if (suppressSearchWatch) {
        return
    }

    if (searchDebounce) {
        clearTimeout(searchDebounce)
    }

    searchDebounce = setTimeout(() => {
        applyFilters()
    }, 250)
})

onBeforeUnmount(() => {
    if (searchDebounce) {
        clearTimeout(searchDebounce)
    }
})

const typeCategoryValue = (category) => `category:${category}`

const typeOptionLabel = (type) => `- ${formatLabel(type)}`

const formatUniverses = (universes) => {
    if (!universes || universes.length === 0) return 'Native'
    return universes.slice(0, 2).join(', ') + (universes.length > 2 ? ` +${universes.length - 2}` : '')
}

const typeBadgeClass = (type) => {
    const map = {
        character:            'type--character',
        faction:              'type--faction',
        location:             'type--location',
        event:                'type--event',
        object:               'type--object',
        constructed_intel:    'type--ci',
        power_system:         'type--power',
    }
    return map[type] ?? 'type--default'
}

const statusDotClass = (status) => {
    const map = {
        active:    'dot--active',
        concept:   'dot--concept',
        archived:  'dot--archived',
        deceased:  'dot--deceased',
        destroyed: 'dot--deceased',
    }
    return map[status] ?? 'dot--default'
}

const completionBarClass = (score) => {
    if (score >= 80) return 'bg-success'
    if (score >= 50) return 'bg-focus'
    if (score >= 20) return 'bg-warn'
    return 'bg-danger'
}
</script>

