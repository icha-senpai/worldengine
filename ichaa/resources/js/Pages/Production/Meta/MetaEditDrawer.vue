<template>
    <form @submit.prevent="submit">
        <AppDrawer
            title="Edit Meta Note"
            :trail-items="trailItems"
            @close="closeDrawer"
        >
            <div class="space-y-6">
                <FormErrorSummary :errors="form.errors" />

                <section class="panel">
                    <h3 class="panel-label">Identity</h3>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="field-group md:col-span-2">
                            <label class="field-label">
                                Title
                                <span class="text-danger">*</span>
                            </label>
                            <TextInput
                                v-model="form.title"
                                type="text"
                                class="w-full"
                                :class="{ 'input--error': form.errors.title }"
                                autofocus
                            />
                            <p v-if="form.errors.title" class="field-error">{{ form.errors.title }}</p>
                        </div>

                        <div class="field-group">
                            <label class="field-label">
                                Category
                                <span class="text-danger">*</span>
                            </label>
                            <SelectInput v-model="form.category" class="w-full">
                                <option value="">Select a category...</option>
                                <option v-for="option in categories" :key="option" :value="option">
                                    {{ formatLabel(option) }}
                                </option>
                            </SelectInput>
                            <p v-if="form.errors.category" class="field-error">{{ form.errors.category }}</p>
                        </div>

                        <div class="field-group">
                            <label class="field-label">
                                Note Type
                                <span class="text-danger">*</span>
                            </label>
                            <SelectInput v-model="form.meta_note_type" class="w-full">
                                <option value="">Select a note type...</option>
                                <option v-for="option in noteTypes" :key="option" :value="option">
                                    {{ formatLabel(option) }}
                                </option>
                            </SelectInput>
                            <p v-if="form.errors.meta_note_type" class="field-error">{{ form.errors.meta_note_type }}</p>
                        </div>

                        <div class="field-group">
                            <label class="field-label">Priority</label>
                            <SelectInput v-model="form.priority" class="w-full">
                                <option value="">Select a priority...</option>
                                <option v-for="option in priorities" :key="option" :value="option">
                                    {{ formatLabel(option) }}
                                </option>
                            </SelectInput>
                            <p v-if="form.errors.priority" class="field-error">{{ form.errors.priority }}</p>
                        </div>

                        <div class="field-group">
                            <label class="field-label">Action Status</label>
                            <SelectInput v-model="form.action_status" class="w-full">
                                <option value="">Select a status...</option>
                                <option v-for="option in actionStatuses" :key="option" :value="option">
                                    {{ formatLabel(option) }}
                                </option>
                            </SelectInput>
                            <p v-if="form.errors.action_status" class="field-error">{{ form.errors.action_status }}</p>
                        </div>

                        <div class="field-group">
                            <label class="field-label">Resolved At</label>
                            <TextInput
                                v-model="form.resolved_at"
                                type="text"
                                placeholder="YYYY-MM-DD HH:MM:SS"
                                class="w-full"
                            />
                            <p v-if="form.errors.resolved_at" class="field-error">{{ form.errors.resolved_at }}</p>
                        </div>

                        <div class="field-group">
                            <label class="field-label">Visibility</label>
                            <TextInput
                                v-model="form.visibility"
                                type="text"
                                placeholder="private, author_only, public_knowledge..."
                                class="w-full"
                            />
                            <p v-if="form.errors.visibility" class="field-error">{{ form.errors.visibility }}</p>
                        </div>

                        <div class="field-group">
                            <label class="field-label">Content Classification</label>
                            <TextInput
                                v-model="form.content_classification"
                                type="text"
                                placeholder="restricted, sensitive, open..."
                                class="w-full"
                            />
                            <p v-if="form.errors.content_classification" class="field-error">{{ form.errors.content_classification }}</p>
                        </div>
                    </div>
                </section>

                <section class="panel">
                    <h3 class="panel-label">Content</h3>

                    <div class="space-y-4">
                        <div class="field-group">
                            <label class="field-label">Content</label>
                            <RichTextEditor v-model="form.content" placeholder="Write the core note..." />
                            <p class="field-help">Rich text with the full editor suite.</p>
                            <p v-if="form.errors.content" class="field-error">{{ form.errors.content }}</p>
                        </div>

                        <div class="field-group">
                            <label class="field-label">Resolution Notes</label>
                            <RichTextEditor v-model="form.resolution_notes" placeholder="Add resolution details if this note is settled..." />
                            <p class="field-help">Use this when the note is resolved or needs outcome context.</p>
                            <p v-if="form.errors.resolution_notes" class="field-error">{{ form.errors.resolution_notes }}</p>
                        </div>
                    </div>
                </section>

                <section class="panel">
                    <h3 class="panel-label">Sensory and Emotional</h3>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="field-group">
                            <label class="field-label">Sight</label>
                            <TextInput v-model="form.sense_sight" type="text" class="w-full" />
                            <p v-if="form.errors.sense_sight" class="field-error">{{ form.errors.sense_sight }}</p>
                        </div>

                        <div class="field-group">
                            <label class="field-label">Sound</label>
                            <TextInput v-model="form.sense_sound" type="text" class="w-full" />
                            <p v-if="form.errors.sense_sound" class="field-error">{{ form.errors.sense_sound }}</p>
                        </div>

                        <div class="field-group">
                            <label class="field-label">Smell</label>
                            <TextInput v-model="form.sense_smell" type="text" class="w-full" />
                            <p v-if="form.errors.sense_smell" class="field-error">{{ form.errors.sense_smell }}</p>
                        </div>

                        <div class="field-group">
                            <label class="field-label">Taste</label>
                            <TextInput v-model="form.sense_taste" type="text" class="w-full" />
                            <p v-if="form.errors.sense_taste" class="field-error">{{ form.errors.sense_taste }}</p>
                        </div>

                        <div class="field-group">
                            <label class="field-label">Touch</label>
                            <TextInput v-model="form.sense_touch" type="text" class="w-full" />
                            <p v-if="form.errors.sense_touch" class="field-error">{{ form.errors.sense_touch }}</p>
                        </div>

                        <div class="field-group">
                            <label class="field-label">Magical</label>
                            <TextInput v-model="form.sense_magical" type="text" class="w-full" />
                            <p v-if="form.errors.sense_magical" class="field-error">{{ form.errors.sense_magical }}</p>
                        </div>

                        <div class="field-group md:col-span-2">
                            <label class="field-label">Emotional Register</label>
                            <TextareaInput
                                v-model="form.emotional_register"
                                rows="3"
                                class="input w-full resize-none"
                            />
                            <p v-if="form.errors.emotional_register" class="field-error">{{ form.errors.emotional_register }}</p>
                        </div>
                    </div>
                </section>

                <section class="panel">
                    <h3 class="panel-label">Symbolism</h3>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="field-group">
                            <label class="field-label">Symbol Name</label>
                            <TextInput v-model="form.symbol_name" type="text" class="w-full" />
                            <p v-if="form.errors.symbol_name" class="field-error">{{ form.errors.symbol_name }}</p>
                        </div>

                        <div class="field-group">
                            <label class="field-label">Symbol Scope</label>
                            <SelectInput v-model="form.symbol_scope" class="w-full">
                                <option value="">Select a scope...</option>
                                <option v-for="option in symbolScopes" :key="option" :value="option">
                                    {{ formatLabel(option) }}
                                </option>
                            </SelectInput>
                            <p v-if="form.errors.symbol_scope" class="field-error">{{ form.errors.symbol_scope }}</p>
                        </div>

                        <div class="field-group">
                            <label class="field-label">Symbol Origin Entity</label>
                            <SelectInput v-model="form.symbol_origin_entity_id" class="w-full">
                                <option value="">Optional origin entity...</option>
                                <option v-for="option in entityOptions" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </SelectInput>
                            <p v-if="form.errors.symbol_origin_entity_id" class="field-error">{{ form.errors.symbol_origin_entity_id }}</p>
                        </div>

                        <div class="field-group md:col-span-2">
                            <label class="field-label">Symbol Usage Context</label>
                            <TextareaInput
                                v-model="form.symbol_usage_context"
                                rows="3"
                                class="input w-full resize-none"
                            />
                            <p v-if="form.errors.symbol_usage_context" class="field-error">{{ form.errors.symbol_usage_context }}</p>
                        </div>

                        <div class="field-group md:col-span-2">
                            <label class="field-label">Associated Entities</label>

                            <div v-if="entityOptions.length" class="multiselect-list">
                                <label
                                    v-for="option in entityOptions"
                                    :key="`symbol-entity-${option.value}`"
                                    class="multiselect-option"
                                >
                                    <Checkbox
                                        v-model:checked="form.symbol_associated_entity_ids"
                                        :value="option.value"
                                    />
                                    <span class="multiselect-label">{{ option.label }}</span>
                                </label>
                            </div>

                            <p v-else class="field-help">No entities exist yet to associate with this symbol.</p>
                            <p v-if="form.errors.symbol_associated_entity_ids" class="field-error">{{ form.errors.symbol_associated_entity_ids }}</p>
                        </div>
                    </div>
                </section>
            </div>

            <template #footer>
                <AppButton type="submit" variant="primary" :disabled="form.processing">
                    <span v-if="form.processing">Saving...</span>
                    <span v-else>Save Meta Note</span>
                </AppButton>
                <AppButton type="button" variant="ghost" @click="closeDrawer">Cancel</AppButton>
            </template>
        </AppDrawer>
    </form>
