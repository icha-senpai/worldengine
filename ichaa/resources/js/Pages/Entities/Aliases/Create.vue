<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="New Alias"
        :back-href="backHref"
        back-label="Entity"
        :cancel-href="backHref"
        submit-label="Create Alias"
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

const props = defineProps({
    embedded: { type: Boolean, default: false },
    entity: { type: Object, required: true },
})

const aliasTypeOptions = [
    { value: 'nickname', label: 'Nickname' },
    { value: 'title', label: 'Title' },
    { value: 'codename', label: 'Codename' },
    { value: 'epithet', label: 'Epithet' },
    { value: 'birth_name', label: 'Birth Name' },
    { value: 'alias', label: 'Alias' },
    { value: 'honorific', label: 'Honorific' },
    { value: 'posthumous', label: 'Posthumous' },
    { value: 'other', label: 'Other' },
]

const backHref = computed(() => route('entities.show', { entity: props.entity.id, tab: 'aliases' }))

const form = useForm({
    alias: '',
    alias_type: '',
    context: '',
    era_start: '',
    era_end: '',
    is_active: true,
})

const sections = computed(() => [
    {
        title: 'Alias',
        fields: [
            { key: 'alias', label: 'Alias', required: true, placeholder: 'The alias text' },
            {
                key: 'alias_type',
                label: 'Type',
                type: 'select',
                required: true,
                options: aliasTypeOptions,
                placeholder: 'Select alias type...',
            },
            {
                key: 'context',
                label: 'Context',
                type: 'textarea',
                rows: 3,
                placeholder: 'When, where, or by whom this alias is used.',
            },
            { key: 'era_start', label: 'Era Start', placeholder: 'e.g. Year of the Dragon' },
            { key: 'era_end', label: 'Era End', placeholder: 'Leave blank if still active' },
            { key: 'is_active', label: 'Currently Active', type: 'checkbox' },
        ],
    },
])

const submit = () => form.post(route('entities.aliases.store', {
    entity: props.entity.id,
    tab: 'aliases',
}))
</script>
