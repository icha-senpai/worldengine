<template>
    <div>
    <AuthenticatedLayout>

        <template #header>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <h1 class="text-primary text-2xl font-light tracking-wide">Writing Pipeline</h1>
                    <p class="text-muted-3 text-sm font-ui mt-1">
                        {{ items.total }} item{{ items.total !== 1 ? 's' : '' }}
                        <span v-if="hasFilters"> · filtered</span>
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <NotionSyncButton resource="pipeline_items" label="Sync from Notion" />
                    <AppButton
                        :href="route('pipeline.create')"
                        :preserve-scroll="true"
                        :preserve-state="true"
                        variant="primary"
                    >
                        New Item
                    </AppButton>
                </div>
            </div>
        </template>

        <!-- FILTERS -->
        <div class="flex items-center gap-3 mb-5 flex-wrap">

            <div class="flex gap-1.5 flex-wrap">
                <AppButton
                    @click="setFilter('type', '')"
                    variant="select"
                    :selected="!filters.type"
                >All</AppButton>
                <AppButton
                    v-for="t in pipelineTypes"
                    :key="t"
                    @click="setFilter('type', t)"
                    variant="select"
                    :selected="filters.type === t"
                >{{ formatLabel(t) }}</AppButton>
            </div>

            <span class="hidden text-border text-sm font-ui sm:inline">|</span>

            <div class="flex gap-1.5 flex-wrap">
                <AppButton
                    @click="setFilter('stage', '')"
                    variant="select"
                    :selected="!filters.stage"
                >All Stages</AppButton>
                <AppButton
                    v-for="s in pipelineStages"
                    :key="s"
                    @click="setFilter('stage', s)"
                    variant="select"
                    :selected="filters.stage === s"
                    :class="['stage--' + s]"
                >{{ formatLabel(s) }}</AppButton>
            </div>

            <AppButton v-if="hasFilters" @click="clearFilters" variant="select-danger">
                Clear
            </AppButton>

        </div>

        <!-- LIST -->
        <div v-if="items.data.length" class="space-y-2">
            <Link
                v-for="item in items.data"
                :key="item.id"
                :href="route('pipeline.show', item.id)"
                class="record-card record-card--interactive"
            >
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">

                    <!-- Left: title + meta -->
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="type-chip" :class="'type--' + item.pipeline_type">
                                {{ formatLabel(item.pipeline_type) }}
                            </span>
                            <span class="stage-chip" :class="'stage--' + item.pipeline_stage">
                                {{ formatLabel(item.pipeline_stage) }}
                            </span>
                        </div>
                        <p class="prose-wrap text-primary text-base font-light leading-snug">{{ item.title }}</p>
                        <div class="flex flex-wrap items-center gap-3 mt-1.5">
                            <span v-if="item.pov_character" class="meta-tag">
                                POV: {{ item.pov_character.name }}
                            </span>
                            <span v-if="item.location" class="meta-tag">
                                @ {{ item.location.name }}
                            </span>
                            <span v-if="item.emotional_beat" class="meta-tag">
                                {{ formatLabel(item.emotional_beat) }}
                            </span>
                            <span v-if="item.word_count" class="meta-tag">
                                {{ item.word_count.toLocaleString() }}w
                            </span>
                        </div>
                    </div>

                    <!-- Right: children count if any -->
                    <div v-if="item.children_count > 0" class="flex-shrink-0 text-left sm:text-right">
                        <span class="count-badge">{{ item.children_count }}</span>
                    </div>

                </div>
            </Link>
        </div>

        <div v-else class="empty-state-panel">
            <p class="text-muted-3 text-sm font-ui uppercase tracking-widest mb-2">No pipeline items found</p>
            <DrawerLink
                :href="route('pipeline.create')"
                :preserve-scroll="true"
                :preserve-state="true"
                title="New Pipeline Item"
                class="text-cyan text-sm font-ui hover:underline"
            >
                Create your first item →
            </DrawerLink>
        </div>

        <!-- PAGINATION -->
        <div v-if="items.last_page > 1" class="mt-6 flex flex-col gap-3 border-t border-border pt-4 sm:flex-row sm:items-center sm:justify-between">
            <span class="text-muted-3 text-sm font-ui">
                Page {{ items.current_page }} of {{ items.last_page }}
            </span>
            <div class="flex gap-2">
                <AppButton
                    v-if="items.prev_page_url"
                    :href="items.prev_page_url"
                    variant="ghost"
                >← Prev</AppButton>
                <AppButton
                    v-if="items.next_page_url"
                    :href="items.next_page_url"
                    variant="ghost"
                >Next →</AppButton>
            </div>
        </div>

    </AuthenticatedLayout>

    <DrawerRouteShell
        v-if="showCreateDrawer"
        :open="showCreateDrawer"
        :ready="Boolean(createDrawer)"
        title="New Pipeline Item"
        :close-href="route('pipeline.index')"
        back-label="Writing Pipeline"
        :back-href="route('pipeline.index')"
    >
        <CreatePipelineItem
            v-if="createDrawer"
            embedded
            v-bind="createDrawer"
        />
    </DrawerRouteShell>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import DrawerRouteShell from '@/Components/ui/DrawerRouteShell.vue'
import DrawerLink from '@/Components/ui/DrawerLink.vue'
import NotionSyncButton from '@/Components/NotionSyncButton.vue'
import CreatePipelineItem from '@/Pages/Production/Pipeline/Create.vue'
import { matchesPendingDrawerHref } from '@/lib/drawerNavigation'

const props = defineProps({
    items:          { type: Object, required: true },
    filters:        { type: Object, default: () => ({}) },
    pipelineTypes:  { type: Array, default: () => [] },
    pipelineStages: { type: Array, default: () => [] },
    createDrawer:   { type: Object, default: null },
})

const hasFilters = computed(() =>
    Object.values(props.filters).some(v => v !== '' && v !== null && v !== undefined)
)

const showCreateDrawer = computed(() =>
    Boolean(props.createDrawer) || matchesPendingDrawerHref(route('pipeline.create'))
)

const setFilter = (key, value) => {
    router.get(route('pipeline.index'), {
        ...props.filters,
        [key]: value || undefined,
    }, { preserveState: true, replace: true })
}

const clearFilters = () => {
    router.get(route('pipeline.index'), {}, { replace: true })
}

const formatLabel = (str) => str
    ? str.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
    : '—'
</script>

