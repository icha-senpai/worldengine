<template>
    <div class="structured-datetime">
        <div class="structured-datetime__fields">
            <TextInput
                :id="inputId"
                v-model="datePart"
                type="text"
                :placeholder="datePlaceholder"
                class="w-full"
                :aria-label="mode === 'datetime' ? `${ariaLabel} date` : ariaLabel"
                :aria-describedby="describedBy"
                @blur="emitCombined"
            />

            <TextInput
                v-if="mode === 'datetime'"
                v-model="timePart"
                type="text"
                placeholder="HH:MM"
                class="w-full structured-datetime__time"
                :aria-label="`${ariaLabel} time`"
                :aria-describedby="describedBy"
                @blur="emitCombined"
            />
        </div>

        <div class="structured-datetime__actions">
            <button
                v-if="mode === 'datetime'"
                type="button"
                class="shell-control structured-datetime__button"
                @click="setNow"
            >
                Now
            </button>
            <button
                type="button"
                class="shell-control structured-datetime__button"
                @click="clearValue"
            >
                Clear
            </button>
        </div>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import TextInput from '@/Components/TextInput.vue'

const props = defineProps({
    modelValue: { type: [String, null], default: '' },
    mode: {
        type: String,
        default: 'date',
        validator: (value) => ['date', 'datetime'].includes(value),
    },
    inputId: { type: String, default: '' },
    ariaLabel: { type: String, default: 'Date' },
    describedBy: { type: String, default: '' },
    datePlaceholder: { type: String, default: 'YYYY-MM-DD' },
})

const emit = defineEmits(['update:modelValue'])

const datePart = ref('')
const timePart = ref('')

const normalizedValue = computed(() => String(props.modelValue ?? '').trim())

const syncFromModel = (value) => {
    const normalized = value.replace('T', ' ')
    const [date = '', timeRaw = ''] = normalized.split(' ', 2)

    datePart.value = date
    timePart.value = timeRaw ? timeRaw.slice(0, 5) : ''
}

watch(normalizedValue, syncFromModel, { immediate: true })

const emitCombined = () => {
    const date = datePart.value.trim()
    const time = timePart.value.trim()

    if (!date) {
        emit('update:modelValue', '')
        return
    }

    if (props.mode === 'datetime') {
        emit('update:modelValue', time ? `${date} ${normalizeTime(time)}` : date)
        return
    }

    emit('update:modelValue', date)
}

const normalizeTime = (value) => {
    const [hours = '00', minutes = '00'] = value.split(':', 2)

    return `${hours.padStart(2, '0')}:${minutes.padStart(2, '0')}:00`
}

const clearValue = () => {
    datePart.value = ''
    timePart.value = ''
    emit('update:modelValue', '')
}

const setNow = () => {
    const now = new Date()
    const date = [
        now.getFullYear(),
        String(now.getMonth() + 1).padStart(2, '0'),
        String(now.getDate()).padStart(2, '0'),
    ].join('-')
    const time = [
        String(now.getHours()).padStart(2, '0'),
        String(now.getMinutes()).padStart(2, '0'),
    ].join(':')

    datePart.value = date
    timePart.value = time
    emitCombined()
}
</script>
