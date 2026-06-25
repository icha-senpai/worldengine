<template>
    <div>
        <ScaffoldIndexPage
            :title="`${entity.name} Versions`"
            :count="countRecords(versions)"
            count-label="versions"
            :items="items"
            empty-title="No versions recorded"
        >
            <template #toolbar>
                <div class="space-y-4">
                    <div class="panel space-y-4">
                        <div class="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
                            <div class="space-y-1">
                                <h3 class="panel-label mb-0!">Canon Thread</h3>
                                <p class="text-muted-3 text-sm font-ui">
                                    Track the live canon chain, source-canon anchor, and every snapshot that reshaped this entity.
                                </p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <span class="chip">Total: {{ summary.counts.total }}</span>
                                <span class="chip">Automatic: {{ summary.counts.automatic }}</span>
                                <span class="chip">Deprecated: {{ summary.counts.deprecated }}</span>
                            </div>
                        </div>

                        <div class="grid gap-3 lg:grid-cols-2">
                            <div class="rounded-md border border-border bg-surface-2 p-4">
                                <span class="field-label">Current Canon</span>
                                <p class="mt-2 text-primary text-sm">
                                    {{ summary.current?.version_label || `Version ${summary.current?.version_number ?? entity.current_version_number}` }}
                                </p>
                                <p class="mt-1 text-muted-3 text-sm font-ui">
                                    {{ summary.current?.valid_from_era || 'No era set yet' }}
                                </p>
                            </div>
                            <div class="rounded-md border border-border bg-surface-2 p-4">
                                <span class="field-label">Version Zero</span>
                                <p class="mt-2 text-primary text-sm">
                                    {{ summary.versionZero?.version_label || 'Not captured yet' }}
                                </p>
                                <p class="mt-1 text-muted-3 text-sm font-ui">
                                    {{ summary.versionZero?.version_zero_confidence ? `Confidence: ${formatLabel(summary.versionZero.version_zero_confidence)}` : 'No source-canon capture recorded yet.' }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <CreateVersion
                        embedded
                        :entity="entity"
                        :visibility-levels="visibilityLevels"
                        :content-classifications="contentClassifications"
                        :version-zero-confidence-levels="versionZeroConfidenceLevels"
                    />

                    <ScaffoldFilterBar
                        :fields="filterFields"
                        :form="filterForm"
                        :has-active-filters="hasActiveFilters"
                        :on-apply="applyFilters"
                        :on-clear="clearFilters"
                    />
                </div>
            </template>
        </ScaffoldIndexPage>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import ScaffoldFilterBar from '@/Components/scaffold/ScaffoldFilterBar.vue'
import ScaffoldIndexPage from '@/Components/scaffold/ScaffoldIndexPage.vue'
import CreateVersion from '@/Pages/Entities/Versions/Create.vue'
import { countRecords, badge, buildMeta, formatLabel } from '@/Pages/scaffold/pageBuilders'
import { summarizeValue } from '@/Components/scaffold/formatters'
import { useIndexFilters } from '@/Pages/scaffold/indexFilters'

const props = defineProps({
    entity: { type: Object, required: true },
    versions: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    summary: { type: Object, required: true },
    versionStates: { type: Array, default: () => [] },
    versionTypes: { type: Array, default: () => [] },
    triggerTypes: { type: Array, default: () => [] },
    currentOptions: { type: Array, default: () => [] },
    versionZeroOptions: { type: Array, default: () => [] },
    visibilityLevels: { type: Array, default: () => [] },
    contentClassifications: { type: Array, default: () => [] },
    versionZeroConfidenceLevels: { type: Array, default: () => [] },
})

const { filterForm, hasActiveFilters, applyFilters, clearFilters } = useIndexFilters('entities.versions.index', {
    state: props.filters.state ?? '',
    trigger: props.filters.trigger ?? '',
    type: props.filters.type ?? '',
    current: props.filters.current ?? '',
    version_zero: props.filters.version_zero ?? '',
}, {
    routeParams: props.entity.id,
})

const filterFields = computed(() => [
    { key: 'state', type: 'select', placeholder: 'All states', options: props.versionStates },
    { key: 'trigger', type: 'select', placeholder: 'All triggers', options: props.triggerTypes },
    { key: 'type', type: 'select', placeholder: 'All version types', options: props.versionTypes },
    { key: 'current', type: 'select', placeholder: 'All current states', options: props.currentOptions },
    { key: 'version_zero', type: 'select', placeholder: 'All version zero states', options: props.versionZeroOptions },
])

const items = computed(() =>
    props.versions.map((version) => ({
        id: version.id,
        href: route('entities.versions.show', [props.entity.id, version.id]),
        title: version.version_label || `Version ${version.version_number ?? version.id}`,
        subtitle: versionSummary(version),
        badges: [
            badge('Type', version.version_type ?? 'unknown'),
            badge('State', version.version_state ?? 'unknown'),
            ...(version.is_version_zero ? [badge('Confidence', version.version_zero_confidence ?? 'rough')] : []),
        ],
        meta: buildMeta([
            { label: 'Version #', value: version.version_number },
            { label: 'Trigger', value: version.trigger_type },
            { label: 'Valid From', value: version.valid_from_era },
            { label: 'Visibility', value: version.visibility },
            { label: 'Classification', value: version.content_classification },
            { label: 'Current', value: version.is_current },
        ]),
    }))
)

const versionSummary = (version) => {
    if (version.is_version_zero) {
        return version.version_zero_notes || 'Source canon anchor for this entity.'
    }

    if (version.source_entity?.name) {
        return `${version.source_entity.name} iteration thread`
    }

    if (version.what_changed) {
        return summarizeValue(version.what_changed)
    }

    return 'Recorded canon snapshot.'
}
</script>
