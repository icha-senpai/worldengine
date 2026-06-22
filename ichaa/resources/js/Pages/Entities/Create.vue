<template>
    <AuthenticatedLayout>

        <template #header>
            <PageHeaderTrail :items="headerItems" />
        </template>

        <form @submit.prevent="submit" class="max-w-2xl space-y-5">

            <FormErrorSummary :errors="form.errors" />

            <!-- NAME -->
            <div class="field-group">
                <label class="field-label">Name <span class="text-danger">*</span></label>
                <TextInput
                    v-model="form.name"
                    type="text"
                    placeholder="Entity name"
                    class="w-full"
                    :class="{ 'input--error': form.errors.name }"
                    autofocus
                />
                <p v-if="form.errors.name" class="field-error">{{ form.errors.name }}</p>
            </div>

            <!-- ENTITY TYPE -->
            <div class="field-group">
                <label class="field-label">Type <span class="text-danger">*</span></label>
                <div class="grid grid-cols-3 gap-1.5">
                    <template v-for="(types, category) in entityTypes" :key="category">
                        <div class="col-span-3 mt-2 first:mt-0">
                            <span class="category-label">{{ formatLabel(category) }}</span>
                        </div>
                        <AppButton
                            v-for="t in types"
                            :key="t"
                            type="button"
                            @click="form.entity_type = t"
                            variant="select-solid"
                            :selected="form.entity_type === t"
                            block
                        >
                            {{ formatLabel(t) }}
                        </AppButton>
                    </template>
                </div>
                <p v-if="form.errors.entity_type" class="field-error">{{ form.errors.entity_type }}</p>
            </div>

            <!-- Only show remaining fields once type is selected -->
            <template v-if="form.entity_type">

                <!-- ORIGIN -->
                <div class="panel">
                    <h3 class="panel-label">Origin</h3>
                    <div class="space-y-3">

                        <div class="field-group">
                            <label class="field-label">Origin Type</label>
                            <div class="flex gap-2 flex-wrap">
                                <AppButton
                                    v-for="o in originTypes"
                                    :key="o.value"
                                    type="button"
                                    @click="form.origin_type = o.value"
                                    variant="select"
                                    :selected="form.origin_type === o.value"
                                >
                                    {{ o.label }}
                                </AppButton>
                            </div>
                        </div>

                        <div class="field-group">
                            <label class="field-label">Source Universes</label>
                            <div class="flex flex-wrap gap-1.5 mb-2">
                                <button
                                    v-for="u in selectedUniverses"
                                    :key="u"
                                    type="button"
                                    @click="removeUniverse(u)"
                                    class="universe-tag"
                                >
                                    {{ u }} ×
                                </button>
                            </div>
                            <SelectInput @change="addUniverse($event)" class="w-full">
                                <option value="">Add a universe...</option>
                                <option
                                    v-for="u in availableUniverses"
                                    :key="u"
                                    :value="u"
                                >{{ u }}</option>
                            </SelectInput>
                        </div>

                        <div v-if="form.origin_type === 'canonical'" class="field-group">
                            <label class="field-label">Canon Deviation</label>
                            <SelectInput v-model="form.canon_deviation" class="w-full">
                                <option value="">None — fully canonical</option>
                                <option value="minor">Minor — small divergence</option>
                                <option value="moderate">Moderate — significant changes</option>
                                <option value="major">Major — heavily AU</option>
                                <option value="concept_only">Concept only — inspired by</option>
                            </SelectInput>
                        </div>

                    </div>
                </div>

                <!-- ACCESS -->
                <div class="panel">
                    <h3 class="panel-label">Access</h3>
                    <div class="grid grid-cols-2 gap-3">

                        <div class="field-group">
                            <label class="field-label">Visibility</label>
                            <div class="flex gap-2 flex-wrap">
                                <AppButton
                                    v-for="v in visibilityOptions"
                                    :key="v.value"
                                    type="button"
                                    @click="form.visibility = v.value"
                                    variant="select"
                                    :selected="form.visibility === v.value"
                                >
                                    {{ v.label }}
                                </AppButton>
                            </div>
                        </div>

                        <div class="field-group">
                            <label class="field-label">Content Classification</label>
                            <div class="flex gap-2 flex-wrap">
                                <AppButton
                                    v-for="c in classificationOptions"
                                    :key="c.value"
                                    type="button"
                                    @click="form.content_classification = c.value"
                                    variant="select"
                                    :selected="form.content_classification === c.value"
                                >
                                    {{ c.label }}
                                </AppButton>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- SUMMARY (optional at creation) -->
                <div class="field-group">
                    <label class="field-label">Summary <span class="text-muted-3 font-normal normal-case">(optional)</span></label>
                    <TextareaInput
                        v-model="form.summary"
                        rows="4"
                        placeholder="Brief description of this entity..."
                        class="input w-full resize-none"
                    />
                </div>

                <!-- SUBMIT -->
                <div class="flex items-center gap-3 pt-2">
                    <AppButton
                        type="submit"
                        variant="primary"
                        :disabled="form.processing || !form.name || !form.entity_type"
                    >
                        <span v-if="form.processing">Creating...</span>
                        <span v-else>Create Entity</span>
                    </AppButton>
                    <AppButton :href="route('entities.index')" variant="ghost">
                        Cancel
                    </AppButton>
                    <span v-if="form.processing" class="text-muted-3 text-sm font-ui">Working...</span>
                </div>

            </template>

            <!-- Prompt to select type first -->
            <div v-else class="empty-state-panel">
                <p class="text-muted-3 text-sm font-ui uppercase tracking-widest">Select a type to continue</p>
            </div>

        </form>

    </AuthenticatedLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import SelectInput from '@/Components/SelectInput.vue'
