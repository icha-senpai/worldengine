<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="Edit Group Relationship"
        :back-href="route('group-relationships.show', group.id)"
        back-label="Group"
        :cancel-href="route('group-relationships.show', group.id)"
        submit-label="Save Group"
        processing-label="Saving..."
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
    group: { type: Object, required: true },
    tensionCharges: { type: Array, default: () => [] },
})

const form = useForm({
    name: props.group.name ?? '',
    relationship_type: props.group.relationship_type ?? '',
    current_tension_charge: props.group.current_tension_charge ?? '',
    charge_change_reason: '',
    is_active: props.group.is_active ?? false,
})

const sections = computed(() => [
    {
        title: 'Group',
        fields: [
            { key: 'name', label: 'Name', required: true },
            { key: 'relationship_type', label: 'Relationship Type', required: true },
            { key: 'current_tension_charge', label: 'Tension Charge', type: 'select', options: props.tensionCharges },
            { key: 'charge_change_reason', label: 'Charge Change Reason', type: 'textarea', rows: 3 },
            { key: 'is_active', label: 'Is Active', type: 'checkbox' },
        ],
    },
])

const submit = () => form.put(route('group-relationships.update', props.group.id))
</script>
