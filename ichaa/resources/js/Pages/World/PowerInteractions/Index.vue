<template>
    <div>
        <ScaffoldIndexPage
            title="Power Interactions"
            :count="countRecords(interactions)"
            count-label="interactions"
            sync-resource="power_interactions"
            :create-href="route('power-interactions.create')"
            :create-preserve-scroll="true"
            :create-preserve-state="true"
            :create-drawer-open="Boolean(createDrawer)"
            :create-close-href="route('power-interactions.index')"
            create-label="New Interaction"
            :items="items"
            empty-title="No power interactions found"
            :empty-cta-href="route('power-interactions.create')"
            :empty-cta-preserve-scroll="true"
            :empty-cta-preserve-state="true"
            empty-cta-label="Create the first interaction ->"
        >
        <template #toolbar>
            <ScaffoldFilterBar
                :fields="filterFields"
                :form="filterForm"
                :has-active-filters="hasActiveFilters"
                :on-apply="applyFilters"
                :on-clear="clearFilters"
            />
        </template>
        <template #create-drawer>
            <CreatePowerInteraction
                v-if="createDrawer"
                embedded
                v-bind="createDrawer"
            />
        </template>
        </ScaffoldIndexPage>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldFilterBar from '@/Components/scaffold/ScaffoldFilterBar.vue'
import ScaffoldIndexPage from '@/Components/scaffold/ScaffoldIndexPage.vue'
import CreatePowerInteraction from '@/Pages/World/PowerInteractions/Create.vue'
import { asArray, badge, buildMeta, countRecords, formatLabel } from '@/Pages/scaffold/pageBuilders'
import { useIndexFilters } from '@/Pages/scaffold/indexFilters'

const props = defineProps({
    interactions: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    createDrawer: { type: Object, default: null },
})

const { filterForm, hasActiveFilters, applyFilters, clearFilters } = useIndexFilters('power-interactions.index', {
    q: props.filters.q ?? '',
    unresolved: Boolean(props.filters.unresolved),
})

const filterFields = [
    { key: 'q', type: 'text', placeholder: 'Search interactions...' },
    { key: 'unresolved', type: 'checkbox', label: 'Unresolved only' },
]

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
