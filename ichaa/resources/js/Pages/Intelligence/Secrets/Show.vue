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
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <section class="panel space-y-4">
                    <div>
                        <h3 class="panel-label !mb-0">Exposure</h3>
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
                        <h3 class="panel-label !mb-0">Known By</h3>
                        <p class="text-muted-3 text-sm font-ui mt-1">
                            Add entities who now know the truth of this secret.
                        </p>
                    </div>

                    <div class="field-group">
                        <label class="field-label" for="secret-known-by">Entity</label>
                        <div class="flex flex-col gap-3 md:flex-row">
                            <SelectInput id="secret-known-by" v-model="knownByForm.entity_id" class="w-full">
                                <option value="">Select an entity...</option>
                                <option v-for="option in availableKnownByOptions" :key="option.id" :value="String(option.id)">
                                    {{ option.name }} ({{ option.entity_type }})
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
import { router, useForm } from '@inertiajs/vue3'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'
import EditSecret from '@/Pages/Intelligence/Secrets/Edit.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import SelectInput from '@/Components/SelectInput.vue'
import TextInput from '@/Components/TextInput.vue'
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

const knownByForm = useForm({
    entity_id: '',
})

const availableKnownByOptions = computed(() =>
    props.entities.filter((entity) => !(props.secret.known_by_entity_ids ?? []).includes(entity.id))
)

const exposeSecret = () => {
    exposeForm.post(route('secrets.expose', props.secret.id))
}

const addKnownBy = () => {
    router.post(route('secrets.known-by.add', {
        secret: props.secret.id,
        entity: Number(knownByForm.entity_id),
    }), {}, {
        onSuccess: () => knownByForm.reset(),
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
