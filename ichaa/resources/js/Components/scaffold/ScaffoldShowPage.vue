<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="page-hero">
                <div class="page-hero__copy min-w-0">
                    <div class="page-hero__eyebrow">
                        <Link :href="backHref" class="hover:text-muted-2 transition-colors">
                            {{ backLabel }}
                        </Link>
                        <span>/</span>
                        <span v-if="badge" class="chip">{{ badge }}</span>
                    </div>
                    <h1 class="page-hero__title page-hero__title--md">
                        {{ title }}
                    </h1>
                    <p v-if="subtitle" class="page-hero__subtitle prose-wrap">
                        {{ subtitle }}
                    </p>
                    <div v-if="resolvedHeroMeta.length" class="page-hero__meta">
                        <div
                            v-for="item in resolvedHeroMeta"
                            :key="`${item.label}-${item.value}`"
                            class="page-hero__meta-item"
                        >
                            <span class="page-hero__meta-label">{{ item.label }}</span>
                            <span class="page-hero__meta-value">{{ item.value }}</span>
                        </div>
                    </div>
                </div>

                <div class="page-hero__actions">
                    <slot name="hero-actions" />
                    <AppButton v-if="destroyHref" type="button" variant="danger" @click="destroyRecord">
                        {{ destroyLabel }}
                    </AppButton>
                    <AppButton
                        v-if="editHref"
                        :href="editHref"
                        :preserve-scroll="editPreserveScroll"
                        :preserve-state="editPreserveState"
                        :opens-drawer="Boolean(editHref)"
                        variant="ghost"
                    >
                        {{ editLabel }}
                    </AppButton>
                </div>
            </div>
        </template>

        <div v-if="normalizedSections.length" class="show-grid">
            <section
                v-for="section in normalizedSections"
                :key="section.title"
                class="panel show-section"
                :class="{ 'md:col-span-2': section.fullWidth }"
            >
                <div class="show-section__header">
                    <div class="min-w-0">
                        <h3 class="panel-label mb-0!">{{ section.title }}</h3>
                        <p v-if="section.description" class="show-section__description">
                            {{ section.description }}
                        </p>
                    </div>
                    <span v-if="section.entries.length" class="mini-chip show-section__count">
                        {{ section.entries.length }} {{ section.entries.length === 1 ? 'field' : 'fields' }}
                    </span>
                </div>

                <div v-if="section.entries.length" class="show-section__entries">
                    <div v-for="entry in section.entries" :key="section.title + entry.label" class="entry-row">
                        <span class="field-label">{{ entry.label }}</span>
                        <div class="entry-value">
                            <template v-if="entry.kind === 'list'">
                                <div v-if="entry.value?.length" class="entry-stack">
                                    <div
                                        v-for="item in entry.value"
                                        :key="item.label ?? item.value ?? item"
                                        class="entry-stack__item"
                                    >
                                        <template v-if="item.href">
                                            <Link :href="item.href" class="entry-stack__link">
                                                {{ item.label ?? item.value }}
                                            </Link>
                                        </template>
                                        <template v-else>
                                            <span class="entry-stack__text">
                                                {{ item.label ?? item.value ?? item }}
                                            </span>
                                        </template>
                                    </div>
                                </div>
                                <span v-else class="entry-placeholder">--</span>
                            </template>

                            <template v-else-if="entry.kind === 'json'">
                                <RichDocumentValue
                                    v-if="isRichDocument(entry.value)"
                                    :content="entry.value"
                                />
                                <pre v-else class="json-block">{{ prettyJson(entry.value) || '--' }}</pre>
                            </template>

                            <template v-else-if="typeof entry.value === 'boolean'">
                                <span
                                    class="status-badge status-badge--sm"
                                    :class="entry.value ? 'show-status--positive' : 'show-status--muted'"
                                >
                                    {{ entry.value ? 'Yes' : 'No' }}
                                </span>
                            </template>

                            <template v-else-if="entry.href">
                                <Link :href="entry.href" class="entry-link">
                                    {{ summarizeValue(entry.value) }}
                                </Link>
                            </template>

                            <template v-else>
                                <span
                                    class="prose-wrap text-muted-2 text-sm leading-relaxed"
                                    :class="{ 'entry-placeholder': isEmptyValue(entry.value) }"
                                >
                                    {{ summarizeValue(entry.value) }}
                                </span>
                            </template>
                        </div>
                    </div>
                </div>

                <p v-else class="text-muted-3 text-sm font-ui">No data in this section yet.</p>
            </section>
        </div>

        <slot />

        <DrawerRouteShell
            v-if="showEditDrawer"
            :open="showEditDrawer"
            :ready="editDrawerOpen"
            :title="resolvedEditDrawerTitle"
            :route-href="editHref"
            :close-href="editCloseHref || backHref"
            :back-href="backHref"
            :back-label="backLabel"
            :close-preserve-scroll="editPreserveScroll"
            :close-preserve-state="editPreserveState"
        >
            <slot name="edit-drawer" />
        </DrawerRouteShell>

        <NotionNotePanel :note="notionNote" />
    </AuthenticatedLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import NotionNotePanel from '@/Components/NotionNotePanel.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import DrawerRouteShell from '@/Components/ui/DrawerRouteShell.vue'
