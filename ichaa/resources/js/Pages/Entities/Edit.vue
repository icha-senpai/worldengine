<template>
    <AuthenticatedLayout>

        <template #header>
            <PageHeaderTrail :items="headerItems" />
        </template>

        <form @submit.prevent="submit" class="max-w-2xl space-y-5">

            <FormErrorSummary :errors="form.errors" />

            <!-- NAME + PUBLIC TITLE -->
            <div class="form-grid-2">
                <div class="field-group">
                    <label class="field-label">Name <span class="text-danger">*</span></label>
                    <TextInput
                        v-model="form.name"
                        type="text"
                        class="w-full"
                        :class="{ 'input--error': form.errors.name }"
                    />
                    <p v-if="form.errors.name" class="field-error">{{ form.errors.name }}</p>
                </div>
                <div class="field-group">
                    <label class="field-label">Public Title <span class="text-muted-3 normal-case font-normal">(alias / in-world name)</span></label>
                    <TextInput
                        v-model="form.public_title"
                        type="text"
                        placeholder="How the world knows them"
                        class="w-full"
                    />
                </div>
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

            <!-- STATUS + TYPE STATUS -->
            <div class="panel">
                <h3 class="panel-label">Status</h3>
                <div class="form-grid-2">

                    <div class="field-group">
                        <label class="field-label">Status</label>
                        <div class="flex flex-wrap gap-1.5">
                            <AppButton
                                v-for="s in statusOptions"
                                :key="s.value"
                                type="button"
                                @click="form.status = s.value"
                                variant="select"
                                :selected="form.status === s.value"
                            >
                                {{ s.label }}
                            </AppButton>
                        </div>
                    </div>

                    <div class="field-group">
                        <label class="field-label">Type Status <span class="text-muted-3 normal-case font-normal">(type-specific state)</span></label>
                        <TextInput
                            v-model="form.type_status"
                            type="text"
                            placeholder="e.g. Horcrux, Shard Vessel, Champion..."
                            class="w-full"
                        />
                    </div>

                </div>
            </div>

            <!-- ORIGIN -->
            <div class="panel">
                <h3 class="panel-label">Origin</h3>
                <div class="space-y-4">

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
                        <div v-if="form.source_universes.length" class="flex flex-wrap gap-1.5 mb-2">
                            <button
                                v-for="u in form.source_universes"
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

                    <div class="field-group">
                        <label class="field-label">Origin Notes</label>
                        <TextareaInput
                            v-model="form.origin_notes"
                            rows="2"
                            placeholder="Context about how this entity came to exist in your world..."
                            class="input w-full resize-none"
                        />
                    </div>

                </div>
            </div>

            <!-- POWER TIERS — only show for powered types -->
            <div v-if="isPoweredType" class="panel">
                <h3 class="panel-label">Power Tiers</h3>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">

                    <div class="field-group">
                        <label class="field-label">Ceiling</label>
                        <SelectInput v-model="form.power_tier_ceiling" class="w-full">
                            <option value="">Not set</option>
                            <option v-for="t in powerTiers" :key="t" :value="t">{{ formatLabel(t) }}</option>
                        </SelectInput>
                    </div>

                    <div class="field-group">
                        <label class="field-label">Operating</label>
                        <SelectInput v-model="form.power_tier_operating" class="w-full">
                            <option value="">Not set</option>
                            <option v-for="t in powerTiers" :key="t" :value="t">{{ formatLabel(t) }}</option>
                        </SelectInput>
                    </div>

                    <div class="field-group">
                        <label class="field-label">Influence</label>
                        <SelectInput v-model="form.power_tier_influence" class="w-full">
                            <option value="">Not set</option>
                            <option v-for="t in powerTiers" :key="t" :value="t">{{ formatLabel(t) }}</option>
                        </SelectInput>
                    </div>

                </div>
            </div>

            <!-- PERCEPTION -->
            <div class="panel">
                <h3 class="panel-label">Perception</h3>
                <div class="field-group">
                    <label class="field-label">Persona Divergence <span class="text-muted-3 normal-case font-normal">(how public image differs from truth)</span></label>
                    <SelectInput v-model="form.persona_divergence" class="w-full">
                        <option value="">None — public = private</option>
                        <option value="minor">Minor — small gaps</option>
                        <option value="moderate">Moderate — meaningful divergence</option>
                        <option value="major">Major — fundamentally different</option>
                        <option value="total">Total — complete mask</option>
                    </SelectInput>
                </div>
            </div>

            <!-- SUMMARIES -->
            <div class="panel">
                <h3 class="panel-label">Summaries</h3>
                <div class="space-y-4">

                    <div class="field-group">
                        <label class="field-label">Private Summary <span class="text-muted-3 normal-case font-normal">(author view)</span></label>
                        <TextareaInput
                            v-model="form.summary"
                            rows="4"
                            placeholder="Full author-level description..."
                            class="input w-full resize-none"
                        />
                    </div>

                    <div class="field-group">
                        <label class="field-label">Public Summary <span class="text-muted-3 normal-case font-normal">(what the world sees)</span></label>
                        <TextareaInput
                            v-model="form.public_summary"
                            rows="3"
                            placeholder="How this entity presents to others..."
                            class="input w-full resize-none"
                        />
                    </div>

                </div>
            </div>

            <!-- ACCESS -->
            <div class="panel">
                <h3 class="panel-label">Access</h3>
                <div class="grid grid-cols-2 gap-4">

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

            <!-- SUBMIT -->
            <div class="flex items-center gap-3 pt-2">
                <AppButton
                    type="submit"
                    variant="primary"
                    :disabled="form.processing || !form.name || !form.entity_type"
                >
                    <span v-if="form.processing">Saving...</span>
                    <span v-else>Save Changes</span>
                </AppButton>
                <AppButton :href="route('entities.show', entity.id)" variant="ghost">
                    Cancel
                </AppButton>
                <span v-if="form.isDirty" class="text-muted-3 text-sm font-ui">Unsaved changes</span>
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
    entity:      { type: Object, required: true },
    entityTypes: { type: Object, default: () => ({}) },
})

