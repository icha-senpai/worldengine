<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="New Group Relationship"
        :back-href="route('group-relationships.index')"
        back-label="Group Relationships"
        :cancel-href="route('group-relationships.index')"
        submit-label="Create Group"
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
    tensionCharges: { type: Array, default: () => [] },
})

const form = useForm({
    name: '',
    relationship_type: '',
    current_tension_charge: '',
    is_active: true,
    visibility: 'private',
    content_classification: 'restricted',
})

const sections = computed(() => [
    {
        title: 'Group',
        fields: [
            { key: 'name', label: 'Name', required: true },
            { key: 'relationship_type', label: 'Relationship Type', required: true, placeholder: 'alliance, coalition, house, cabal...' },
            { key: 'current_tension_charge', label: 'Tension Charge', type: 'select', options: props.tensionCharges },
            { key: 'is_active', label: 'Is Active', type: 'checkbox' },
            { key: 'visibility', label: 'Visibility' },
            { key: 'content_classification', label: 'Content Classification' },
        ],
    },
])

const submit = () => form.post(route('group-relationships.store'))
</script>
