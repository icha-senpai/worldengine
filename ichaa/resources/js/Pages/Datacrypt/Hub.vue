<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="page-hero">
                <div class="page-hero__copy">
                    <div class="page-hero__eyebrow">Datacrypt</div>
                    <h1 class="page-hero__title page-hero__title--md">Hub</h1>
                </div>
            </div>
        </template>

        <div class="grid gap-5 lg:grid-cols-2">
            <section
                v-for="section in sections"
                :key="section.key"
                class="surface-section"
            >
                <div class="surface-section__header">
                    <div class="surface-section__copy">
                        <span class="surface-section__title">{{ section.label }}</span>
                        <p class="surface-section__subtitle">{{ section.status }}</p>
                    </div>
                    <span class="tag" :class="{ 'tag--success': section.enabled }">
                        {{ section.enabled ? 'Open' : 'Locked' }}
                    </span>
                </div>

                <div class="surface-section__body">
                    <Link
                        v-if="section.enabled"
                        :href="section.href"
                        class="app-btn app-btn--primary"
                    >
                        Enter
                    </Link>

                    <button
                        v-else
                        type="button"
                        class="app-btn app-btn--ghost"
                        disabled
                    >
                        Locked
                    </button>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

const page = usePage()
const user = computed(() => page.props.auth?.user ?? {})

const sections = computed(() => [
    {
        key: 'bitcraft',
        label: 'Bitcraft',
        href: route('bitcraft.market'),
        enabled: Boolean(user.value.can_access_bitcraft),
        status: user.value.can_access_bitcraft ? 'Market, crafting, and trackers.' : 'No access assigned.',
    },
    {
        key: 'world-engine',
        label: 'World Engine',
        href: route('dashboard'),
        enabled: Boolean(user.value.can_access_world_engine),
        status: user.value.can_access_world_engine ? 'Worldbuilding dashboard and records.' : 'No access assigned.',
    },
])
</script>