import TextareaInput from '@/Components/TextareaInput.vue'
import TextInput from '@/Components/TextInput.vue'
import FormErrorSummary from '@/Components/ui/FormErrorSummary.vue'
import PageHeaderTrail from '@/Components/ui/PageHeaderTrail.vue'

const props = defineProps({
    entityTypes: { type: Object, default: () => ({}) },
})

const form = useForm({
    name:                   '',
    entity_type:            '',
    origin_type:            'native',
    source_universes:       [],
    canon_deviation:        '',
    visibility:             'private',
    content_classification: 'restricted',
    summary:                '',
})

const headerItems = [
    { label: 'Entities', href: route('entities.index') },
    { label: 'New Entity' },
]

const submit = () => {
    form.post(route('entities.store'))
}

// --- Universe helpers ---

const ALL_UNIVERSES = [
    'Harry Potter', 'Cosmere', 'Warhammer 40K', 'Dune', 'Wheel of Time',
    'Lord of the Rings', 'Star Wars', 'Marvel', 'DC', 'Witcher',
    'Elder Scrolls', 'Final Fantasy', 'Mass Effect', 'Dragon Age',
    'Mistborn', 'Stormlight Archive', 'First Law', 'Malazan',
    'Kingkiller Chronicle', 'Night Circus', 'Original',
]

const selectedUniverses = computed(() => form.source_universes)

const availableUniverses = computed(() =>
    ALL_UNIVERSES.filter(u => !form.source_universes.includes(u))
)

const addUniverse = (event) => {
    const val = event.target.value
    if (val && !form.source_universes.includes(val)) {
        form.source_universes = [...form.source_universes, val]
    }
    event.target.value = ''
}

const removeUniverse = (u) => {
    form.source_universes = form.source_universes.filter(x => x !== u)
}

// --- Static options ---

const originTypes = [
    { value: 'native',    label: 'Native'    },
    { value: 'canonical', label: 'Canonical' },
    { value: 'alternate', label: 'Alternate' },
    { value: 'original',  label: 'Original'  },
]

const visibilityOptions = [
    { value: 'private',          label: 'Private'   },
    { value: 'author_only',      label: 'Author'    },
    { value: 'public_knowledge', label: 'Public'    },
]

const classificationOptions = [
    { value: 'restricted', label: 'Restricted' },
    { value: 'sensitive',  label: 'Sensitive'  },
    { value: 'open',       label: 'Open'       },
]

// --- Formatters ---

const formatLabel = (str) => str
    ? str.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
    : '—'
</script>
