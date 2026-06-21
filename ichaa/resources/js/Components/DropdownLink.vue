<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    href: {
        type: String,
        required: true,
    },
});
</script>

<template>
    <Link
        :href="href"
        class="block w-full px-4 py-3 text-start text-base leading-6 text-primary transition duration-150 ease-in-out hover:bg-surface-2 focus:bg-surface-2 focus:outline-none"
    >
        <slot />
    </Link>
</template>
