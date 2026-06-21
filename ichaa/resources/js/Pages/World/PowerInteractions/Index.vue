<template>
    <ScaffoldIndexPage
        title="Power Interactions"
        :count="countRecords(interactions)"
        count-label="interactions"
        :create-href="route('power-interactions.create')"
        create-label="New Interaction"
        :items="items"
        empty-title="No power interactions found"
        :empty-cta-href="route('power-interactions.create')"
        empty-cta-label="Create the first interaction ->"
    />
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldIndexPage from '@/Components/scaffold/ScaffoldIndexPage.vue'
import { asArray, badge, buildMeta, countRecords, formatLabel } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    interactions: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
})

const items = computed(() =>
    asArray(props.interactions).map((interaction) => ({
        id: interaction.id,
        href: route('power-interactions.show', interaction.id),
        title: interaction.interaction_name,
        subtitle: `${interaction.system_a?.name ?? 'Unknown'} vs ${interaction.system_b?.name ?? 'Unknown'}`,
        badges: [
            badge('Knowledge', formatLabel(interaction.knowledge_state)),
            badge('Danger', formatLabel(interaction.danger_rating)),
        ],
        meta: buildMeta([
            { label: 'Scale', value: formatLabel(interaction.interaction_scale) },
            { label: 'Direction', value: formatLabel(interaction.directionality) },
            { label: 'Unresolved', value: interaction.unresolved_flag },
        ]),
    }))
)
</script>
