<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="New Faction Membership"
        :back-href="backHref"
        :back-label="backLabel"
        :cancel-href="backHref"
        submit-label="Create Membership"
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
    factionEntities: { type: Array, default: () => [] },
    entities: { type: Array, default: () => [] },
    initialFactionEntityId: { type: Number, default: null },
    initialMemberEntityId: { type: Number, default: null },
    initialFactionEntityName: { type: String, default: '' },
    returnContext: { type: String, default: '' },
    returnEntityId: { type: Number, default: null },
    returnEntityName: { type: String, default: '' },
})

const factionOptions = computed(() => toEntityOptions(props.factionEntities))
const entityOptions = computed(() => toEntityOptions(props.entities))
const membershipStatusOptions = [
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
    { value: 'former', label: 'Former' },
]
const backHref = computed(() =>
    props.returnEntityId
        ? route('entities.show', { entity: props.returnEntityId, tab: 'memberships' })
        : props.initialFactionEntityId
            ? route('entities.show', { entity: props.initialFactionEntityId, tab: 'memberships' })
        : route('entities.index')
)
const backLabel = computed(() => props.returnEntityName || props.initialFactionEntityName || 'Entities')

const form = useForm({
    faction_entity_id: props.initialFactionEntityId ?? '',
    member_entity_id: props.initialMemberEntityId ?? '',
    return_context: props.returnContext ?? '',
    return_entity_id: props.returnEntityId ?? props.initialFactionEntityId ?? props.initialMemberEntityId ?? '',
    rank_or_role: '',
    membership_status: 'active',
    joined_era: '',
    left_era: '',
    departure_reason: null,
    true_loyalty_entity_id: '',
    is_undercover: false,
    public_membership_known: true,
    recruited_by_entity_id: '',
    notes: null,
})

const sections = computed(() => [
    {
        title: 'Membership',
        fields: [
            {
                key: 'faction_entity_id',
                label: 'Faction',
                type: 'select',
                required: true,
                options: factionOptions.value,
                placeholder: 'Select a faction, organization, government, or movement...',
            },
            {
                key: 'member_entity_id',
                label: 'Member',
                type: 'select',
                required: true,
                options: entityOptions.value,
                placeholder: 'Select the member entity...',
            },
            { key: 'rank_or_role', label: 'Rank or Role' },
            {
                key: 'membership_status',
                label: 'Membership Status',
                type: 'select',
                options: membershipStatusOptions,
            },
            { key: 'joined_era', label: 'Joined Era' },
            { key: 'left_era', label: 'Left Era' },
        ],
    },
    {
        title: 'Recruitment and Secrecy',
        fields: [
            {
                key: 'recruited_by_entity_id',
                label: 'Recruited By',
                type: 'select',
                options: entityOptions.value,
                placeholder: 'Optional recruiter...',
            },
            {
                key: 'true_loyalty_entity_id',
                label: 'True Loyalty',
                type: 'select',
                options: entityOptions.value,
                placeholder: 'Optional hidden loyalty...',
            },
            { key: 'is_undercover', label: 'Is Undercover', type: 'checkbox' },
            { key: 'public_membership_known', label: 'Public Membership Known', type: 'checkbox' },
        ],
    },
    {
        title: 'Departure and Notes',
        fields: [
            {
                key: 'departure_reason',
                label: 'Departure Reason',
                type: 'json',
                jsonMode: 'document',
                rows: 4,
                placeholder: 'Why the membership ended, fractured, or changed.',
            },
            {
                key: 'notes',
                label: 'Membership Notes',
                type: 'json',
                jsonMode: 'document',
                rows: 5,
                placeholder: 'Operational notes, leverage, political context, or trust issues.',
            },
        ],
    },
])

const submit = () => form.post(route('faction-memberships.store', {
    return_context: props.returnContext || undefined,
    return_entity_id: props.returnEntityId ?? props.initialFactionEntityId ?? props.initialMemberEntityId ?? undefined,
}))
</script>