</template>

<script setup>
import { computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import Checkbox from '@/Components/Checkbox.vue'
import FormErrorSummary from '@/Components/ui/FormErrorSummary.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import AppDrawer from '@/Components/ui/AppDrawer.vue'
import SelectInput from '@/Components/SelectInput.vue'
import TextareaInput from '@/Components/TextareaInput.vue'
import TextInput from '@/Components/TextInput.vue'
import RichTextEditor from '@/Components/scaffold/RichTextEditor.vue'
import { formatLabel, toEntityOptions } from '@/Components/scaffold/formatters'

const props = defineProps({
    note: { type: Object, required: true },
    closeHref: { type: String, required: true },
    entities: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    noteTypes: { type: Array, default: () => [] },
    priorities: { type: Array, default: () => [] },
    actionStatuses: { type: Array, default: () => [] },
    symbolScopes: { type: Array, default: () => [] },
})

const entityOptions = computed(() => toEntityOptions(props.entities))

const trailItems = computed(() => [
    { label: 'Meta', href: route('meta.index') },
    { label: props.note.title, href: props.closeHref },
    { label: 'Edit' },
])

const form = useForm({
    title: props.note.title ?? '',
    category: props.note.category ?? '',
    meta_note_type: props.note.meta_note_type ?? '',
    content: props.note.content ?? null,
    priority: props.note.priority ?? '',
    action_status: props.note.action_status ?? '',
    resolution_notes: props.note.resolution_notes ?? null,
    resolved_at: props.note.resolved_at ?? '',
    sense_sight: props.note.sense_sight ?? '',
    sense_sound: props.note.sense_sound ?? '',
    sense_smell: props.note.sense_smell ?? '',
    sense_taste: props.note.sense_taste ?? '',
    sense_touch: props.note.sense_touch ?? '',
    sense_magical: props.note.sense_magical ?? '',
    emotional_register: props.note.emotional_register ?? '',
    symbol_name: props.note.symbol_name ?? '',
    symbol_origin_entity_id: props.note.symbol_origin_entity_id ?? '',
    symbol_usage_context: props.note.symbol_usage_context ?? '',
    symbol_associated_entity_ids: Array.isArray(props.note.symbol_associated_entity_ids)
        ? [...props.note.symbol_associated_entity_ids]
        : [],
    symbol_scope: props.note.symbol_scope ?? '',
    visibility: props.note.visibility ?? 'private',
    content_classification: props.note.content_classification ?? 'restricted',
})

const submit = () => {
    form.put(route('meta.update', props.note.id), {
        preserveScroll: true,
    })
}

const closeDrawer = () => {
    router.visit(props.closeHref, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    })
}
</script>
