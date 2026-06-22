<template>
    <div class="flex items-center gap-3">
        <template v-for="(item, index) in items" :key="`${index}-${item.label}`">
            <Link
                v-if="item.href"
                :href="item.href"
                class="text-muted-3 text-sm font-ui hover:text-muted-2 transition-colors"
                :class="{ 'truncate max-w-48': item.truncate }"
            >
                {{ item.label }}
            </Link>

            <span
                v-else
                class="text-primary text-base font-light"
                :class="{ 'truncate max-w-48': item.truncate }"
            >
                {{ item.label }}
            </span>

            <span v-if="index < items.length - 1" class="text-muted-3 text-sm font-ui">/</span>
        </template>
    </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'

defineProps({
    items: { type: Array, required: true },
})
</script>
