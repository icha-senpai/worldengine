<template>
    <AuthenticatedLayout>
        <template #header>
            <PageHeaderTrail :items="headerItems" />
        </template>

        <form @submit.prevent="submitHandler" class="max-w-3xl space-y-6">
            <FormErrorSummary :errors="form.errors" />

            <div
                v-for="section in normalizedSections"
                :key="section.title"
                class="panel"
            >
                <h3 v-if="section.title" class="panel-label">{{ section.title }}</h3>

                <div class="space-y-4">
                    <div
                        v-for="field in section.fields"
                        :key="field.key"
                        class="field-group"
                    >
                        <label class="field-label">
                            {{ field.label }}
                            <span v-if="field.required" class="text-danger">*</span>
                        </label>

                        <TextareaInput
                            v-if="field.type === 'textarea'"
                            v-model="form[field.key]"
                            :rows="field.rows ?? 3"
                            :placeholder="field.placeholder ?? ''"
                            class="input w-full resize-none"
                        />

                        <TextareaInput
                            v-else-if="field.type === 'json'"
                            v-model="jsonBuffers[field.key]"
                            :rows="field.rows ?? 5"
                            :placeholder="field.placeholder ?? jsonPlaceholder(field)"
                            class="input w-full resize-none"
                        />

                        <SelectInput
                            v-else-if="field.type === 'select'"
                            v-model="form[field.key]"
                            class="w-full"
                        >
                            <option value="">{{ field.placeholder ?? 'Select an option...' }}</option>
                            <option
                                v-for="option in normalizeOptions(field.options)"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </SelectInput>

                        <div
                            v-else-if="field.type === 'multiselect'"
                            class="multiselect-list"
                        >
                            <label
                                v-for="option in normalizeOptions(field.options)"
                                :key="`${field.key}-${option.value}`"
                                class="multiselect-option"
                            >
                                <Checkbox
                                    v-model:checked="form[field.key]"
                                    :value="option.value"
                                />
                                <span class="multiselect-label">{{ option.label }}</span>
                            </label>

                            <p v-if="!normalizeOptions(field.options).length" class="field-help">
                                {{ field.emptyMessage ?? 'No options available yet.' }}
                            </p>
                        </div>

                        <Checkbox
                            v-else-if="field.type === 'checkbox'"
                            v-model:checked="form[field.key]"
                        />

                        <TextInput
                            v-else
                            v-model="form[field.key]"
                            :type="field.type ?? 'text'"
                            :placeholder="field.placeholder ?? ''"
                            class="w-full"
                        />

                        <p v-if="field.help" class="field-help">{{ field.help }}</p>
                        <p v-if="field.type === 'json'" class="field-help">
                            {{ jsonHelp(field) }}
                        </p>
                        <p v-if="jsonErrors[field.key]" class="field-error">{{ jsonErrors[field.key] }}</p>
                        <p v-else-if="form.errors[field.key]" class="field-error">{{ form.errors[field.key] }}</p>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-3 pt-2">
                <AppButton type="submit" variant="primary" :disabled="form.processing || hasJsonErrors">
                    <span v-if="form.processing">{{ processingLabel }}</span>
                    <span v-else>{{ submitLabel }}</span>
                </AppButton>
                <AppButton :href="cancelHref" variant="ghost">Cancel</AppButton>
                <AppButton v-if="destroyHref" type="button" variant="danger" @click="destroyRecord">
                    {{ destroyLabel }}
                </AppButton>
            </div>
        </form>
    </AuthenticatedLayout>
</template>

<script setup>
import { computed, reactive } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { formatLabel, prettyJson } from '@/Components/scaffold/formatters'
import AppButton from '@/Components/ui/AppButton.vue'
import Checkbox from '@/Components/Checkbox.vue'
import FormErrorSummary from '@/Components/ui/FormErrorSummary.vue'
import PageHeaderTrail from '@/Components/ui/PageHeaderTrail.vue'
import SelectInput from '@/Components/SelectInput.vue'
import TextareaInput from '@/Components/TextareaInput.vue'
import TextInput from '@/Components/TextInput.vue'

