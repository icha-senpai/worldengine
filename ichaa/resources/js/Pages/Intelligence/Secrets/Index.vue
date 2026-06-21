<template>
    <ScaffoldIndexPage
        title="Secrets"
        :count="countRecords(secrets)"
        count-label="secrets"
        :create-href="route('secrets.create')"
        create-label="New Secret"
        :items="items"
        empty-title="No secrets found"
        :empty-cta-href="route('secrets.create')"
        empty-cta-label="Create the first secret ->"
    />
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldIndexPage from '@/Components/scaffold/ScaffoldIndexPage.vue'
import { asArray, badge, buildMeta, countRecords } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    secrets: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
})

const items = computed(() =>
    asArray(props.secrets).map((secret) => ({
        id: secret.id,
        href: route('secrets.show', secret.id),
        title: secret.title,
        badges: [badge('Type', secret.secret_type)],
        meta: buildMeta([
            { label: 'Risk', value: secret.exposure_risk },
            { label: 'Status', value: secret.status },
        ]),
    }))
)
</script>
