<template>
    <ScaffoldIndexPage
        :title="`${entity.name} Versions`"
        :count="versions.length"
        count-label="versions"
        :items="items"
        empty-title="No versions recorded"
    />
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldIndexPage from '@/Components/scaffold/ScaffoldIndexPage.vue'
import { badge, buildMeta } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    entity: { type: Object, required: true },
    versions: { type: Array, default: () => [] },
})

const items = computed(() =>
    props.versions.map((version) => ({
        id: version.id,
        href: route('entities.versions.show', [props.entity.id, version.id]),
        title: version.version_label || `Version ${version.version_number ?? version.id}`,
        badges: [
            badge('Type', version.version_type ?? 'unknown'),
            badge('State', version.version_state ?? 'unknown'),
        ],
        meta: buildMeta([
            { label: 'Version #', value: version.version_number },
            { label: 'Valid From', value: version.valid_from_era },
            { label: 'Current', value: version.is_current },
            { label: 'Version Zero', value: version.is_version_zero },
        ]),
    }))
)
</script>
