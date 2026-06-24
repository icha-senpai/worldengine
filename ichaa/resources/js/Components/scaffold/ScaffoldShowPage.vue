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
                </div>

                <div class="page-hero__actions">
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

        <div class="grid gap-5 md:grid-cols-2">
            <section
                v-for="section in sections"
                :key="section.title"
                class="panel"
                :class="{ 'md:col-span-2': section.fullWidth }"
            >
                <h3 class="panel-label">{{ section.title }}</h3>

                <div v-if="section.entries?.length">
                    <div v-for="entry in section.entries" :key="section.title + entry.label" class="entry-row">
                        <span class="field-label">{{ entry.label }}</span>
                        <div class="entry-value">
                            <template v-if="entry.kind === 'list'">
                                <ul v-if="entry.value?.length" class="space-y-1">
                                    <li v-for="item in entry.value" :key="item.label ?? item.value ?? item" class="text-muted-2 text-sm leading-relaxed">
                                        <template v-if="item.href">
                                            <Link :href="item.href" class="text-cyan hover:underline">
                                                {{ item.label ?? item.value }}
                                            </Link>
                                        </template>
                                        <template v-else>
                                            {{ item.label ?? item.value ?? item }}
                                        </template>
                                    </li>
                                </ul>
                                <span v-else class="text-muted-3 text-sm font-ui">--</span>
                            </template>

                            <template v-else-if="entry.kind === 'json'">
                                <RichDocumentValue
                                    v-if="isRichDocument(entry.value)"
                                    :content="entry.value"
                                />
                                <pre v-else class="json-block">{{ prettyJson(entry.value) || '--' }}</pre>
                            </template>

                            <template v-else-if="entry.href">
                                <Link :href="entry.href" class="text-cyan hover:underline">
                                    {{ summarizeValue(entry.value) }}
                                </Link>
                            </template>

                            <template v-else>
                                <span class="prose-wrap text-muted-2 text-sm leading-relaxed">
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
    sections: { type: Array, default: () => [] },
})

const page = usePage()
const notionNote = computed(() => page.props?.notionNote ?? null)
const showEditDrawer = computed(() =>
    Boolean(props.editHref) && (props.editDrawerOpen || matchesPendingDrawerHref(props.editHref))
)
const resolvedEditDrawerTitle = computed(() => props.editDrawerTitle || `Edit ${props.title}`)

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
