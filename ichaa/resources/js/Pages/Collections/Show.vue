<template>
    <div>
        <ScaffoldShowPage
            :title="collection.name"
            back-label="Collections"
            :back-href="route('collections.index')"
            :edit-href="route('collections.edit', collection.id)"
            :edit-preserve-scroll="true"
            :edit-preserve-state="true"
            :edit-drawer-open="Boolean(editDrawer)"
            :edit-close-href="route('collections.show', collection.id)"
            :destroy-href="route('collections.destroy', collection.id)"
            :badge="formatLabel(collection.collection_type)"
            :subtitle="collection.rules ? 'Rules and members are attached below.' : ''"
            :sections="sections"
        >
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <section class="panel">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h3 class="panel-label !mb-0">Manage Members</h3>
                            <p class="text-muted-3 text-sm font-ui mt-1">
                                Add entities directly to this collection and remove them without leaving the page.
                            </p>
                        </div>
                        <AppButton
                            v-if="supportsSync"
                            type="button"
                            variant="sync"
                            :disabled="syncForm.processing"
                            @click="syncMembers"
                        >
                            Sync Members
                        </AppButton>
                    </div>

                    <form class="mt-4 space-y-3" @submit.prevent="addMember">
                        <div class="field-group">
                            <label class="field-label" for="collection-member-entity">Entity</label>
                            <SelectInput id="collection-member-entity" v-model="memberForm.entity_id" class="w-full">
                                <option value="">Select an entity...</option>
                                <option
                                    v-for="option in availableEntityOptions"
                                    :key="option.value"
                                    :value="String(option.value)"
                                >
                                    {{ option.label }}
                                </option>
                            </SelectInput>
                        </div>
                        <div class="form-grid-2-tight">
                            <div class="field-group">
                                <label class="field-label" for="collection-member-role">Role In Collection</label>
                                <TextInput id="collection-member-role" v-model="memberForm.role_in_collection" class="w-full" />
                            </div>
                            <div class="field-group">
                                <label class="field-label" for="collection-member-sort">Sort Order</label>
                                <TextInput id="collection-member-sort" v-model.number="memberForm.sort_order" type="number" class="w-full" />
                            </div>
                        </div>
                        <AppButton type="submit" variant="primary" :disabled="memberForm.processing || !memberForm.entity_id">
                            Add Member
                        </AppButton>
                    </form>
                </section>

                <section class="panel">
                    <h3 class="panel-label">Member Entries</h3>
                    <div v-if="sortedEntries.length" class="space-y-3">
                        <div
                            v-for="entry in sortedEntries"
                            :key="`${entry.collection_id}-${entry.entity_id}`"
                            class="record-card"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0 space-y-1">
                                    <Link
                                        v-if="entry.entity"
                                        :href="route('entities.show', entry.entity.id)"
                                        class="text-primary text-sm hover:text-cyan transition-colors"
                                    >
                                        {{ entry.entity.name }}
                                    </Link>
                                    <span v-else class="text-primary text-sm">Unknown entity</span>
                                    <div class="flex flex-wrap gap-1.5">
                                        <span v-if="entry.entity?.entity_type" class="alias-type-chip">
                                            {{ formatLabel(entry.entity.entity_type) }}
                                        </span>
                                        <span v-if="entry.role_in_collection" class="accent-tag">
                                            {{ entry.role_in_collection }}
                                        </span>
                                        <span v-if="entry.added_manually" class="accent-tag">manual</span>
                                        <span v-if="entry.added_by_rule" class="accent-tag">rule</span>
                                    </div>
                                </div>

                                <AppButton
                                    type="button"
                                    variant="danger"
                                    size="sm"
                                    @click="removeMember(entry.entity_id)"
                                >
                                    Remove
                                </AppButton>
                            </div>
                        </div>
                    </div>
                    <p v-else class="text-muted-3 text-sm font-ui">No member entries yet.</p>
                </section>
            </div>
                <template #edit-drawer>
            <EditCollection
                        v-if="editDrawer"
                        embedded
                        :collection="collection"
                        v-bind="editDrawer"
                    />
        </template>
    </ScaffoldShowPage>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppButton from '@/Components/ui/AppButton.vue'
