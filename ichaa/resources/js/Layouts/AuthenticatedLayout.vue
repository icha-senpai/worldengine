<template>
    <div class="min-h-screen flex flex-col bg-canvas">

        <!-- TOP NAV -->
        <header class="sticky top-0 z-50 bg-surface border-b border-border flex-shrink-0">
            <div class="md:hidden px-4 py-3">
                <div class="flex items-center gap-3">
                    <a :href="route('dashboard')" class="flex items-baseline flex-shrink-0 font-ui text-base tracking-widest uppercase">
                        <span class="text-primary font-light">Data</span><span class="text-focus font-medium">verse</span>
                    </a>

                    <div class="min-w-0 flex-1">
                        <p class="text-[11px] font-ui uppercase tracking-[0.16em] text-muted-3">Current</p>
                        <p class="truncate text-sm font-ui text-primary">{{ activeDomain.label }}</p>
                    </div>

                    <div class="flex items-center gap-2">
                        <Link
                            :href="route('search')"
                            class="mobile-icon-btn"
                            aria-label="Search"
                        >
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                                <circle cx="6.5" cy="6.5" r="5" stroke="currentColor" stroke-width="1.5"/>
                                <path d="M10.5 10.5L14 14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                        </Link>

                        <Link
                            :href="route('trash.index')"
                            class="mobile-icon-btn"
                            :class="{ 'mobile-icon-btn--danger': currentPath === '/trash' }"
                            aria-label="Trash"
                        >
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                                <path d="M3 4h10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                <path d="M5 4V3a1 1 0 011-1h4a1 1 0 011 1v1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                <path d="M5 6.5v4.5M8 6.5v4.5M11 6.5v4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                <path d="M4 4l.6 8.1A1 1 0 005.6 13h4.8a1 1 0 001-.9L12 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                        </Link>

                        <button
                            type="button"
                            class="mobile-icon-btn"
                            :aria-expanded="mobileNavOpen ? 'true' : 'false'"
                            aria-label="Toggle navigation"
                            @click="mobileNavOpen = !mobileNavOpen"
                        >
                            <svg v-if="!mobileNavOpen" width="16" height="16" viewBox="0 0 16 16" fill="none">
                                <path d="M2 4h12M2 8h12M2 12h12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                            <svg v-else width="16" height="16" viewBox="0 0 16 16" fill="none">
                                <path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <Transition name="flash">
                    <div
                        v-if="$page.props.flash?.success"
                        class="mt-3 text-sm font-ui text-success border border-success/25 bg-success/10 rounded-md px-3 py-2"
                    >
                        {{ $page.props.flash.success }}
                    </div>
                </Transition>

                <Transition name="flash">
                    <div
                        v-if="$page.props.flash?.error"
                        class="mt-3 text-sm font-ui text-[var(--accent-pink)] border border-[rgb(var(--accent-pink-rgb)_/_0.28)] bg-[rgb(var(--accent-pink-rgb)_/_0.08)] rounded-md px-3 py-2"
                    >
                        {{ $page.props.flash.error }}
                    </div>
                </Transition>
            </div>

            <div v-if="mobileNavOpen" class="md:hidden border-t border-border bg-surface-2/70 px-4 py-4 space-y-4">
                <div>
                    <p class="mobile-section-label">Domains</p>
                    <nav class="grid gap-2" aria-label="Primary mobile">
                        <div
                            v-for="domain in domains"
                            :key="domain.key"
                            class="mobile-domain-group"
                        >
                            <Link
                                v-if="!domain.children?.length"
                                :href="domain.href"
                                class="mobile-domain-nav-item"
                                :class="{ 'active': isDomainActive(domain.key) }"
                                @click="mobileNavOpen = false"
                            >
                                <span class="opacity-60 flex items-center" v-html="domain.icon" />
                                <span>{{ domain.label }}</span>
                            </Link>

                            <template v-else>
                                <button
                                    type="button"
                                    class="mobile-domain-toggle"
                                    :class="{ 'active': isDomainActive(domain.key) }"
                                    :aria-expanded="isMobileDomainExpanded(domain) ? 'true' : 'false'"
                                    @click="toggleMobileDomain(domain)"
                                >
                                    <span class="flex items-center gap-3 min-w-0">
                                        <span class="opacity-60 flex items-center flex-shrink-0" v-html="domain.icon" />
                                        <span class="truncate">{{ domain.label }}</span>
                                    </span>

                                    <svg
                                        class="mobile-domain-chevron"
                                        :class="{ 'open': isMobileDomainExpanded(domain) }"
                                        width="14"
                                        height="14"
                                        viewBox="0 0 16 16"
                                        fill="none"
                                    >
                                        <path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </button>

                                <div v-if="isMobileDomainExpanded(domain)" class="mobile-domain-children">
                                    <Link
                                        v-for="item in domain.children"
                                        :key="item.key"
                                        :href="item.href"
                                        class="mobile-domain-child-link"
                                        :class="{ 'active': isNavItemActive(item) }"
                                        @click="mobileNavOpen = false"
                                    >
                                        {{ item.label }}
                                    </Link>
                                </div>
                            </template>
                        </div>
                    </nav>
                </div>

                <div>
                    <p class="mobile-section-label">Workspace</p>
                    <div class="rounded-md border border-border bg-surface px-3 py-3">
                        <NotionSyncButton resource="all" label="Sync All" class="w-full" />
                    </div>
                </div>

                <div>
                    <p class="mobile-section-label">Account</p>
                    <div class="rounded-md border border-border bg-surface px-3 py-3">
                        <p class="text-sm font-ui text-primary">{{ $page.props.auth.user.name }}</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <Link :href="route('profile.edit')" class="mobile-subnav-item" @click="mobileNavOpen = false">Profile</Link>
                            <Link :href="route('logout')" method="post" as="button" class="mobile-subnav-item" @click="mobileNavOpen = false">Log Out</Link>
                        </div>
                    </div>
                </div>
            </div>

            <div class="hidden md:flex items-center h-16 px-5 lg:px-6">

                <!-- Wordmark -->
                <a :href="route('dashboard')" class="flex items-baseline mr-8 flex-shrink-0 font-ui text-base tracking-[0.22em] uppercase">
                    <span class="text-primary font-light">Data</span><span class="text-focus font-medium">verse</span>
                </a>

                <!-- Domain nav -->
                <nav class="flex items-center h-full flex-1 overflow-x-auto scrollbar-none" aria-label="Primary">
                    <Link
                        v-for="domain in domains"
                        :key="domain.key"
                        :href="domain.href"
                        class="domain-nav-item"
                        :class="{ 'active': isDomainActive(domain.key) }"
                    >
                        <span class="opacity-50 flex items-center" v-html="domain.icon" />
                        <span>{{ domain.label }}</span>
                    </Link>
                </nav>

                <!-- Right cluster -->
                <div class="shell-toolbar ml-4 flex-shrink-0">

                    <div class="shell-toolbar__group">
                        <Link
                            :href="route('search')"
                            class="shell-control"
                        >
                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                                <circle cx="6.5" cy="6.5" r="5" stroke="currentColor" stroke-width="1.5"/>
                                <path d="M10.5 10.5L14 14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                            <span>Search</span>
                            <kbd>/</kbd>
                        </Link>

                        <Link
                            :href="route('trash.index')"
                            class="shell-control"
                            :class="currentPath === '/trash'
                                ? '!border-[rgb(var(--accent-pink-rgb)_/_0.35)] !text-[var(--accent-pink)] !bg-[rgb(var(--accent-pink-rgb)_/_0.08)]'
                                : ''"
                        >
                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                                <path d="M3 4h10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                <path d="M5 4V3a1 1 0 011-1h4a1 1 0 011 1v1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                <path d="M5 6.5v4.5M8 6.5v4.5M11 6.5v4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                <path d="M4 4l.6 8.1A1 1 0 005.6 13h4.8a1 1 0 001-.9L12 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                            <span>Trash</span>
                        </Link>
                    </div>

                    <div class="shell-toolbar__group">
                        <NotionSyncButton resource="all" label="Sync All" compact />
                    </div>

                    <div v-if="$page.props.flash?.success || $page.props.flash?.error" class="shell-toolbar__group">
                        <Transition name="flash">
                            <div
                                v-if="$page.props.flash?.success"
                                class="text-sm font-ui text-success border border-success/25 bg-success/10 rounded-md px-3 py-1.5"
                            >
                                {{ $page.props.flash.success }}
                            </div>
                        </Transition>

                        <Transition name="flash">
                            <div
                                v-if="$page.props.flash?.error"
                                class="text-sm font-ui text-[var(--accent-pink)] border border-[rgb(var(--accent-pink-rgb)_/_0.28)] bg-[rgb(var(--accent-pink-rgb)_/_0.08)] rounded-md px-3 py-1.5"
                            >
                                {{ $page.props.flash.error }}
                            </div>
                        </Transition>
                    </div>

                    <div class="shell-toolbar__group">
                        <Dropdown align="right" width="44">
                            <template #trigger>
                                <button
                                    type="button"
                                    class="shell-control"
                                >
                                    <span>{{ $page.props.auth.user.name }}</span>
                                    <svg class="w-3.5 h-3.5 opacity-50" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                            </template>
                            <template #content>
                                <DropdownLink :href="route('profile.edit')">Profile</DropdownLink>
                                <DropdownLink :href="route('logout')" method="post" as="button">Log Out</DropdownLink>
                            </template>
                        </Dropdown>
                    </div>

                </div>
            </div>

            <div
                v-if="activeSubnavItems.length"
                class="hidden md:flex items-center gap-1.5 px-5 lg:px-6 h-12 border-t border-border bg-surface-2/70 overflow-x-auto scrollbar-none"
            >
                <Link
                    v-for="item in activeSubnavItems"
                    :key="item.key"
                    :href="item.href"
                    class="subdomain-nav-item"
                    :class="{ 'active': isNavItemActive(item) }"
                >
                    {{ item.label }}
                </Link>
            </div>
        </header>

        <!-- BODY -->
        <div class="flex flex-1 min-h-0">

            <!-- SIDEBAR — optional, provided by each page via slot -->
            <aside v-if="$slots.sidebar" class="w-48 flex-shrink-0 bg-surface border-r border-border">
                <div class="sticky py-4" :style="{ top: headerOffset }">
                    <slot name="sidebar" />
                </div>
            </aside>

            <!-- MAIN -->
            <main
                class="flex-1 min-w-0 p-4 md:p-7 xl:p-8"
                :class="{ 'max-w-6xl mx-auto w-full': !$slots.sidebar }"
            >
                <div v-if="$slots.header" class="mb-7 pb-6 border-b border-border">
                    <slot name="header" />
                </div>
                <slot />
            </main>

        </div>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import Dropdown from '@/Components/Dropdown.vue'
