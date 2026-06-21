<template>
    <ScaffoldFormPage
        title="Edit Travel Route"
        :back-href="route('travel-routes.show', routeRecord.id)"
        back-label="Travel Route"
        :cancel-href="route('travel-routes.show', routeRecord.id)"
        submit-label="Save Route"
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
    route: { type: Object, required: true },
})

const routeRecord = computed(() => props.route)

const form = useForm({
    standard_duration: routeRecord.value.standard_duration ?? '',
    method_variants: routeRecord.value.method_variants ?? null,
    hazards: routeRecord.value.hazards ?? null,
    is_active: routeRecord.value.is_active ?? false,
})

const sections = computed(() => [
    {
        title: 'Route',
        fields: [
            { key: 'standard_duration', label: 'Standard Duration' },
            { key: 'is_active', label: 'Is Active', type: 'checkbox' },
            {
                key: 'method_variants',
                label: 'Method Variants JSON',
                type: 'json',
                jsonMode: 'object-list',
                jsonObjectFields: ['method_name', 'required_ability_or_artifact', 'duration', 'conditions', 'notes'],
                rows: 6,
            },
            {
                key: 'hazards',
                label: 'Hazards JSON',
                type: 'json',
                jsonMode: 'object-list',
                jsonObjectFields: ['hazard_type', 'description', 'era_active', 'severity'],
                rows: 6,
            },
        ],
    },
])

const submit = () => form.put(route('travel-routes.update', routeRecord.value.id))
</script>
