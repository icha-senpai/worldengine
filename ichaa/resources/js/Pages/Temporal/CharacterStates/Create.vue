<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="New Character Snapshot"
        :back-href="route('character-states.index')"
        back-label="Character States"
        :cancel-href="route('character-states.index')"
        submit-label="Create Snapshot"
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
import { toEntityOptions } from '@/Components/scaffold/formatters'

const props = defineProps({
    embedded: { type: Boolean, default: false },
    entities: { type: Array, default: () => [] },
    timelineEntities: { type: Array, default: () => [] },
    eraEntities: { type: Array, default: () => [] },
    stabilityLevels: { type: Array, default: () => [] },
    maskIntegrityLevels: { type: Array, default: () => [] },
    significanceLevels: { type: Array, default: () => [] },
})

const entityOptions = computed(() => toEntityOptions(props.entities))
const timelineOptions = computed(() => toEntityOptions(props.timelineEntities))
const eraOptions = computed(() => toEntityOptions(props.eraEntities))

const form = useForm({
    entity_id: '',
    timeline_id: '',
    era_entity_id: '',
    au_date: '',
    source_date: '',
    snapshot_label: '',
    snapshot_significance: '',
    significance_reason: '',
    current_stability_level: '',
    mask_integrity: '',
    current_trauma_profile: null,
    active_psychological_patterns: null,
    core_wound: '',
    current_desire: '',
    current_fear: '',
    shadow_self: '',
    true_self: '',
    performed_self: '',
    current_power_tier_operating: '',
    current_power_tier_influence: '',
    timeline_position: '',
})

const sections = computed(() => [
    {
        title: 'Snapshot',
        fields: [
            {
                key: 'entity_id',
                label: 'Character Entity',
                type: 'select',
                required: true,
                options: entityOptions.value,
                placeholder: 'Select the character or powered entity...',
            },
            {
                key: 'timeline_id',
                label: 'Timeline',
                type: 'select',
                options: timelineOptions.value,
                placeholder: 'Optional timeline entity...',
            },
            {
                key: 'era_entity_id',
                label: 'Era',
                type: 'select',
                options: eraOptions.value,
                placeholder: 'Optional era or cycle...',
            },
            { key: 'au_date', label: 'AU Date' },
            { key: 'source_date', label: 'Source Date' },
            { key: 'snapshot_label', label: 'Snapshot Label' },
            { key: 'snapshot_significance', label: 'Snapshot Significance', type: 'select', options: props.significanceLevels },
            { key: 'significance_reason', label: 'Significance Reason', type: 'textarea', rows: 3 },
            { key: 'current_stability_level', label: 'Stability Level', type: 'select', options: props.stabilityLevels },
            { key: 'mask_integrity', label: 'Mask Integrity', type: 'select', options: props.maskIntegrityLevels },
            { key: 'timeline_position', label: 'Timeline Position', type: 'number' },
        ],
    },
    {
        title: 'Psychology',
        fields: [
            { key: 'current_trauma_profile', label: 'Trauma Profile', type: 'json', jsonMode: 'document', rows: 4 },
            { key: 'active_psychological_patterns', label: 'Psychological Patterns', type: 'json', jsonMode: 'document', rows: 4 },
            { key: 'core_wound', label: 'Core Wound' },
            { key: 'current_desire', label: 'Current Desire' },
            { key: 'current_fear', label: 'Current Fear' },
            { key: 'shadow_self', label: 'Shadow Self' },
            { key: 'true_self', label: 'True Self' },
            { key: 'performed_self', label: 'Performed Self' },
            { key: 'current_power_tier_operating', label: 'Power Tier Operating' },
            { key: 'current_power_tier_influence', label: 'Power Tier Influence' },
        ],
    },
])

const submit = () => form.post(route('character-states.store'))
</script>
