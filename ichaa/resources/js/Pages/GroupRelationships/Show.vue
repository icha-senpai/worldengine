<template>
    <ScaffoldShowPage
        :title="group.name"
        back-label="Group Relationships"
        :back-href="route('group-relationships.index')"
        :edit-href="route('group-relationships.edit', group.id)"
        :badge="group.relationship_type || 'group'"
        :sections="sections"
    />
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'
import { sectionEntry } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    group: { type: Object, required: true },
})

const sections = computed(() => [
    {
        title: 'Overview',
        entries: [
            sectionEntry('Relationship Type', props.group.relationship_type),
            sectionEntry('Tension Charge', props.group.current_tension_charge),
            sectionEntry('Active', props.group.is_active),
            sectionEntry('Visibility', props.group.visibility),
            sectionEntry('Content Classification', props.group.content_classification),
        ],
    },
    {
        title: 'Active Members',
        entries: [
            sectionEntry(
                'Members',
                (props.group.active_members ?? []).map((entity) => ({
                    label: `${entity.name} (${entity.entity_type})`,
                    href: route('entities.show', entity.id),
                })),
                { kind: 'list' },
            ),
        ],
    },
    {
        title: 'Member Entries',
        entries: [
            sectionEntry(
                'Entries',
                (props.group.member_entries ?? []).map((entry) => ({
                    label: `${entry.entity?.name ?? 'Unknown'}${entry.role_in_group ? ` · ${entry.role_in_group}` : ''}${entry.joined_era ? ` · ${entry.joined_era}` : ''}`,
                    href: entry.entity?.id ? route('entities.show', entry.entity.id) : null,
                })),
                { kind: 'list' },
            ),
        ],
    },
])
</script>
