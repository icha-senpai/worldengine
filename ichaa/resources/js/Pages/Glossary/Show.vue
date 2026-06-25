<template>
    <div>
        <ScaffoldShowPage
            :title="term.term"
            :subtitle="term.usage_context"
            back-label="Glossary"
            :back-href="route('glossary.index')"
            :edit-href="route('glossary.edit', term.id)"
            :edit-preserve-scroll="true"
            :edit-preserve-state="true"
            :edit-drawer-open="Boolean(editDrawer)"
            :edit-close-href="route('glossary.show', term.id)"
            :destroy-href="route('glossary.destroy', term.id)"
            badge="term"
            :sections="sections"
        >
            <template #edit-drawer>
                <EditGlossaryTerm
                    v-if="editDrawer"
                    embedded
                    :term="term"
                    v-bind="editDrawer"
                />
            </template>
        </ScaffoldShowPage>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'
import EditGlossaryTerm from '@/Pages/Glossary/Edit.vue'
import { sectionEntry } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    term: { type: Object, required: true },
    editDrawer: { type: Object, default: null },
})

const sections = computed(() => [
    {
        title: 'Overview',
        entries: [
            sectionEntry('Usage Context', props.term.usage_context),
            sectionEntry('Origin Universe', props.term.origin_universe),
            sectionEntry('Era Introduced', props.term.era_introduced),
            sectionEntry('Term Status', props.term.term_status),
        ],
    },
    {
        title: 'Definition',
        entries: [
            sectionEntry('Definition', props.term.definition, { kind: 'json' }),
        ],
        fullWidth: true,
    },
])
</script>
