<script setup>
defineProps({
    type: {
        type: String,
        default: 'button',
    },
});
</script>

<template>
    <button
        :type="type"
        class="inline-flex items-center rounded-lg border border-border bg-surface px-5 py-2.5 text-sm font-semibold uppercase tracking-widest text-primary shadow-sm transition duration-150 ease-in-out hover:bg-surface-2 focus:outline-none focus:ring-2 focus:ring-focus focus:ring-offset-2 disabled:opacity-25"
    >
        <slot />
    </button>
</template>
