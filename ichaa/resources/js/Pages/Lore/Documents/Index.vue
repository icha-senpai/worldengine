<template>
    <div>
        <ScaffoldIndexPage
            title="Documents"
            :count="countRecords(documents)"
            count-label="documents"
            sync-resource="documents"
            :create-href="route('documents.create')"
            :create-preserve-scroll="true"
            :create-preserve-state="true"
            create-label="New Document"
            :items="items"
            empty-title="No documents found"
            :empty-cta-href="route('documents.create')"
            :empty-cta-preserve-scroll="true"
            :empty-cta-preserve-state="true"
            empty-cta-label="Create the first document ->"
            
        />

        <CreateDocument
            v-if="createDrawer"
            embedded
            v-bind="createDrawer"
        />
    </div>
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldIndexPage from '@/Components/scaffold/ScaffoldIndexPage.vue'
import CreateDocument from '@/Pages/Lore/Documents/Create.vue'
import { asArray, badge, buildMeta, countRecords, formatLabel } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    documents: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    documentTypes: { type: Array, default: () => [] },
    createDrawer: { type: Object, default: null },
})

const items = computed(() =>
    asArray(props.documents).map((document) => ({
        id: document.id,
        href: route('documents.show', document.id),
        title: document.title,
        badges: [badge('Type', formatLabel(document.document_type))],
        meta: buildMeta([
            { label: 'Status', value: formatLabel(document.document_status) },
            { label: 'Authenticity', value: formatLabel(document.document_authenticity) },
            { label: 'Era', value: document.era_created },
        ]),
    }))
)
</script>
