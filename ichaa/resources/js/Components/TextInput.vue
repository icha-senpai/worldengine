<script setup>
import { onMounted, ref, useAttrs } from 'vue';

defineOptions({
    inheritAttrs: false,
});

const [model, modifiers] = defineModel();

const input = ref(null);
const attrs = useAttrs();

onMounted(() => {
    if (input.value?.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value.focus() });

const normalizeValue = (value) => {
    let normalized = value;

    if (modifiers.trim) {
        normalized = normalized.trim();
    }

    if (modifiers.number) {
        if (normalized === '') {
            return '';
        }

        const parsed = Number(normalized);

        return Number.isNaN(parsed) ? normalized : parsed;
    }

    return normalized;
};

const updateValue = (event) => {
    model.value = normalizeValue(event.target.value);
};
</script>

<template>
    <input
        ref="input"
        class="input"
        :value="model ?? ''"
        v-bind="attrs"
        @input="updateValue"
    />
</template>