import RichDocumentValue from '@/Components/scaffold/RichDocumentValue.vue'
import { isRichDocument, prettyJson, summarizeValue } from '@/Components/scaffold/formatters'
import { confirmDialog, showErrorDialog } from '@/lib/appDialog'
import { matchesPendingDrawerHref } from '@/lib/drawerNavigation'

const props = defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    backHref: { type: String, required: true },
    backLabel: { type: String, required: true },
    editHref: { type: String, default: '' },
    editLabel: { type: String, default: 'Edit' },
    editPreserveScroll: { type: Boolean, default: false },
    editPreserveState: { type: Boolean, default: false },
    editDrawerOpen: { type: Boolean, default: false },
    editDrawerTitle: { type: String, default: '' },
    editCloseHref: { type: String, default: '' },
    destroyHref: { type: String, default: '' },
    destroyLabel: { type: String, default: 'Move to Trash' },
    destroyConfirm: { type: String, default: 'Move this item to trash?' },
    badge: { type: String, default: '' },
    heroMeta: { type: Array, default: () => [] },
    sections: { type: Array, default: () => [] },
})

const page = usePage()
const notionNote = computed(() => page.props?.notionNote ?? null)
const normalizedSections = computed(() =>
    props.sections.map((section) => ({
        ...section,
        description: section.description ?? '',
        entries: (section.entries ?? []).filter((entry) => entry && entry.label),
    })),
)
const showEditDrawer = computed(() =>
    Boolean(props.editHref) && (props.editDrawerOpen || matchesPendingDrawerHref(props.editHref))
)
const resolvedEditDrawerTitle = computed(() => props.editDrawerTitle || `Edit ${props.title}`)
const resolvedHeroMeta = computed(() =>
    props.heroMeta.length
        ? props.heroMeta
        : normalizedSections.value
            .flatMap((section) => section.entries)
            .filter((entry) =>
                entry.kind !== 'json'
                && entry.kind !== 'list'
                && !entry.href
                && !isEmptyValue(entry.value)
                && summarizeValue(entry.value).length <= 40,
            )
            .slice(0, 4)
            .map((entry) => ({
                label: entry.label,
                value: summarizeValue(entry.value),
            })),
)

const isEmptyValue = (value) => value === null || value === undefined || value === ''

const destroyRecord = async () => {
    if (!props.destroyHref) {
        return
    }

    const confirmed = await confirmDialog({
        title: props.destroyLabel,
        message: props.destroyConfirm,
        confirmLabel: props.destroyLabel,
        cancelLabel: 'Cancel',
        confirmVariant: 'danger',
    })

    if (!confirmed) {
        return
    }

    router.delete(props.destroyHref, {
        onError: (errors) => {
            void showErrorDialog({
                title: `Could not ${props.destroyLabel.toLowerCase()}`,
                message: 'The request did not complete.',
                details: errors,
            })
        },
    })
}
</script>
