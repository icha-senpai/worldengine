<template>
    <AuthenticatedLayout v-if="showSetupLayout">
        <template #header>
            <div class="bitcraft-widget-page-header">
                <p>Bitcraft Widget Setup</p>
                <h1>{{ title }}</h1>
                <span v-if="description">{{ description }}</span>
            </div>
        </template>

        <slot />
    </AuthenticatedLayout>

    <slot v-else />
</template>

<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

const props = defineProps({
    setup: { type: Boolean, default: false },
    title: { type: String, required: true },
    description: { type: String, default: '' },
})

const page = usePage()
const showSetupLayout = computed(() => props.setup && Boolean(page.props.auth?.user))
</script>

<style scoped>
.bitcraft-widget-page-header {
    display: grid;
    gap: 6px;
}

.bitcraft-widget-page-header p {
    margin: 0;
    color: var(--text-muted-3);
    font-family: var(--font-ui);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.16em;
    text-transform: uppercase;
}

.bitcraft-widget-page-header h1 {
    margin: 0;
    color: var(--text-primary);
    font-family: var(--font-ui);
    font-size: 24px;
    font-weight: 650;
}

.bitcraft-widget-page-header span {
    color: var(--text-muted);
    font-family: var(--font-ui);
    font-size: 14px;
}
</style>