const form = useForm({
    name:                   props.entity.name                   ?? '',
    public_title:           props.entity.public_title           ?? '',
    entity_type:            props.entity.entity_type            ?? '',
    entity_sub_type:        props.entity.entity_sub_type        ?? '',
    status:                 props.entity.status                 ?? 'concept',
    type_status:            props.entity.type_status            ?? '',
    summary:                props.entity.summary                ?? '',
    public_summary:         props.entity.public_summary         ?? '',
    source_universes:       props.entity.source_universes       ?? [],
    origin_type:            props.entity.origin_type            ?? 'native',
    canon_deviation:        props.entity.canon_deviation        ?? '',
    origin_notes:           props.entity.origin_notes           ?? '',
    power_tier_ceiling:     props.entity.power_tier_ceiling     ?? '',
    power_tier_operating:   props.entity.power_tier_operating   ?? '',
    power_tier_influence:   props.entity.power_tier_influence   ?? '',
    persona_divergence:     props.entity.persona_divergence     ?? '',
    control_state:          props.entity.control_state          ?? '',
    visibility:             props.entity.visibility             ?? 'private',
    content_classification: props.entity.content_classification ?? 'restricted',
})

const headerItems = computed(() => [
    { label: 'Entities', href: route('entities.index') },
    { label: props.entity.name, href: route('entities.show', props.entity.id), truncate: true },
    { label: 'Edit' },
])

const submit = () => {
    form.put(route('entities.update', props.entity.id))
}

// --- Power tier awareness ---

const POWERED_TYPES = [
    'character', 'historical_figure', 'constructed_intelligence',
    'deity', 'cosmic_entity', 'spirit', 'creature',
    'cosmological_force', 'void_entity',
]

const isPoweredType = computed(() => POWERED_TYPES.includes(form.entity_type))

// --- Universe helpers ---

const ALL_UNIVERSES = [
    'Harry Potter', 'Cosmere', 'Warhammer 40K', 'Dune', 'Wheel of Time',
    'Lord of the Rings', 'Star Wars', 'Marvel', 'DC', 'Witcher',
    'Elder Scrolls', 'Final Fantasy', 'Mass Effect', 'Dragon Age',
    'Mistborn', 'Stormlight Archive', 'First Law', 'Malazan',
    'Kingkiller Chronicle', 'Night Circus', 'Original',
]

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

const statusOptions = [
    { value: 'concept',   label: 'Concept'   },
    { value: 'active',    label: 'Active'     },
    { value: 'dormant',   label: 'Dormant'    },
    { value: 'deceased',  label: 'Deceased'   },
    { value: 'destroyed', label: 'Destroyed'  },
    { value: 'archived',  label: 'Archived'   },
    { value: 'unknown',   label: 'Unknown'    },
]

const originTypes = [
    { value: 'native',    label: 'Native'    },
    { value: 'canonical', label: 'Canonical' },
    { value: 'alternate', label: 'Alternate' },
    { value: 'original',  label: 'Original'  },
]

const powerTiers = [
    'mundane', 'trained', 'enhanced', 'exceptional',
    'superhuman', 'meta', 'cosmic', 'transcendent', 'absolute',
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
