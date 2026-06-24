<template>
    <component :is="layoutComponent">
        <template v-if="showLayoutHeader" #header>
            <div class="page-hero">
                <div class="page-hero__copy">
                    <PageHeaderTrail :items="headerItems" />
                    <h1 class="page-hero__title page-hero__title--md mt-3">{{ title }}</h1>
                </div>
            </div>
        </template>

        <form v-if="isDrawerPresentation && !embedded" @submit.prevent="submitHandler">
            <AppDrawer
                :title="title"
                :trail-items="headerItems"
                :close-href="cancelHref"
                @close="closeDrawer"
            >
                <div class="form-shell">
                    <FormErrorSummary :errors="form.errors" />

                    <div
                        v-for="section in normalizedSections"
                        :key="section.title"
                        class="panel form-section"
                    >
                        <h3 v-if="section.title" class="panel-label">{{ section.title }}</h3>

                        <div class="form-section__body">
                            <div
                                v-for="field in section.fields"
                                :key="field.key"
                                class="field-group"
                                :class="{ 'field-group--checkbox': isCheckboxField(field) }"
                            >
                                <label v-if="!isCheckboxField(field)" class="field-label" :for="fieldInputId(field)">
                                    {{ displayLabel(field) }}
                                    <span v-if="field.required" class="text-danger">*</span>
                                </label>

                                <TextareaInput
                                    v-if="resolvedFieldType(field) === 'textarea'"
                                    :id="fieldInputId(field)"
                                    v-model="form[field.key]"
                                    :rows="field.rows ?? 3"
                                    :placeholder="field.placeholder ?? ''"
                                    :aria-describedby="fieldHelpId(field)"
                                    class="input w-full resize-none"
                                />

                                <RichTextEditor
                                    v-else-if="resolvedFieldType(field) === 'json' && isDocumentField(field)"
                                    :input-id="fieldInputId(field)"
                                    :aria-label="displayLabel(field)"
                                    :described-by="fieldHelpId(field)"
                                    v-model="documentBuffers[field.key]"
                                    :placeholder="field.placeholder ?? jsonPlaceholder(field)"
                                />

                                <TextareaInput
                                    v-else-if="resolvedFieldType(field) === 'json'"
                                    :id="fieldInputId(field)"
                                    v-model="jsonBuffers[field.key]"
                                    :rows="field.rows ?? 5"
                                    :placeholder="field.placeholder ?? jsonPlaceholder(field)"
                                    :aria-describedby="fieldHelpId(field)"
                                    class="input w-full resize-none"
                                />

                                <SelectInput
                                    v-else-if="resolvedFieldType(field) === 'select'"
                                    :id="fieldInputId(field)"
                                    v-model="form[field.key]"
                                    :aria-label="displayLabel(field)"
                                    :aria-describedby="fieldHelpId(field)"
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
                                    v-else-if="resolvedFieldType(field) === 'multiselect'"
                                    class="multiselect-list"
                                    role="group"
                                    :aria-label="displayLabel(field)"
                                    :aria-describedby="fieldHelpId(field)"
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

                                <label
                                    v-else-if="resolvedFieldType(field) === 'checkbox'"
                                    class="checkbox-card"
                                    :for="fieldInputId(field)"
                                >
                                    <Checkbox
                                        :id="fieldInputId(field)"
                                        v-model:checked="form[field.key]"
                                        :aria-label="displayLabel(field)"
                                        :aria-describedby="fieldHelpId(field)"
                                    />
                                    <span class="checkbox-card__copy">
                                        <span class="checkbox-card__label">
                                            {{ displayLabel(field) }}
                                            <span v-if="field.required" class="text-danger">*</span>
                                        </span>
                                        <span
                                            v-if="field.help"
                                            :id="fieldHelpId(field)"
                                            class="checkbox-card__help"
                                        >
                                            {{ field.help }}
                                        </span>
                                    </span>
                                </label>

                                <input
                                    v-else-if="resolvedFieldType(field) === 'file'"
                                    :id="fieldInputId(field)"
                                    type="file"
                                    class="input w-full"
                                    :accept="field.accept ?? null"
                                    :aria-describedby="fieldHelpId(field)"
                                    @change="handleFileInput(field, $event)"
                                >

                                <TextInput
                                    v-else
                                    :id="fieldInputId(field)"
                                    v-model="form[field.key]"
                                    :type="resolvedFieldType(field)"
                                    :placeholder="field.placeholder ?? ''"
                                    :aria-label="displayLabel(field)"
                                    :aria-describedby="fieldHelpId(field)"
                                    class="w-full"
                                />

                                <p v-if="!isCheckboxField(field) && field.help" :id="fieldHelpId(field)" class="field-help">{{ field.help }}</p>
                                <p v-else-if="resolvedFieldType(field) === 'file' && selectedFiles[field.key]" :id="fieldHelpId(field)" class="field-help">
                                    Selected: {{ selectedFiles[field.key] }}
                                </p>
                                <p v-else-if="resolvedFieldType(field) === 'json'" :id="fieldHelpId(field)" class="field-help">
                                    {{ jsonHelp(field) }}
                                </p>
                                <p v-if="jsonErrors[field.key]" :id="fieldErrorId(field)" class="field-error">{{ jsonErrors[field.key] }}</p>
                                <p v-else-if="form.errors[field.key]" :id="fieldErrorId(field)" class="field-error">{{ form.errors[field.key] }}</p>
                            </div>
                        </div>
                    </div>

                    <NotionNotePanel v-if="showNotionNote" :note="notionNote" />
                </div>

                <template #footer>
                    <AppButton type="submit" variant="primary" :disabled="form.processing || hasJsonErrors">
                        <span v-if="form.processing">{{ processingLabel }}</span>
                        <span v-else>{{ submitLabel }}</span>
                    </AppButton>
                    <AppButton :href="cancelHref" variant="ghost">Cancel</AppButton>
                    <AppButton v-if="destroyHref" type="button" variant="danger" @click="destroyRecord">
                        {{ destroyLabel }}
                    </AppButton>
                </template>
            </AppDrawer>
        </form>

        <form
            v-else
            @submit.prevent="submitHandler"
            class="form-shell"
            :class="{ 'max-w-3xl': !isDrawerPresentation }"
        >
            <FormErrorSummary :errors="form.errors" />

            <div
                v-for="section in normalizedSections"
                :key="section.title"
                class="panel form-section"
            >
                <h3 v-if="section.title" class="panel-label">{{ section.title }}</h3>

                <div class="form-section__body">
                    <div
                        v-for="field in section.fields"
                        :key="field.key"
                        class="field-group"
                        :class="{ 'field-group--checkbox': isCheckboxField(field) }"
                    >
                        <label v-if="!isCheckboxField(field)" class="field-label" :for="fieldInputId(field)">
                            {{ displayLabel(field) }}
                            <span v-if="field.required" class="text-danger">*</span>
                        </label>

                        <TextareaInput
                            v-if="resolvedFieldType(field) === 'textarea'"
                            :id="fieldInputId(field)"
                            v-model="form[field.key]"
                            :rows="field.rows ?? 3"
                            :placeholder="field.placeholder ?? ''"
                            :aria-describedby="fieldHelpId(field)"
                            class="input w-full resize-none"
                        />

                        <RichTextEditor
                            v-else-if="resolvedFieldType(field) === 'json' && isDocumentField(field)"
                            :input-id="fieldInputId(field)"
                            :aria-label="displayLabel(field)"
                            :described-by="fieldHelpId(field)"
                            v-model="documentBuffers[field.key]"
                            :placeholder="field.placeholder ?? jsonPlaceholder(field)"
                        />

                        <TextareaInput
                            v-else-if="resolvedFieldType(field) === 'json'"
                            :id="fieldInputId(field)"
                            v-model="jsonBuffers[field.key]"
                            :rows="field.rows ?? 5"
                            :placeholder="field.placeholder ?? jsonPlaceholder(field)"
                            :aria-describedby="fieldHelpId(field)"
                            class="input w-full resize-none"
                        />

                        <SelectInput
                            v-else-if="resolvedFieldType(field) === 'select'"
                            :id="fieldInputId(field)"
                            v-model="form[field.key]"
                            :aria-label="displayLabel(field)"
                            :aria-describedby="fieldHelpId(field)"
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
                            v-else-if="resolvedFieldType(field) === 'multiselect'"
                            class="multiselect-list"
                            role="group"
                            :aria-label="displayLabel(field)"
                            :aria-describedby="fieldHelpId(field)"
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

                        <label
                            v-else-if="resolvedFieldType(field) === 'checkbox'"
                            class="checkbox-card"
                            :for="fieldInputId(field)"
                        >
                            <Checkbox
                                :id="fieldInputId(field)"
                                v-model:checked="form[field.key]"
                                :aria-label="displayLabel(field)"
                                :aria-describedby="fieldHelpId(field)"
                            />
                            <span class="checkbox-card__copy">
                                <span class="checkbox-card__label">
                                    {{ displayLabel(field) }}
                                    <span v-if="field.required" class="text-danger">*</span>
                                </span>
                                <span
                                    v-if="field.help"
                                    :id="fieldHelpId(field)"
                                    class="checkbox-card__help"
                                >
                                    {{ field.help }}
                                </span>
                            </span>
                        </label>

                        <input
                            v-else-if="resolvedFieldType(field) === 'file'"
                            :id="fieldInputId(field)"
                            type="file"
                            class="input w-full"
                            :accept="field.accept ?? null"
                            :aria-describedby="fieldHelpId(field)"
                            @change="handleFileInput(field, $event)"
                        >

                        <TextInput
                            v-else
                            :id="fieldInputId(field)"
                            v-model="form[field.key]"
                            :type="resolvedFieldType(field)"
                            :placeholder="field.placeholder ?? ''"
                            :aria-label="displayLabel(field)"
                            :aria-describedby="fieldHelpId(field)"
                            class="w-full"
                        />

                        <p v-if="!isCheckboxField(field) && field.help" :id="fieldHelpId(field)" class="field-help">{{ field.help }}</p>
                        <p v-else-if="resolvedFieldType(field) === 'file' && selectedFiles[field.key]" :id="fieldHelpId(field)" class="field-help">
                            Selected: {{ selectedFiles[field.key] }}
                        </p>
                        <p v-else-if="resolvedFieldType(field) === 'json'" :id="fieldHelpId(field)" class="field-help">
                            {{ jsonHelp(field) }}
                        </p>
                        <p v-if="jsonErrors[field.key]" :id="fieldErrorId(field)" class="field-error">{{ jsonErrors[field.key] }}</p>
                        <p v-else-if="form.errors[field.key]" :id="fieldErrorId(field)" class="field-error">{{ form.errors[field.key] }}</p>
                    </div>
                </div>
            </div>

            <NotionNotePanel v-if="showNotionNote" :note="notionNote" />

            <div class="form-actions">
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
    </component>
