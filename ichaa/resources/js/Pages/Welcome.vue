<script setup>
import { Head, Link } from '@inertiajs/vue3'

defineProps({
    canLogin: {
        type: Boolean,
        default: false,
    },
    canRegister: {
        type: Boolean,
        default: false,
    },
})
</script>

<template>
    <Head title="Dataverse" />

    <div class="min-h-screen bg-canvas text-primary">
        <div class="mx-auto flex min-h-screen w-full max-w-5xl flex-col px-6 py-8 sm:px-8 lg:px-10">
            <header class="flex items-center justify-between border-b border-border pb-6">
                <Link :href="route('home')" class="font-ui text-lg uppercase tracking-[0.18em] text-primary">
                    Dataverse
                </Link>

                <div class="flex items-center gap-3">
                    <template v-if="$page.props.auth.user">
                        <span class="hidden font-ui text-sm text-muted-2 sm:inline">
                            {{ $page.props.auth.user.name }}
                        </span>
                        <Link
                            :href="route('logout')"
                            method="post"
                            as="button"
                            class="app-btn app-btn--ghost app-btn--sm"
                        >
                            Log Out
                        </Link>
                    </template>

                    <template v-else-if="canLogin">
                        <Link :href="route('login')" class="app-btn app-btn--ghost app-btn--sm">
                            Log In
                        </Link>

                        <Link v-if="canRegister" :href="route('register')" class="app-btn app-btn--primary app-btn--sm">
                            Register
                        </Link>
                    </template>
                </div>
            </header>

            <main class="flex flex-1 items-center justify-center py-16">
                <div class="flex w-full max-w-xl flex-col items-center gap-8 text-center">
                    <h1 class="font-ui text-4xl uppercase tracking-[0.18em] text-primary sm:text-5xl">
                        Dataverse
                    </h1>

                    <div
                        v-if="$page.props.flash?.success || $page.props.flash?.error"
                        class="w-full space-y-3"
                    >
                        <div
                            v-if="$page.props.flash?.success"
                            class="rounded-md border border-success/25 bg-success/10 px-4 py-3 font-ui text-sm text-success"
                        >
                            {{ $page.props.flash.success }}
                        </div>

                        <div
                            v-if="$page.props.flash?.error"
                            class="rounded-md border border-[rgb(var(--accent-pink-rgb)/0.28)] bg-[rgb(var(--accent-pink-rgb)/0.08)] px-4 py-3 font-ui text-sm text-(--accent-pink)"
                        >
                            {{ $page.props.flash.error }}
                        </div>
                    </div>

                    <div class="flex flex-wrap justify-center gap-3">
                        <template v-if="$page.props.auth.user?.can_access_world_engine">
                            <Link :href="route('dashboard')" class="app-btn app-btn--primary">
                                World Engine
                            </Link>
                        </template>

                        <template v-if="$page.props.auth.user?.can_access_bitcraft">
                            <Link :href="route('bitcraft.market')" class="app-btn app-btn--ghost">
                                Bitcraft
                            </Link>
                        </template>

                        <template v-else-if="!$page.props.auth.user && canLogin">
                            <Link :href="route('login')" class="app-btn app-btn--primary">
                                Log In
                            </Link>
                        </template>
                    </div>
                </div>
            </main>
        </div>
    </div>
</template>
