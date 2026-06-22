<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-primary text-3xl font-light tracking-wide">{{ title }}</h1>
                    <p class="text-muted-3 text-sm font-ui mt-1">
                        {{ count }} {{ countLabel }}
                    </p>
                </div>
                <div v-if="syncResource || createHref" class="flex flex-wrap items-center justify-end gap-2">
                    <NotionSyncButton
                        v-if="syncResource"
                        :resource="syncResource"
                        :label="syncLabel"
                    />
                    <AppButton v-if="createHref" :href="createHref" variant="primary">
                        {{ createLabel }}
                    </AppButton>
                </div>
            </div>
        </template>

        <div v-if="items.length" class="space-y-3">
            <component
                :is="item.href ? Link : 'div'"
                v-for="item in items"
                :key="item.id ?? item.title"
                v-bind="item.href ? { href: item.href } : {}"
                class="record-card record-card--interactive"
            >
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 flex-wrap mb-1">
                            <span class="prose-wrap text-primary text-base font-light leading-snug">{{ item.title }}</span>
                            <span
                                v-for="badge in item.badges ?? []"
                                :key="badge.label + badge.value"
                                class="chip"
                            >
                                {{ badge.label }}: {{ badge.value }}
                            </span>
                        </div>

                        <p v-if="item.subtitle" class="prose-wrap text-muted-3 text-sm leading-relaxed mb-3">
                            {{ item.subtitle }}
                        </p>

                        <div v-if="item.meta?.length" class="flex flex-wrap gap-3">
                            <span
                                v-for="meta in item.meta"
                                :key="meta.label + meta.value"
                                class="meta-tag"
                            >
                                {{ meta.label }}: {{ meta.value }}
                            </span>
                        </div>
                    </div>

                    <div v-if="item.stats?.length" class="flex flex-col items-end gap-1 flex-shrink-0">
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
            <p class="text-muted-3 text-sm font-ui uppercase tracking-widest mb-3">{{ emptyTitle }}</p>
            <Link v-if="emptyCtaHref" :href="emptyCtaHref" class="text-cyan text-sm font-ui hover:underline">
                {{ emptyCtaLabel }}
            </Link>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import NotionSyncButton from '@/Components/NotionSyncButton.vue'
import AppButton from '@/Components/ui/AppButton.vue'

defineProps({
    title: { type: String, required: true },
    count: { type: Number, default: 0 },
    countLabel: { type: String, default: 'records' },
    syncResource: { type: String, default: '' },
    syncLabel: { type: String, default: 'Sync from Notion' },
    createHref: { type: String, default: '' },
    createLabel: { type: String, default: 'Create' },
    items: { type: Array, default: () => [] },
    emptyTitle: { type: String, default: 'No records found' },
    emptyCtaHref: { type: String, default: '' },
    emptyCtaLabel: { type: String, default: 'Create one ->' },
})
</script>
