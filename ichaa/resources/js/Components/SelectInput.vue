<script setup>
import { onMounted, ref, useAttrs } from 'vue';

const model = defineModel();

const select = ref(null);
const attrs = useAttrs();

onMounted(() => {
    if (select.value?.hasAttribute('autofocus')) {
        select.value.focus();
    }
});

defineExpose({ focus: () => select.value?.focus() });
</script>

<template>
    <select
        ref="select"
        v-model="model"
        class="input"
        v-bind="attrs"
    >
        <slot />
    </select>
</template>