</template>

<script setup>
import { computed, defineAsyncComponent, nextTick, reactive } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { formatLabel, prettyJson } from '@/Components/scaffold/formatters'
import NotionNotePanel from '@/Components/NotionNotePanel.vue'
import Checkbox from '@/Components/Checkbox.vue'
import AppDrawer from '@/Components/ui/AppDrawer.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import FormErrorSummary from '@/Components/ui/FormErrorSummary.vue'
import PageHeaderTrail from '@/Components/ui/PageHeaderTrail.vue'
import SelectInput from '@/Components/SelectInput.vue'
import TextareaInput from '@/Components/TextareaInput.vue'
import TextInput from '@/Components/TextInput.vue'
import { confirmDialog, showErrorDialog } from '@/lib/appDialog'
import { normalizeRichDocument, prepareRichDocumentForSubmit } from '@/lib/tiptap/documents'

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
    embedded: { type: Boolean, default: false },
    presentation: {
        type: String,
        default: 'page',
        validator: (value) => ['page', 'drawer'].includes(value),
    },
})

const page = usePage()
const notionNote = computed(() => page.props?.notionNote ?? null)
const RichTextEditor = defineAsyncComponent(() => import('@/Components/scaffold/RichTextEditor.vue'))
const isDrawerPresentation = computed(() => props.presentation === 'drawer')
const layoutComponent = computed(() => (props.embedded ? 'div' : AuthenticatedLayout))
const showLayoutHeader = computed(() => !props.embedded && !isDrawerPresentation.value)
const showNotionNote = computed(() => !props.embedded)

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
const documentBuffers = reactive({})
const jsonErrors = reactive({})
const selectedFiles = reactive({})

