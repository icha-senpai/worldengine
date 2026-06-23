<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                        <Link :href="backHref" class="text-muted-3 text-sm font-ui hover:text-muted-2 transition-colors">
                            {{ backLabel }}
                        </Link>
                        <span class="text-muted-3 text-sm font-ui">/</span>
                        <span v-if="badge" class="chip">{{ badge }}</span>
                    </div>
                    <h1 class="text-primary text-2xl font-light tracking-wide leading-tight">
                        {{ title }}
                    </h1>
                    <p v-if="subtitle" class="prose-wrap text-muted-3 text-base mt-2">
                        {{ subtitle }}
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <AppButton v-if="destroyHref" type="button" variant="danger" @click="destroyRecord">
                        {{ destroyLabel }}
                    </AppButton>
                    <AppButton
                        v-if="editHref"
                        :href="editHref"
                        :preserve-scroll="editPreserveScroll"
                        :preserve-state="editPreserveState"
                        variant="ghost"
                    >
                        {{ editLabel }}
                    </AppButton>
                </div>
            </div>
        </template>

        <div class="grid gap-4 md:grid-cols-2">
            <section
                v-for="section in sections"
                :key="section.title"
                class="panel"
                :class="{ 'md:col-span-2': section.fullWidth }"
            >
                <h3 class="panel-label">{{ section.title }}</h3>

                <div v-if="section.entries?.length" class="space-y-4">
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
                                <span v-else class="text-muted-3 text-sm font-ui">—</span>
                            </template>

                            <template v-else-if="entry.kind === 'json'">
                                <RichDocumentValue
                                    v-if="isRichDocument(entry.value)"
                                    :content="entry.value"
                                />
                                <pre v-else class="json-block">{{ prettyJson(entry.value) || '—' }}</pre>
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

        <NotionNotePanel :note="notionNote" />
    </AuthenticatedLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import NotionNotePanel from '@/Components/NotionNotePanel.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import RichDocumentValue from '@/Components/scaffold/RichDocumentValue.vue'
import { isRichDocument, prettyJson, summarizeValue } from '@/Components/scaffold/formatters'

const props = defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    backHref: { type: String, required: true },
    backLabel: { type: String, required: true },
    editHref: { type: String, default: '' },
    editLabel: { type: String, default: 'Edit' },
    editPreserveScroll: { type: Boolean, default: false },
    editPreserveState: { type: Boolean, default: false },
    destroyHref: { type: String, default: '' },
    destroyLabel: { type: String, default: 'Move to Trash' },
    destroyConfirm: { type: String, default: 'Move this item to trash?' },
    badge: { type: String, default: '' },
    sections: { type: Array, default: () => [] },
})

const page = usePage()
const notionNote = computed(() => page.props?.notionNote ?? null)

const destroyRecord = () => {
    if (!props.destroyHref || !confirm(props.destroyConfirm)) {
        return
    }

    router.delete(props.destroyHref)
}
</script>
