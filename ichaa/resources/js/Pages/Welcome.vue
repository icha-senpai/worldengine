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
        <div class="mx-auto flex min-h-screen w-full max-w-6xl flex-col px-6 py-8 sm:px-8 lg:px-10">
            <header class="flex items-center justify-between border-b border-border pb-6">
                <div>
                    <p class="font-ui text-xs uppercase tracking-[0.24em] text-muted-3">Dataverse</p>
                    <h1 class="mt-2 font-ui text-2xl uppercase tracking-[0.14em] text-primary sm:text-3xl">
                        Public Gateway
                    </h1>
                </div>

                <div class="flex items-center gap-3">
                    <template v-if="$page.props.auth.user">
                        <span class="hidden text-sm font-ui text-muted-2 sm:inline">
                            {{ $page.props.auth.user.name }}
                        </span>
                        <Link
                            :href="route('logout')"
                            method="post"
                            as="button"
                            class="inline-flex items-center rounded-md border border-border bg-surface px-4 py-2 font-ui text-sm uppercase tracking-[0.14em] text-primary transition hover:border-focus/40 hover:text-focus"
                        >
                            Log Out
                        </Link>
                    </template>

                    <template v-else-if="canLogin">
                        <Link
                            :href="route('login')"
                            class="inline-flex items-center rounded-md border border-border bg-surface px-4 py-2 font-ui text-sm uppercase tracking-[0.14em] text-primary transition hover:border-focus/40 hover:text-focus"
                        >
                            Log In
                        </Link>

                        <Link
                            v-if="canRegister"
                            :href="route('register')"
                            class="inline-flex items-center rounded-md border border-focus/40 bg-focus px-4 py-2 font-ui text-sm uppercase tracking-[0.14em] text-primary transition hover:bg-focus/85"
                        >
                            Register
                        </Link>
                    </template>
                </div>
            </header>

            <main class="flex flex-1 items-center py-12 sm:py-16">
                <div class="grid w-full gap-8 lg:grid-cols-[minmax(0,1.4fr)_minmax(18rem,24rem)] lg:items-start">
                    <section class="rounded-2xl border border-border bg-surface px-6 py-8 shadow-[0px_14px_34px_0px_rgba(0,0,0,0.08)] sm:px-8 sm:py-10">
                        <p class="font-ui text-xs uppercase tracking-[0.24em] text-focus">Private Control Surface</p>
                        <h2 class="mt-4 max-w-3xl font-ui text-3xl uppercase leading-tight tracking-[0.12em] text-primary sm:text-4xl">
                            Dataverse keeps the public front door open and the private workspace behind Datacrypt.
                        </h2>
                        <p class="mt-5 max-w-2xl text-base leading-7 text-muted-2 sm:text-lg">
                            This public root is a clean landing space. The World Engine now lives under
                            <span class="font-ui uppercase tracking-[0.12em] text-primary">/datacrypt/worldengine</span>, with room for more private sections beside it.
                        </p>

                        <div
                            v-if="$page.props.flash?.success || $page.props.flash?.error"
                            class="mt-6 space-y-3"
                        >
                            <div
                                v-if="$page.props.flash?.success"
                                class="rounded-xl border border-success/25 bg-success/10 px-4 py-3 font-ui text-sm text-success"
                            >
                                {{ $page.props.flash.success }}
                            </div>

                            <div
                                v-if="$page.props.flash?.error"
                                class="rounded-xl border border-[rgb(var(--accent-pink-rgb)/0.28)] bg-[rgb(var(--accent-pink-rgb)/0.08)] px-4 py-3 font-ui text-sm text-(--accent-pink)"
                            >
                                {{ $page.props.flash.error }}
                            </div>
                        </div>

                        <div class="mt-8 flex flex-wrap gap-3">
                            <template v-if="$page.props.auth.user?.is_admin">
                                <Link
                                    :href="route('dashboard')"
                                    class="inline-flex items-center rounded-md border border-focus/40 bg-focus px-5 py-3 font-ui text-sm uppercase tracking-[0.16em] text-primary transition hover:bg-focus/85"
                                >
                                    Enter World Engine
                                </Link>
                            </template>

                            <template v-else-if="!$page.props.auth.user && canLogin">
                                <Link
                                    :href="route('login')"
                                    class="inline-flex items-center rounded-md border border-focus/40 bg-focus px-5 py-3 font-ui text-sm uppercase tracking-[0.16em] text-primary transition hover:bg-focus/85"
                                >
                                    Sign In
                                </Link>
                            </template>
                        </div>
                    </section>

                    <aside class="rounded-2xl border border-border bg-surface-2/70 px-6 py-8">
                        <p class="font-ui text-xs uppercase tracking-[0.24em] text-muted-3">Access State</p>

                        <div class="mt-5 space-y-4">
                            <div
                                v-if="$page.props.auth.user?.is_admin"
                                class="rounded-xl border border-success/20 bg-success/10 px-4 py-4"
                            >
                                <p class="font-ui text-xs uppercase tracking-[0.18em] text-success">Admin Access</p>
                                <p class="mt-2 text-sm leading-6 text-muted-1">
                                    Your account can enter Datacrypt and use the protected workspace routes.
                                </p>
                            </div>

                            <div
                                v-else-if="$page.props.auth.user"
                                class="rounded-xl border border-border bg-surface px-4 py-4"
                            >
                                <p class="font-ui text-xs uppercase tracking-[0.18em] text-muted-2">Signed In</p>
                                <p class="mt-2 text-sm leading-6 text-muted-1">
                                    You are logged in, but this account does not have Datacrypt access.
                                </p>
                            </div>

                            <div
                                v-else
                                class="rounded-xl border border-border bg-surface px-4 py-4"
                            >
                                <p class="font-ui text-xs uppercase tracking-[0.18em] text-muted-2">Guest</p>
                                <p class="mt-2 text-sm leading-6 text-muted-1">
                                    Sign in to identify yourself. Only admin accounts can open the protected Datacrypt sections.
                                </p>
                            </div>

                            <div class="rounded-xl border border-border bg-surface px-4 py-4">
                                <p class="font-ui text-xs uppercase tracking-[0.18em] text-muted-2">Route Layout</p>
                                <p class="mt-2 text-sm leading-6 text-muted-1">
                                    Public landing page at <span class="font-ui text-primary">/</span>. Private sections start at
                                    <span class="font-ui text-primary">/datacrypt</span>, with World Engine at
                                    <span class="font-ui text-primary">/datacrypt/worldengine</span>.
                                </p>
                            </div>
                        </div>
                    </aside>
                </div>
            </main>
        </div>
    </div>
</template>
