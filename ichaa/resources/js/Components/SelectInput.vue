<script setup>
import { onMounted, ref } from 'vue';

const model = defineModel();

const select = ref(null);

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
    >
        <slot />
    </select>
</template>
