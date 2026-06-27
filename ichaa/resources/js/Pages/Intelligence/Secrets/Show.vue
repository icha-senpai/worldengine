<template>
    <div>
        <ScaffoldShowPage
            :title="secret.title"
            back-label="Secrets"
            :back-href="route('secrets.index')"
            :edit-href="route('secrets.edit', secret.id)"
            :edit-preserve-scroll="true"
            :edit-preserve-state="true"
            :edit-drawer-open="Boolean(editDrawer)"
            :edit-close-href="route('secrets.show', secret.id)"
            :destroy-href="route('secrets.destroy', secret.id)"
            :badge="secret.secret_type || 'secret'"
            :sections="sections"
        >
            <div class="mt-4 grid gap-4 xl:grid-cols-3">
                <section class="panel space-y-4">
                    <div>
                        <h3 class="panel-label mb-0!">Exposure</h3>
                        <p class="text-muted-3 text-sm font-ui mt-1">
                            Record when this secret starts leaking or becomes fully exposed.
                        </p>
                    </div>

                    <div class="field-group">
                        <label class="field-label" for="secret-exposed-era">Revealed At Era</label>
                        <TextInput
                            id="secret-exposed-era"
                            v-model="exposeForm.era"
                            type="text"
                            class="w-full"
                            placeholder="e.g. Fifth winter after the fracture"
                        />
                    </div>

                    <div class="field-group">
                        <label class="field-label" for="secret-exposure-level">Exposure Level</label>
                        <SelectInput id="secret-exposure-level" v-model="exposeForm.exposure_level" class="w-full">
                            <option v-for="option in exposureLevels" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </SelectInput>
                    </div>

                    <AppButton
                        type="button"
                        variant="warn"
                        :disabled="exposeForm.processing || !exposeForm.era"
                        @click="exposeSecret"
                    >
                        Record Exposure
                    </AppButton>
                </section>

                <section class="panel space-y-4">
                    <div>
                        <h3 class="panel-label mb-0!">Holders</h3>
                        <p class="text-muted-3 text-sm font-ui mt-1">
                            Track the entities actively holding and concealing this secret.
                        </p>
                    </div>

                    <div class="field-group">
                        <label class="field-label" for="secret-holder">Entity</label>
                        <div class="flex flex-col gap-3 md:flex-row">
                            <SelectInput id="secret-holder" v-model="holderForm.entity_id" class="w-full">
                                <option value="">Select an entity...</option>
                                <option v-for="option in availableHolderOptions" :key="option.id" :value="String(option.id)">
                                    {{ entityOptionLabel(option) }}
                                </option>
                            </SelectInput>
                            <AppButton
                                type="button"
                                variant="primary"
                                :disabled="holderForm.processing || !holderForm.entity_id"
                                @click="addHolder"
                            >
                                Add Holder
                            </AppButton>
                        </div>
                    </div>

                    <div v-if="holderEntities.length" class="space-y-2">
                        <div v-for="entity in holderEntities" :key="`holder-${entity.id ?? entity.label}`" class="record-card">
                            <div class="flex items-center justify-between gap-3">
                                <Link v-if="entity.href" :href="entity.href" class="text-primary text-sm hover:text-cyan transition-colors">
                                    {{ entity.label }}
                                </Link>
                                <span v-else class="text-primary text-sm">{{ entity.label }}</span>
                                <AppButton
                                    v-if="entity.id"
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    @click="removeHolder(entity)"
                                >
                                    Remove
                                </AppButton>
                            </div>
                        </div>
                    </div>
                    <div v-else class="empty-state">No holders recorded yet.</div>
                </section>

                <section class="panel space-y-4">
                    <div>
                        <h3 class="panel-label mb-0!">Known By</h3>
                        <p class="text-muted-3 text-sm font-ui mt-1">
                            Add or remove entities who now know the truth of this secret.
                        </p>
                    </div>

                    <div class="field-group">
                        <label class="field-label" for="secret-known-by">Entity</label>
                        <div class="flex flex-col gap-3 md:flex-row">
                            <SelectInput id="secret-known-by" v-model="knownByForm.entity_id" class="w-full">
                                <option value="">Select an entity...</option>
                                <option v-for="option in availableKnownByOptions" :key="option.id" :value="String(option.id)">
                                    {{ entityOptionLabel(option) }}
                                </option>
                            </SelectInput>
                            <AppButton
                                type="button"
                                variant="primary"
                                :disabled="knownByForm.processing || !knownByForm.entity_id"
                                @click="addKnownBy"
                            >
                                Add Entity
                            </AppButton>
                        </div>
                    </div>

                    <div v-if="knownByEntities.length" class="space-y-2">
                        <div v-for="entity in knownByEntities" :key="`known-${entity.id ?? entity.label}`" class="record-card">
                            <div class="flex items-center justify-between gap-3">
                                <Link v-if="entity.href" :href="entity.href" class="text-primary text-sm hover:text-cyan transition-colors">
                                    {{ entity.label }}
                                </Link>
                                <span v-else class="text-primary text-sm">{{ entity.label }}</span>
                                <AppButton
                                    v-if="entity.id"
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    @click="removeKnownBy(entity)"
                                >
                                    Remove
                                </AppButton>
                            </div>
                        </div>
                    </div>
                    <div v-else class="empty-state">No known-by entities recorded yet.</div>
                </section>
            </div>
            <template #edit-drawer>
                <EditSecret
                    v-if="editDrawer"
                    embedded
                    :secret="secret"
                    v-bind="editDrawer"
                />
            </template>
        </ScaffoldShowPage>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'
