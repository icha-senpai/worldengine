<template>
    <div class="space-y-4">
        <div class="panel space-y-4">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div class="space-y-1">
                    <h3 class="panel-label mb-0!">Canon Capture</h3>
                    <p class="text-muted-3 text-sm font-ui">
                        Save the current entity state as an active canon snapshot, or capture a separate version zero record for source-canon grounding.
                    </p>
                </div>
                <div class="flex flex-wrap gap-1.5">
                    <AppButton type="button" variant="select" :selected="!form.is_version_zero" @click="applyMode('manual')">
                        Current Snapshot
                    </AppButton>
                    <AppButton type="button" variant="select" :selected="form.is_version_zero" @click="applyMode('version_zero')">
                        Version Zero
                    </AppButton>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <span class="chip">Entity: {{ entity.name }}</span>
                <span class="chip">Mode: {{ form.is_version_zero ? 'Source canon capture' : 'Active canon snapshot' }}</span>
                <span class="chip">Default access: {{ formatLabel(form.visibility) }} / {{ formatLabel(form.content_classification) }}</span>
            </div>
        </div>

        <ScaffoldFormPage
            presentation="page"
            :embedded="props.embedded"
            :title="formTitle"
            :back-href="backHref"
            back-label="Versions"
            :cancel-href="backHref"
            :submit-label="submitLabel"
            :processing-label="processingLabel"
            :form="form"
            :sections="sections"
            :on-submit="submit"
        />
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import ScaffoldFormPage from '@/Components/scaffold/ScaffoldFormPage.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import { formatLabel } from '@/Components/scaffold/formatters'

const props = defineProps({
    embedded: { type: Boolean, default: false },
    entity: { type: Object, required: true },
    visibilityLevels: { type: Array, default: () => [] },
    contentClassifications: { type: Array, default: () => [] },
    versionZeroConfidenceLevels: { type: Array, default: () => [] },
    initialMode: { type: String, default: 'manual' },
})

const backHref = computed(() => route('entities.versions.index', props.entity.id))

const form = useForm({
    version_label: '',
    what_changed: null,
    why_changed: null,
    valid_from_era: '',
    version_zero_confidence: 'rough',
    version_zero_notes: '',
    visibility: props.entity.visibility ?? 'private',
    content_classification: props.entity.content_classification ?? 'restricted',
    is_version_zero: props.initialMode === 'version_zero',
})

const formTitle = computed(() => form.is_version_zero ? 'Capture Version Zero' : 'Save Canon Snapshot')
const submitLabel = computed(() => form.is_version_zero ? 'Capture Version Zero' : 'Save Snapshot')
const processingLabel = computed(() => form.is_version_zero ? 'Capturing...' : 'Saving...')

const sections = computed(() => {
    const identityFields = [
        {
            key: 'version_label',
            label: 'Version Label',
            placeholder: form.is_version_zero
                ? `Version Zero - ${props.entity.name}`
                : `Version ${nextVersionNumber.value} - ${props.entity.name}`,
        },
        {
            key: 'valid_from_era',
            label: 'Valid From Era',
            placeholder: form.is_version_zero ? 'Optional canon reference point' : 'When this snapshot becomes canon',
        },
        {
            key: 'visibility',
            label: 'Visibility',
            type: 'select',
            options: props.visibilityLevels,
        },
        {
            key: 'content_classification',
            label: 'Content Classification',
            type: 'select',
            options: props.contentClassifications,
        },
    ]

    if (form.is_version_zero) {
        return [
            {
                title: 'Source Canon Capture',
                fields: [
                    ...identityFields,
                    {
                        key: 'version_zero_confidence',
                        label: 'Confidence',
                        type: 'select',
                        options: props.versionZeroConfidenceLevels,
                    },
                    {
                        key: 'version_zero_notes',
                        label: 'Coverage Notes',
                        type: 'textarea',
                        rows: 4,
                        placeholder: 'What is still rough, missing, or intentionally simplified about this source-canon capture?',
                    },
                ],
            },
        ]
    }

    return [
        {
            title: 'Snapshot Identity',
            fields: identityFields,
        },
        {
            title: 'Narrative Change Log',
            fields: [
                {
                    key: 'what_changed',
                    label: 'What Changed',
                    type: 'json',
                    jsonMode: 'document',
                    placeholder: 'Record the concrete canon shift, new state, or continuity move this snapshot captures.',
                },
                {
                    key: 'why_changed',
                    label: 'Why Changed',
                    type: 'json',
                    jsonMode: 'document',
                    placeholder: 'Explain the in-world cause, authorial reason, or story pressure that justified the change.',
                },
            ],
        },
    ]
})

const nextVersionNumber = computed(() => {
    const current = Number(props.entity.current_version_number ?? 1)

    return Number.isFinite(current) ? current + 1 : 2
})

const applyMode = (mode) => {
    form.is_version_zero = mode === 'version_zero'

    if (form.is_version_zero) {
        form.visibility = 'private'
        form.content_classification = 'author_only'
        form.version_zero_confidence ||= 'rough'
        return
    }

    form.visibility = props.entity.visibility ?? 'private'
    form.content_classification = props.entity.content_classification ?? 'restricted'
}

const submit = () => form.post(route('entities.versions.store', props.entity.id))

applyMode(props.initialMode)
</script>