import SelectInput from '@/Components/SelectInput.vue'
import ScaffoldShowPage from '@/Components/scaffold/ScaffoldShowPage.vue'
import EditCollection from '@/Pages/Collections/Edit.vue'
import TextInput from '@/Components/TextInput.vue'
import { confirmDialog, showErrorDialog } from '@/lib/appDialog'
import { formatLabel, sectionEntry } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    collection: { type: Object, required: true },
    entities: { type: Array, default: () => [] },
    editDrawer: { type: Object, default: null },
})

const memberForm = useForm({
    entity_id: '',
    role_in_collection: '',
    sort_order: '',
})

const syncForm = useForm({})

const supportsSync = computed(() =>
    ['smart', 'hybrid'].includes(props.collection.collection_mode)
)

const sortedEntries = computed(() =>
    [...(props.collection.entity_entries ?? [])].sort((left, right) => {
        const leftSort = left.sort_order ?? Number.MAX_SAFE_INTEGER
        const rightSort = right.sort_order ?? Number.MAX_SAFE_INTEGER

        if (leftSort !== rightSort) {
            return leftSort - rightSort
        }

        return (left.entity?.name ?? '').localeCompare(right.entity?.name ?? '')
    })
)

const memberIds = computed(() =>
    new Set(sortedEntries.value.map((entry) => Number(entry.entity_id)))
)

const availableEntityOptions = computed(() =>
    props.entities
        .filter((entity) => !memberIds.value.has(Number(entity.id)))
        .map((entity) => ({
            value: entity.id,
            label: `${entity.name} (#${entity.id} · ${formatLabel(entity.entity_type)})`,
        }))
)

const addMember = () => {
    memberForm.post(route('collections.entities.add', [props.collection.id, memberForm.entity_id]), {
        preserveScroll: true,
        onSuccess: () => {
            memberForm.reset()
        },
    })
}

const removeMember = async (entityId) => {
    const confirmed = await confirmDialog({
        title: 'Remove Member',
        message: 'Remove this entity from the collection?',
        confirmLabel: 'Remove Member',
        cancelLabel: 'Cancel',
        confirmVariant: 'danger',
    })

    if (!confirmed) {
        return
    }

    router.delete(route('collections.entities.remove', [props.collection.id, entityId]), {
        preserveScroll: true,
        onError: (errors) => {
            void showErrorDialog({
                title: 'Could not remove collection member',
                message: 'The request did not complete.',
                details: errors,
            })
        },
    })
}

const syncMembers = () => {
    syncForm.post(route('collections.sync', props.collection.id), {
        preserveScroll: true,
    })
}

const sections = computed(() => [
    {
        title: 'Overview',
        entries: [
            sectionEntry('Type', formatLabel(props.collection.collection_type)),
            sectionEntry('Mode', formatLabel(props.collection.collection_mode)),
            sectionEntry('State', formatLabel(props.collection.completion_state)),
            sectionEntry('Visibility', formatLabel(props.collection.visibility)),
            sectionEntry('Classification', formatLabel(props.collection.content_classification)),
        ],
    },
    {
        title: 'Rules',
        entries: [
            sectionEntry('Rules', props.collection.rules, { kind: 'json' }),
        ],
        fullWidth: true,
    },
    {
        title: 'Children',
        entries: [
            sectionEntry(
                'Child Collections',
                (props.collection.child_collections ?? []).map((child) => ({
                    label: `${child.name} (${formatLabel(child.collection_type)})`,
                    href: route('collections.show', child.id),
                })),
                { kind: 'list' },
            ),
        ],
    },
])
</script>