import EditSecret from '@/Pages/Intelligence/Secrets/Edit.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import SelectInput from '@/Components/SelectInput.vue'
import TextInput from '@/Components/TextInput.vue'
import { confirmDialog, showErrorDialog } from '@/lib/appDialog'
import { sectionEntry } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    secret: { type: Object, required: true },
    subjectEntities: { type: Array, default: () => [] },
    holderEntities: { type: Array, default: () => [] },
    knownByEntities: { type: Array, default: () => [] },
    entities: { type: Array, default: () => [] },
    editDrawer: { type: Object, default: null },
})

const exposureLevels = [
    { value: 'partially_exposed', label: 'Partially Exposed' },
    { value: 'fully_exposed', label: 'Fully Exposed' },
]

const exposeForm = useForm({
    era: props.secret.revealed_at_era ?? '',
    exposure_level: props.secret.status === 'fully_exposed' ? 'fully_exposed' : 'partially_exposed',
})

const holderForm = useForm({
    entity_id: '',
})

const knownByForm = useForm({
    entity_id: '',
})

const holderIds = computed(() => new Set(props.holderEntities.map((entity) => Number(entity.id)).filter(Boolean)))
const knownByIds = computed(() => new Set(props.knownByEntities.map((entity) => Number(entity.id)).filter(Boolean)))

const availableHolderOptions = computed(() =>
    props.entities.filter((entity) => !holderIds.value.has(Number(entity.id)))
)

const availableKnownByOptions = computed(() =>
    props.entities.filter((entity) => !knownByIds.value.has(Number(entity.id)))
)

const entityOptionLabel = (entity) => `${entity.name} (${entity.entity_type})`

const exposeSecret = () => {
    exposeForm.post(route('secrets.expose', props.secret.id))
}

const addHolder = () => {
    router.post(route('secrets.holders.add', {
        secret: props.secret.id,
        entity: Number(holderForm.entity_id),
    }), {}, {
        preserveScroll: true,
        onSuccess: () => holderForm.reset(),
    })
}

const addKnownBy = () => {
    router.post(route('secrets.known-by.add', {
        secret: props.secret.id,
        entity: Number(knownByForm.entity_id),
    }), {}, {
        preserveScroll: true,
        onSuccess: () => knownByForm.reset(),
    })
}

const removeHolder = async (entity) => {
    const confirmed = await confirmDialog({
        title: 'Remove Holder',
        message: `Remove ${entity.label} from the holder list?`,
        confirmLabel: 'Remove Holder',
        cancelLabel: 'Cancel',
        confirmVariant: 'danger',
    })

    if (!confirmed) {
        return
    }

    router.delete(route('secrets.holders.remove', {
        secret: props.secret.id,
        entity: entity.id,
    }), {
        preserveScroll: true,
        onError: (errors) => {
            void showErrorDialog({
                title: 'Could not remove holder',
                message: 'The request did not complete.',
                details: errors,
            })
        },
    })
}

const removeKnownBy = async (entity) => {
    const confirmed = await confirmDialog({
        title: 'Remove Known-By Entity',
        message: `Remove ${entity.label} from the known-by list?`,
        confirmLabel: 'Remove Entity',
        cancelLabel: 'Cancel',
        confirmVariant: 'danger',
    })

    if (!confirmed) {
        return
    }

    router.delete(route('secrets.known-by.remove', {
        secret: props.secret.id,
        entity: entity.id,
    }), {
        preserveScroll: true,
        onError: (errors) => {
            void showErrorDialog({
                title: 'Could not remove known-by entity',
                message: 'The request did not complete.',
                details: errors,
            })
        },
    })
}

const sections = computed(() => [
    {
        title: 'Overview',
        entries: [
            sectionEntry('Secret Type', props.secret.secret_type),
            sectionEntry('Exposure Risk', props.secret.exposure_risk),
            sectionEntry('Status', props.secret.status),
            sectionEntry('Revealed At Era', props.secret.revealed_at_era),
            sectionEntry('Revelation Trigger', props.secret.revelation_trigger),
        ],
    },
    {
        title: 'Content',
        entries: [
            sectionEntry('Secret Content', props.secret.secret_content, { kind: 'json' }),
            sectionEntry('Subject Entities', props.subjectEntities, { kind: 'list' }),
            sectionEntry('Holder Entities', props.holderEntities, { kind: 'list' }),
            sectionEntry('Known By Entities', props.knownByEntities, { kind: 'list' }),
        ],
        fullWidth: true,
    },
])
</script>
