<template>
    <form class="index-toolbar" @submit.prevent="onApply">
        <div
            v-for="field in fields"
            :key="field.key"
            class="flex items-center gap-2"
            :class="field.type === 'text' ? 'w-full sm:w-auto sm:flex-1 sm:min-w-56' : 'w-full sm:w-auto'"
        >
            <TextInput
                v-if="field.type === 'text'"
                v-model="form[field.key]"
                :placeholder="field.placeholder ?? ''"
                class="w-full"
            />

            <SelectInput
                v-else-if="field.type === 'select'"
                v-model="form[field.key]"
                class="w-full sm:w-auto"
            >
                <option value="">{{ field.placeholder ?? 'All' }}</option>
                <option
                    v-for="option in normalizeOptions(field.options)"
                    :key="option.value"
                    :value="option.value"
                >
                    {{ option.label }}
                </option>
            </SelectInput>

            <label
                v-else-if="field.type === 'checkbox'"
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
            @click="onClear"
        >
            Clear
        </AppButton>
    </form>
</template>

<script setup>
import AppButton from '@/Components/ui/AppButton.vue'
import Checkbox from '@/Components/Checkbox.vue'
import SelectInput from '@/Components/SelectInput.vue'
import TextInput from '@/Components/TextInput.vue'
import { formatLabel } from '@/Components/scaffold/formatters'

defineProps({
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
</script>
