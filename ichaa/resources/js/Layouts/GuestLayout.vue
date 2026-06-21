<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div
        class="flex min-h-screen flex-col items-center bg-canvas px-4 pt-8 sm:justify-center sm:pt-0"
    >
        <div>
            <Link href="/">
                <ApplicationLogo class="h-24 w-24 fill-current text-muted" />
            </Link>
        </div>

        <div
            class="mt-8 w-full overflow-hidden bg-surface px-8 py-6 shadow-md sm:max-w-lg sm:rounded-xl"
        >
            <slot />
        </div>
    </div>
</template>
