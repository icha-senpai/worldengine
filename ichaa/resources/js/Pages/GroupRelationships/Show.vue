<template>
    <div>
        <ScaffoldShowPage
            :title="group.name"
            back-label="Group Relationships"
            :back-href="route('group-relationships.index')"
            :edit-href="route('group-relationships.edit', group.id)"
            :edit-preserve-scroll="true"
            :edit-preserve-state="true"
            :edit-drawer-open="Boolean(editDrawer)"
            :edit-close-href="route('group-relationships.show', group.id)"
            :destroy-href="route('group-relationships.destroy', group.id)"
            :badge="group.relationship_type || 'group'"
            :sections="sections"
        >
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <section class="panel">
                    <h3 class="panel-label">Add Member</h3>
                    <p class="text-muted-3 text-sm font-ui">
                        Attach a new participant directly from the relationship page.
                    </p>

                    <form class="mt-4 space-y-3" @submit.prevent="addMember">
                        <div class="field-group">
                            <label class="field-label" for="group-member-entity">Entity</label>
                            <SelectInput id="group-member-entity" v-model="memberForm.entity_id" class="w-full">
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
                                <label class="field-label" for="group-member-role">Role In Group</label>
                                <TextInput id="group-member-role" v-model="memberForm.role_in_group" class="w-full" />
                            </div>
                            <div class="field-group">
                                <label class="field-label" for="group-member-joined">Joined Era</label>
                                <TextInput id="group-member-joined" v-model="memberForm.joined_era" class="w-full" />
                            </div>
                        </div>
                        <AppButton type="submit" variant="primary" :disabled="memberForm.processing || !memberForm.entity_id">
                            Add Member
                        </AppButton>
                    </form>
                </section>

                <section class="panel">
                    <h3 class="panel-label">Manage Entries</h3>
                    <div v-if="group.member_entries?.length" class="space-y-3">
                        <div
                            v-for="entry in group.member_entries"
                            :key="entry.id"
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
                                        <span v-if="entry.role_in_group" class="accent-tag">{{ entry.role_in_group }}</span>
                                        <span class="accent-tag" :class="entry.is_active_member ? '' : 'opacity-70'">
                                            {{ entry.is_active_member ? 'active' : 'departed' }}
                                        </span>
                                    </div>

                                    <p v-if="entry.joined_era || entry.left_era" class="text-muted-3 text-sm">
                                        {{ entry.joined_era || 'Unknown join' }}<span v-if="entry.left_era"> → {{ entry.left_era }}</span>
                                    </p>
                                </div>

                                <AppButton
                                    v-if="entry.is_active_member"
                                    type="button"
                                    variant="danger"
                                    size="sm"
                                    @click="removeMember(entry.id)"
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
            <EditGroupRelationship
                        v-if="editDrawer"
                        embedded
                        :group="group"
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
import EditGroupRelationship from '@/Pages/GroupRelationships/Edit.vue'
import TextInput from '@/Components/TextInput.vue'
import { confirmDialog, showErrorDialog } from '@/lib/appDialog'
import { formatLabel, sectionEntry } from '@/Pages/scaffold/pageBuilders'

const props = defineProps({
    group: { type: Object, required: true },
    entities: { type: Array, default: () => [] },
    editDrawer: { type: Object, default: null },
})

const memberForm = useForm({
    entity_id: '',
    role_in_group: '',
    joined_era: '',
})

const currentMemberIds = computed(() =>
    new Set(
        (props.group.member_entries ?? [])
            .filter((entry) => entry.is_active_member)
            .map((entry) => Number(entry.entity_id))
    )
)

const availableEntityOptions = computed(() =>
    props.entities
        .filter((entity) => !currentMemberIds.value.has(Number(entity.id)))
        .map((entity) => ({
            value: entity.id,
            label: `${entity.name} (#${entity.id} · ${formatLabel(entity.entity_type)})`,
        }))
)

const addMember = () => {
    memberForm.post(route('group-relationships.members.add', props.group.id), {
        preserveScroll: true,
        onSuccess: () => memberForm.reset(),
    })
}

const removeMember = async (entryId) => {
    const confirmed = await confirmDialog({
        title: 'Remove Member',
        message: 'Remove this member from the group relationship?',
        confirmLabel: 'Remove Member',
        cancelLabel: 'Cancel',
        confirmVariant: 'danger',
    })

    if (!confirmed) {
        return
    }

    router.delete(route('group-relationships.members.remove', [props.group.id, entryId]), {
        preserveScroll: true,
        onError: (errors) => {
            void showErrorDialog({
                title: 'Could not remove member',
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
            sectionEntry('Relationship Type', props.group.relationship_type),
            sectionEntry('Tension Charge', props.group.current_tension_charge),
            sectionEntry('Active', props.group.is_active),
            sectionEntry('Visibility', props.group.visibility),
            sectionEntry('Content Classification', props.group.content_classification),
        ],
    },
])
</script>
