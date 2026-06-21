<template>
    <ScaffoldFormPage
        title="New Faction Membership"
        :back-href="route('entities.index')"
        back-label="Entities"
        :cancel-href="route('entities.index')"
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
    factionEntities: { type: Array, default: () => [] },
    entities: { type: Array, default: () => [] },
    initialFactionEntityId: { type: Number, default: null },
    initialMemberEntityId: { type: Number, default: null },
})

const factionOptions = computed(() => toEntityOptions(props.factionEntities))
const entityOptions = computed(() => toEntityOptions(props.entities))

const form = useForm({
    faction_entity_id: props.initialFactionEntityId ?? '',
    member_entity_id: props.initialMemberEntityId ?? '',
    rank_or_role: '',
    membership_status: '',
    joined_era: '',
    true_loyalty_entity_id: '',
    is_undercover: false,
    public_membership_known: true,
    recruited_by_entity_id: '',
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
            { key: 'membership_status', label: 'Membership Status' },
            { key: 'joined_era', label: 'Joined Era' },
        ],
    },
    {
        title: 'Secrecy',
        fields: [
            {
                key: 'true_loyalty_entity_id',
                label: 'True Loyalty',
                type: 'select',
                options: entityOptions.value,
                placeholder: 'Optional hidden loyalty...',
            },
            {
                key: 'recruited_by_entity_id',
                label: 'Recruited By',
                type: 'select',
                options: entityOptions.value,
                placeholder: 'Optional recruiter...',
            },
            { key: 'is_undercover', label: 'Is Undercover', type: 'checkbox' },
            { key: 'public_membership_known', label: 'Public Membership Known', type: 'checkbox' },
        ],
    },
])

const submit = () => form.post(route('faction-memberships.store'))
</script>
