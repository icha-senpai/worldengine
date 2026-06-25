<template>
    <ScaffoldFormPage
        presentation="drawer"
        :embedded="props.embedded"
        title="Edit Faction Membership"
        :back-href="backHref"
        :back-label="backLabel"
        :cancel-href="backHref"
        submit-label="Save Membership"
        processing-label="Saving..."
        :destroy-href="route('faction-memberships.destroy', { faction_membership: membership.id, return_context: props.returnContext || undefined, return_entity_id: resolvedReturnEntityId })"
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
    membership: { type: Object, required: true },
    factionEntities: { type: Array, default: () => [] },
    entities: { type: Array, default: () => [] },
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
const resolvedReturnEntityId = computed(() => props.returnEntityId ?? props.membership.faction?.id ?? null)

const backHref = computed(() =>
    resolvedReturnEntityId.value
        ? route('entities.show', { entity: resolvedReturnEntityId.value, tab: 'memberships' })
        : route('entities.index')
)

const backLabel = computed(() => props.returnEntityName || props.membership.faction?.name || 'Entities')

const form = useForm({
    faction_entity_id: props.membership.faction_entity_id ?? '',
    member_entity_id: props.membership.member_entity_id ?? '',
    return_context: props.returnContext ?? '',
    return_entity_id: resolvedReturnEntityId.value ?? '',
    rank_or_role: props.membership.rank_or_role ?? '',
    membership_status: props.membership.membership_status ?? 'active',
    joined_era: props.membership.joined_era ?? '',
    left_era: props.membership.left_era ?? '',
    departure_reason: props.membership.departure_reason ?? null,
    recruited_by_entity_id: props.membership.recruited_by_entity_id ?? '',
    true_loyalty_entity_id: props.membership.true_loyalty_entity_id ?? '',
    is_undercover: props.membership.is_undercover ?? false,
    public_membership_known: props.membership.public_membership_known ?? true,
    notes: props.membership.notes ?? null,
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

const submit = () => form.put(route('faction-memberships.update', {
    faction_membership: props.membership.id,
    return_context: props.returnContext || undefined,
    return_entity_id: resolvedReturnEntityId.value ?? undefined,
}))
</script>