for (const section of normalizedSections.value) {
    for (const field of section.fields) {
        if (field.type === 'json') {
            if (isDocumentField(field)) {
                documentBuffers[field.key] = normalizeRichDocument(props.form[field.key])
            } else {
                jsonBuffers[field.key] = prettyJson(props.form[field.key])
            }
            jsonErrors[field.key] = ''
        }

        if (field.type === 'file') {
            selectedFiles[field.key] = ''
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

const handleFileInput = (field, event) => {
    const file = event.target.files?.[0] ?? null

    props.form[field.key] = file
    selectedFiles[field.key] = file?.name ?? ''
}

function jsonMode(field) {
    return field.jsonMode ?? 'document'
}

function isDocumentField(field) {
    return jsonMode(field) === 'document'
}

const resolvedFieldType = (field) => {
    if (field.type) {
        return field.type
    }

    if (Array.isArray(field.options) && field.options.length) {
        return 'select'
    }

    return 'text'
}

const displayLabel = (field) => String(field.label ?? '').replace(/\s+JSON$/i, '')
const isCheckboxField = (field) => resolvedFieldType(field) === 'checkbox'

const fieldInputId = (field) => `field-${field.key}`
const fieldHelpId = (field) => `field-${field.key}-help`
const fieldErrorId = (field) => `field-${field.key}-error`

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
        case 'raw':
            return '{\n  "key": "value"\n}'
        default:
            return 'Start writing...'
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
        case 'raw':
            return 'Paste valid JSON.'
        default:
            return 'Rich text field with formatting, colors, links, images, and table tools.'
    }
}

const normalizeJsonField = (field) => {
    if (isDocumentField(field)) {
        props.form[field.key] = prepareRichDocumentForSubmit(documentBuffers[field.key], field.emptyValue ?? null)
        jsonErrors[field.key] = ''
        return true
    }

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
            case 'raw':
                jsonErrors[field.key] = 'Enter valid JSON.'
                return false
            default:
                jsonErrors[field.key] = 'Unsupported JSON field mode.'
                return false
        }
    }
}

const submitHandler = async () => {
    await nextTick()

    for (const section of normalizedSections.value) {
        for (const field of section.fields ?? []) {
            if (field.type === 'json' && !normalizeJsonField(field)) {
                return
            }

            if (
                resolvedFieldType(field) === 'select' &&
                !field.required &&
                props.form[field.key] === ''
            ) {
                props.form[field.key] = null
            }
        }
    }

    if (hasJsonErrors.value) {
        return
    }

    props.onSubmit()
}

const closeDrawer = () => {
    router.visit(props.cancelHref, props.embedded
        ? {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        }
        : undefined)
}

const destroyRecord = async () => {
    if (!props.destroyHref) {
        return
    }

    const confirmed = await confirmDialog({
        title: props.destroyLabel,
        message: props.destroyConfirm,
        confirmLabel: props.destroyLabel,
        cancelLabel: 'Cancel',
        confirmVariant: 'danger',
    })

    if (!confirmed) {
        return
    }

    router.delete(props.destroyHref, {
        onError: (errors) => {
            void showErrorDialog({
                title: `Could not ${props.destroyLabel.toLowerCase()}`,
                message: 'The request did not complete.',
                details: errors,
            })
        },
    })
}
</script>
