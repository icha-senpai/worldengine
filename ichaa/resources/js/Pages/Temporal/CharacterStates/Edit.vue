<template>
    <ScaffoldFormPage
        title="Edit Character Snapshot"
        :back-href="route('character-states.show', state.id)"
        back-label="Snapshot"
        :cancel-href="route('character-states.show', state.id)"
        submit-label="Save Snapshot"
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
    state: { type: Object, required: true },
    stabilityLevels: { type: Array, default: () => [] },
    maskIntegrityLevels: { type: Array, default: () => [] },
    significanceLevels: { type: Array, default: () => [] },
})

const form = useForm({
    au_date: props.state.au_date ?? '',
    source_date: props.state.source_date ?? '',
    snapshot_label: props.state.snapshot_label ?? '',
    snapshot_significance: props.state.snapshot_significance ?? '',
    significance_reason: props.state.significance_reason ?? '',
    current_stability_level: props.state.current_stability_level ?? '',
    mask_integrity: props.state.mask_integrity ?? '',
    current_trauma_profile: props.state.current_trauma_profile ?? '',
    active_psychological_patterns: props.state.active_psychological_patterns ?? '',
    core_wound: props.state.core_wound ?? '',
    current_desire: props.state.current_desire ?? '',
    current_fear: props.state.current_fear ?? '',
    current_power_tier_operating: props.state.current_power_tier_operating ?? '',
    current_power_tier_influence: props.state.current_power_tier_influence ?? '',
    timeline_position: props.state.timeline_position ?? '',
})

const sections = computed(() => [
    {
        title: 'Snapshot',
        fields: [
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
            { key: 'current_trauma_profile', label: 'Trauma Profile', type: 'textarea', rows: 3 },
            { key: 'active_psychological_patterns', label: 'Psychological Patterns', type: 'textarea', rows: 3 },
            { key: 'core_wound', label: 'Core Wound' },
            { key: 'current_desire', label: 'Current Desire' },
            { key: 'current_fear', label: 'Current Fear' },
            { key: 'current_power_tier_operating', label: 'Power Tier Operating' },
            { key: 'current_power_tier_influence', label: 'Power Tier Influence' },
        ],
    },
])

const submit = () => form.put(route('character-states.update', props.state.id))
</script>
