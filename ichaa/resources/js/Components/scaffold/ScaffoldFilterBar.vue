<template>
    <form class="index-toolbar" @submit.prevent="onApply">
        <div
            v-for="field in fields"
            :key="field.key"
            class="flex items-center gap-2"
            :class="resolvedFieldType(field) === 'text' ? 'w-full sm:w-auto sm:flex-1 sm:min-w-56' : 'w-full sm:w-auto'"
        >
            <TextInput
                v-if="resolvedFieldType(field) === 'text'"
                v-model="form[field.key]"
                :placeholder="field.placeholder ?? ''"
                class="w-full"
            />

            <SelectInput
                v-else-if="resolvedFieldType(field) === 'select'"
                v-model="form[field.key]"
                class="w-full sm:w-auto"
            >
                <option value="">{{ field.placeholder ?? 'All' }}</option>
                <option
                    v-for="option in normalizeOptions(fallbackOptions(field))"
                    :key="option.value"
                    :value="option.value"
                >
                    {{ option.label }}
                </option>
            </SelectInput>

            <label
                v-else-if="resolvedFieldType(field) === 'checkbox'"
                class="inline-flex min-h-[42px] items-center gap-2 rounded-md border border-border px-3 py-2 text-sm font-ui text-muted-2"
            >
                <Checkbox v-model:checked="form[field.key]" />
                <span>{{ field.label }}</span>
            </label>
        </div>

        <AppButton type="submit" variant="primary">
            Apply
        </AppButton>
        <AppButton
            v-if="hasActiveFilters"
            type="button"
            variant="select-danger"
            @click="handleClear"
        >
            Clear
        </AppButton>
    </form>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, watch } from 'vue'
import AppButton from '@/Components/ui/AppButton.vue'
import Checkbox from '@/Components/Checkbox.vue'
import SelectInput from '@/Components/SelectInput.vue'
import TextInput from '@/Components/TextInput.vue'
import { formatLabel } from '@/Components/scaffold/formatters'
import { visibilityLevelOptions } from '@/Pages/Entities/entityFieldOptions'

const props = defineProps({
    fields: { type: Array, default: () => [] },
    form: { type: Object, required: true },
    hasActiveFilters: { type: Boolean, default: false },
    onApply: { type: Function, required: true },
    onClear: { type: Function, required: true },
})

const normalizeOptions = (options = []) => options.map((option) =>
    typeof option === 'object'
        ? option
        : { value: option, label: formatLabel(option) }
)

let liveSearchTimer = null
let suppressLiveApply = false

const fallbackOptions = (field) => {
    if (field.key === 'visibility') {
        return visibilityLevelOptions
    }

    return field.options ?? []
}

const resolvedFieldType = (field) => {
    if (field.type) {
        return field.type
    }

    if (field.key === 'visibility') {
        return 'select'
    }

    if (Array.isArray(field.options) && field.options.length) {
        return 'select'
    }

    return 'text'
}

const isLiveSearchField = (field) =>
    resolvedFieldType(field) === 'text'
    && (field.live === true || ['q', 'search'].includes(field.key))

const liveSearchValues = computed(() =>
    props.fields
        .filter(isLiveSearchField)
        .map((field) => props.form[field.key] ?? '')
)

const clearLiveSearchTimer = () => {
    if (liveSearchTimer) {
        clearTimeout(liveSearchTimer)
        liveSearchTimer = null
    }
}

onBeforeUnmount(() => {
    clearLiveSearchTimer()
})

watch(liveSearchValues, (values, previousValues) => {
    if (suppressLiveApply || values.length === 0) {
        return
    }

    if (JSON.stringify(values) === JSON.stringify(previousValues ?? [])) {
        return
    }

    clearLiveSearchTimer()
    liveSearchTimer = setTimeout(() => {
        props.onApply()
    }, 250)
})

const handleClear = async () => {
    suppressLiveApply = true
    clearLiveSearchTimer()
    props.onClear()
    await nextTick()
    suppressLiveApply = false
}
</script>
