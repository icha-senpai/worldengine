<template>
    <div v-if="messages.length" class="error-box">
        <p class="text-danger text-sm font-ui mb-2">{{ title }}</p>
        <ul class="space-y-1">
            <li v-for="(message, index) in messages" :key="index" class="text-danger text-sm font-ui">
                · {{ message }}
            </li>
        </ul>
    </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
    errors: { type: Object, default: () => ({}) },
    title: { type: String, default: 'Fix the following:' },
})

const messages = computed(() => Object.values(props.errors ?? {}).filter(Boolean))
</script>
