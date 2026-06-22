<script setup>
import { onMounted, ref, useAttrs } from 'vue';

defineOptions({
    inheritAttrs: false,
});

const [model, modifiers] = defineModel();

const textarea = ref(null);
const attrs = useAttrs();

onMounted(() => {
    if (textarea.value?.hasAttribute('autofocus')) {
        textarea.value.focus();
    }
});

defineExpose({ focus: () => textarea.value?.focus() });

const normalizeValue = (value) => {
    if (modifiers.trim) {
        return value.trim();
    }

    return value;
};

const updateValue = (event) => {
    model.value = normalizeValue(event.target.value);
};
</script>

<template>
    <textarea
        ref="textarea"
        class="input"
        :value="model ?? ''"
        v-bind="attrs"
        @input="updateValue"
    />
</template>