const props = defineProps({
    title: { type: String, required: true },
    backHref: { type: String, required: true },
    backLabel: { type: String, required: true },
    cancelHref: { type: String, required: true },
    submitLabel: { type: String, required: true },
    processingLabel: { type: String, default: 'Saving...' },
    destroyHref: { type: String, default: '' },
    destroyLabel: { type: String, default: 'Move to Trash' },
    destroyConfirm: { type: String, default: 'Move this item to trash?' },
    form: { type: Object, required: true },
    sections: { type: Array, required: true },
    onSubmit: { type: Function, required: true },
})

const normalizedSections = computed(() =>
    props.sections.map((section) => ({
        title: section.title ?? '',
        fields: section.fields ?? [],
    })),
)

const headerItems = computed(() => [
    { label: props.backLabel, href: props.backHref },
    { label: props.title },
])

const jsonBuffers = reactive({})
const jsonErrors = reactive({})

for (const section of normalizedSections.value) {
    for (const field of section.fields) {
        if (field.type === 'json') {
            jsonBuffers[field.key] = prettyJson(props.form[field.key])
            jsonErrors[field.key] = ''
        }

        if (field.type === 'multiselect' && !Array.isArray(props.form[field.key])) {
            props.form[field.key] = props.form[field.key] ? [props.form[field.key]] : []
        }
    }
}

const hasJsonErrors = computed(() =>
    Object.values(jsonErrors).some((message) => Boolean(message))
)

const normalizeOptions = (options = []) => options.map((option) =>
    typeof option === 'object'
        ? option
        : { value: option, label: formatLabel(option) }
)

const jsonMode = (field) => {
    if (field.jsonMode) {
        return field.jsonMode
    }

    const key = String(field.key ?? '').toLowerCase()
    const label = String(field.label ?? '').toLowerCase()

    if (key.endsWith('_ids') || label.includes(' ids')) {
        return 'ids'
    }

    if (
        key.includes('effects') ||
        key.includes('hazards') ||
        key.includes('variants') ||
        key.includes('changes') ||
        key.includes('decisions') ||
        key.includes('threads')
    ) {
        return 'list'
    }

    return 'document'
}

const tiptapDocumentFromText = (value) => ({
    type: 'doc',
    content: value
        .split(/\r?\n{2,}/)
        .map((paragraph) => paragraph.trim())
        .filter(Boolean)
        .map((paragraph) => ({
            type: 'paragraph',
            content: [{ type: 'text', text: paragraph }],
        })),
})

const parseListValue = (value) =>
    value
        .split(/\r?\n|,/)
        .map((item) => item.trim())
        .filter(Boolean)

const parseIdValue = (value) =>
    parseListValue(value).map((item) => (/^-?\d+$/.test(item) ? Number(item) : item))

const parsePrimitive = (value) => {
    const trimmed = value.trim()

    if (trimmed === '') {
        return ''
    }

    if (trimmed === 'true') {
        return true
    }

    if (trimmed === 'false') {
        return false
    }

    if (trimmed === 'null') {
        return null
    }

    if (/^-?\d+(\.\d+)?$/.test(trimmed)) {
        return Number(trimmed)
    }

    if ((trimmed.startsWith('[') && trimmed.endsWith(']')) || (trimmed.startsWith('{') && trimmed.endsWith('}'))) {
        try {
            return JSON.parse(trimmed)
        } catch {
            return trimmed
        }
    }

    return trimmed
}

const parseObjectListValue = (field, value) => {
    const objectFields = field.jsonObjectFields ?? []
    const lines = value
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter(Boolean)

    return lines.map((line) => {
        const parts = line.split('|').map((part) => part.trim())
        const record = {}

        objectFields.forEach((name, index) => {
            if (parts[index] !== undefined && parts[index] !== '') {
                record[name] = parsePrimitive(parts[index])
            }
        })

        if (parts.length > objectFields.length) {
            record.notes = parts.slice(objectFields.length).join(' | ')
        }

        return record
    })
}

