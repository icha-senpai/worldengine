<template>
    <section class="surface-section xl:col-span-2" aria-busy="true">
        <div class="surface-section__header">
            <div class="surface-section__copy">
                <span class="surface-section__title">{{ title }}</span>
                <p class="surface-section__subtitle">{{ subtitle }}</p>
            </div>
        </div>

        <div class="surface-section__body">
            <div class="grid gap-3 md:grid-cols-3">
                <div
                    v-for="index in 6"
                    :key="index"
                    class="min-h-24 animate-pulse rounded-md border border-border bg-surface-2 px-3 py-3"
                >
                    <div class="h-3 w-2/3 rounded bg-border/80" />
                    <div class="mt-4 h-2 w-full rounded bg-border/60" />
                    <div class="mt-2 h-2 w-4/5 rounded bg-border/50" />
                </div>
            </div>
        </div>
    </section>
</template>

<script setup>
defineProps({
    title: {
        type: String,
        required: true,
    },
    subtitle: {
        type: String,
        default: 'Loading the heavier ledger data...',
    },
})
</script>
