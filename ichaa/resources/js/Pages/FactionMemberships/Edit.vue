<template>
    <ScaffoldFormPage
        title="Edit Faction Membership"
        :back-href="backHref"
        :back-label="backLabel"
        :cancel-href="backHref"
        submit-label="Save Membership"
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
import { toEntityOptions } from '@/Components/scaffold/formatters'

const props = defineProps({
    membership: { type: Object, required: true },
    entities: { type: Array, default: () => [] },
})

const entityOptions = computed(() => toEntityOptions(props.entities))

const backHref = computed(() =>
    props.membership.faction?.id
        ? route('entities.show', props.membership.faction.id)
        : route('entities.index')
)

const backLabel = computed(() => props.membership.faction?.name || 'Entities')

const form = useForm({
    rank_or_role: props.membership.rank_or_role ?? '',
    membership_status: props.membership.membership_status ?? '',
    true_loyalty_entity_id: props.membership.true_loyalty_entity_id ?? '',
    is_undercover: props.membership.is_undercover ?? false,
    public_membership_known: props.membership.public_membership_known ?? true,
})

const sections = computed(() => [
    {
        title: 'Membership',
        fields: [
            { key: 'rank_or_role', label: 'Rank or Role' },
            { key: 'membership_status', label: 'Membership Status' },
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
])

const submit = () => form.put(route('faction-memberships.update', props.membership.id))
</script>