const parseCollectionRulesValue = (value) =>
    value
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter(Boolean)
        .map((line) => {
            const operatorMatch = [
                [' not_in ', 'not_in'],
                [' contains ', 'contains'],
                [' in ', 'in'],
                [' >= ', 'greater_than'],
                [' <= ', 'less_than'],
                [' > ', 'greater_than'],
                [' < ', 'less_than'],
                [' = ', 'equals'],
                [':', 'equals'],
            ].find(([needle]) => line.includes(needle))

            if (!operatorMatch) {
                return {
                    field: line,
                    operator: 'equals',
                    value: true,
                }
            }

            const [needle, operator] = operatorMatch
            const [rawField, rawValue] = line.split(needle, 2)
            const fieldName = rawField.trim()
            const parsedValue = operator === 'in' || operator === 'not_in'
                ? rawValue.split(',').map((item) => parsePrimitive(item)).filter((item) => item !== '')
                : parsePrimitive(rawValue)

            return {
                field: fieldName,
                operator,
                value: parsedValue,
            }
        })

const jsonPlaceholder = (field) => {
    if (field.placeholder) {
        return field.placeholder
    }

    switch (jsonMode(field)) {
        case 'collection-rules':
            return 'entity_type = character\nsource_universes contains Harry Potter'
        case 'object-list':
            return (field.jsonObjectFields ?? []).join(' | ')
        case 'ids':
            return '1, 2, 3'
        case 'list':
            return 'One item per line'
        default:
            return 'Type normally or paste JSON'
    }
}

const jsonHelp = (field) => {
    switch (jsonMode(field)) {
        case 'collection-rules':
            return 'One rule per line. Use forms like "entity_type = character" or "source_universes contains Harry Potter".'
        case 'object-list':
            return `One item per line using "${(field.jsonObjectFields ?? []).join(' | ')}". You can still paste raw JSON.`
        case 'ids':
            return 'Type IDs normally with commas or new lines, or paste raw JSON.'
        case 'list':
            return 'Type one item per line, or paste raw JSON.'
        default:
            return 'Type normally and it will be wrapped into JSON on save, or paste raw JSON.'
    }
}

const normalizeJsonField = (field) => {
    const value = jsonBuffers[field.key]

    if (!value.trim()) {
        props.form[field.key] = field.emptyValue ?? null
        jsonErrors[field.key] = ''
        return true
    }

    try {
        props.form[field.key] = JSON.parse(value)
        jsonErrors[field.key] = ''
        return true
    } catch {
        switch (jsonMode(field)) {
            case 'collection-rules':
                props.form[field.key] = parseCollectionRulesValue(value)
                jsonErrors[field.key] = ''
                return true
            case 'object-list':
                props.form[field.key] = parseObjectListValue(field, value)
                jsonErrors[field.key] = ''
                return true
            case 'ids':
                props.form[field.key] = parseIdValue(value)
                jsonErrors[field.key] = ''
                return true
            case 'list':
                props.form[field.key] = parseListValue(value)
                jsonErrors[field.key] = ''
                return true
            default:
                props.form[field.key] = tiptapDocumentFromText(value)
                jsonErrors[field.key] = ''
                return true
        }
    }
}

const submitHandler = () => {
    for (const section of normalizedSections.value) {
        for (const field of section.fields ?? []) {
            if (field.type === 'json' && !normalizeJsonField(field)) {
                return
            }
        }
    }

    if (hasJsonErrors.value) {
        return
    }

    props.onSubmit()
}

const destroyRecord = () => {
    if (!props.destroyHref || !confirm(props.destroyConfirm)) {
        return
    }

    router.delete(props.destroyHref)
}
</script>
