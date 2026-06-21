<script setup>
import { onMounted, ref } from 'vue';

const model = defineModel({
    type: String,
    required: true,
});

const input = ref(null);

onMounted(() => {
    if (input.value.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <input
        class="rounded-lg border-border bg-surface px-4 py-3 text-base text-primary shadow-sm focus:border-focus focus:ring-focus"
        v-model="model"
        ref="input"
    />
</template>