import DropdownLink from '@/Components/DropdownLink.vue'
import NotionSyncButton from '@/Components/NotionSyncButton.vue'

const page = usePage()
const mobileNavOpen = ref(false)
const mobileExpandedDomainKey = ref(null)

const domains = [
    {
        key: 'dashboard',
        label: 'Overview',
        href: route('dashboard'),
        matches: ['/'],
        children: [],
        icon: `<svg width="12" height="12" viewBox="0 0 16 16" fill="none"><rect x="1" y="1" width="6" height="6" rx="1" stroke="currentColor" stroke-width="1.5"/><rect x="9" y="1" width="6" height="6" rx="1" stroke="currentColor" stroke-width="1.5"/><rect x="1" y="9" width="6" height="6" rx="1" stroke="currentColor" stroke-width="1.5"/><rect x="9" y="9" width="6" height="6" rx="1" stroke="currentColor" stroke-width="1.5"/></svg>`
    },
    {
        key: 'entities',
        label: 'Entities',
        href: route('entities.index'),
        matches: ['/entities', '/media-references'],
        children: [
            { key: 'entities', label: 'All Entities', href: route('entities.index'), matches: ['/entities'] },
            { key: 'media-references', label: 'Media Library', href: route('media-references.index'), matches: ['/media-references'] },
        ],
        icon: `<svg width="12" height="12" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="5" r="3" stroke="currentColor" stroke-width="1.5"/><path d="M2 14c0-3.314 2.686-5 6-5s6 1.686 6 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>`
    },
    {
        key: 'connections',
        label: 'Connections',
        href: route('relationships.index'),
        matches: ['/relationships', '/group-relationships', '/faction-memberships'],
        children: [
            { key: 'relationships', label: 'Relationships', href: route('relationships.index'), matches: ['/relationships'] },
            { key: 'group-relationships', label: 'Group Relationships', href: route('group-relationships.index'), matches: ['/group-relationships'] },
        ],
        icon: `<svg width="12" height="12" viewBox="0 0 16 16" fill="none"><circle cx="3" cy="8" r="2" stroke="currentColor" stroke-width="1.5"/><circle cx="13" cy="4" r="2" stroke="currentColor" stroke-width="1.5"/><circle cx="13" cy="12" r="2" stroke="currentColor" stroke-width="1.5"/><path d="M5 8l6-4M5 8l6 4" stroke="currentColor" stroke-width="1.5"/></svg>`
    },
    {
        key: 'temporal',
        label: 'Temporal',
        href: route('timelines.index'),
        matches: ['/timelines', '/character-states', '/concurrency-groups'],
        children: [
            { key: 'timelines', label: 'Timelines', href: route('timelines.index'), matches: ['/timelines'] },
            { key: 'character-states', label: 'Character States', href: route('character-states.index'), matches: ['/character-states'] },
            { key: 'concurrency-groups', label: 'Concurrency Groups', href: route('concurrency-groups.index'), matches: ['/concurrency-groups'] },
        ],
        icon: `<svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M2 8h12M8 2v12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="8" cy="8" r="2.5" stroke="currentColor" stroke-width="1.5"/></svg>`
    },
    {
        key: 'lore',
        label: 'Lore',
        href: route('documents.index'),
        matches: ['/documents', '/canon-references', '/crossover-entry-points'],
        children: [
            { key: 'documents', label: 'Documents', href: route('documents.index'), matches: ['/documents'] },
            { key: 'canon-references', label: 'Canon References', href: route('canon-references.index'), matches: ['/canon-references'] },
            { key: 'crossover-entry-points', label: 'Entry Points', href: route('crossover-entry-points.index'), matches: ['/crossover-entry-points'] },
        ],
        icon: `<svg width="12" height="12" viewBox="0 0 16 16" fill="none"><rect x="3" y="1" width="10" height="14" rx="1" stroke="currentColor" stroke-width="1.5"/><path d="M6 5h4M6 8h4M6 11h2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>`
    },
    {
        key: 'intelligence',
        label: 'Intelligence',
        href: route('knowledge-states.index'),
        matches: ['/knowledge-states', '/secrets', '/perception-states'],
        children: [
            { key: 'knowledge-states', label: 'Knowledge States', href: route('knowledge-states.index'), matches: ['/knowledge-states'] },
            { key: 'secrets', label: 'Secrets', href: route('secrets.index'), matches: ['/secrets'] },
            { key: 'perception-states', label: 'Perception States', href: route('perception-states.index'), matches: ['/perception-states'] },
        ],
        icon: `<svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M8 2a4 4 0 014 4c0 2-1 3-2 4v2H6v-2C5 9 4 8 4 6a4 4 0 014-4z" stroke="currentColor" stroke-width="1.5"/><path d="M6 14h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>`
    },
    {
        key: 'world',
        label: 'World',
        href: route('power-interactions.index'),
        matches: ['/power-interactions', '/travel-routes', '/location-containment', '/location-control'],
        children: [
            { key: 'power-interactions', label: 'Power Interactions', href: route('power-interactions.index'), matches: ['/power-interactions'] },
            { key: 'travel-routes', label: 'Travel Routes', href: route('travel-routes.index'), matches: ['/travel-routes'] },
            { key: 'location-containment', label: 'Containment', href: route('location-containment.index'), matches: ['/location-containment'] },
            { key: 'location-control', label: 'Control', href: route('location-control.index'), matches: ['/location-control'] },
        ],
        icon: `<svg width="12" height="12" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.5"/><path d="M2 8h12M8 2a9 9 0 010 12M8 2a9 9 0 000 12" stroke="currentColor" stroke-width="1.5"/></svg>`
    },
    {
        key: 'organize',
        label: 'Organize',
        href: route('collections.index'),
        matches: ['/collections', '/glossary'],
        children: [
            { key: 'collections', label: 'Collections', href: route('collections.index'), matches: ['/collections'] },
            { key: 'glossary', label: 'Glossary', href: route('glossary.index'), matches: ['/glossary'] },
        ],
        icon: `<svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M2 4h12M2 8h8M2 12h10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>`
    },
    {
        key: 'production',
        label: 'Production',
        href: route('meta.index'),
        matches: ['/meta', '/pipeline', '/session-logs'],
        children: [
            { key: 'meta', label: 'Meta', href: route('meta.index'), matches: ['/meta'] },
            { key: 'pipeline', label: 'Pipeline', href: route('pipeline.index'), matches: ['/pipeline'] },
            { key: 'session-logs', label: 'Sessions', href: route('session-logs.index'), matches: ['/session-logs'] },
        ],
        icon: `<svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M3 3l10 5-10 5V3z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>`
    },
]

const currentPath = computed(() => page.url.split('?')[0] || '/')

const pathMatches = (path, match) => {
    if (match === '/') {
        return path === '/'
    }

    return path === match || path.startsWith(`${match}/`)
}

const isNavItemActive = (item) => item.matches?.some((match) => pathMatches(currentPath.value, match)) ?? false

const activeDomain = computed(() =>
    domains.find((domain) => isNavItemActive(domain)) ?? domains[0]
)

const activeSubnavItems = computed(() => activeDomain.value?.children ?? [])

const headerOffset = computed(() => (activeSubnavItems.value.length ? '100px' : '56px'))

const isDomainActive = (key) => activeDomain.value?.key === key
const isMobileDomainExpanded = (domain) => mobileExpandedDomainKey.value === domain.key

const toggleMobileDomain = (domain) => {
    mobileExpandedDomainKey.value = mobileExpandedDomainKey.value === domain.key ? null : domain.key
}

watch(
    () => activeDomain.value?.key,
    (key) => {
        mobileExpandedDomainKey.value = key ?? null
    },
    { immediate: true },
)

watch(
    () => page.url,
    () => {
        mobileNavOpen.value = false
        mobileExpandedDomainKey.value = activeDomain.value?.key ?? null
    },
)
</script>
