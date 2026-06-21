<template>
    <ScaffoldFormPage
        title="New Collection"
        :back-href="route('collections.index')"
        back-label="Collections"
        :cancel-href="route('collections.index')"
        submit-label="Create Collection"
        processing-label="Creating..."
        :form="form"
        :sections="sections"
        :on-submit="submit"
    />
</template>

<script setup>
import { computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import ScaffoldFormPage from '@/Components/scaffold/ScaffoldFormPage.vue'
import { toCollectionOptions } from '@/Components/scaffold/formatters'

const props = defineProps({
    collections: { type: Array, default: () => [] },
    types: { type: Array, default: () => [] },
    modes: { type: Array, default: () => [] },
})

const collectionOptions = computed(() => toCollectionOptions(props.collections))

const form = useForm({
    name: '',
    collection_type: '',
    collection_mode: '',
    rules: null,
    parent_collection_id: '',
    visibility: '',
    content_classification: '',
})

const sections = computed(() => [
    {
        title: 'Identity',
        fields: [
            { key: 'name', label: 'Name', required: true, placeholder: 'Collection name' },
            { key: 'collection_type', label: 'Collection Type', type: 'select', required: true, options: props.types },
            { key: 'collection_mode', label: 'Collection Mode', type: 'select', required: true, options: props.modes },
            {
                key: 'parent_collection_id',
                label: 'Parent Collection',
                type: 'select',
                options: collectionOptions.value,
                placeholder: 'Optional parent collection...',
            },
        ],
    },
    {
        title: 'Access',
        fields: [
            { key: 'visibility', label: 'Visibility', placeholder: 'private, author_only, public_knowledge...' },
            { key: 'content_classification', label: 'Content Classification', placeholder: 'restricted, sensitive, open...' },
        ],
    },
    {
        title: 'Rules',
        fields: [
            { key: 'rules', label: 'Rules JSON', type: 'json', jsonMode: 'collection-rules', rows: 8 },
        ],
    },
])

const submit = () => form.post(route('collections.store'))
</script>
