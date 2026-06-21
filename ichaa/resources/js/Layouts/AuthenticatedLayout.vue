<template>
    <div class="min-h-screen flex flex-col bg-canvas">

        <!-- TOP NAV -->
        <header class="sticky top-0 z-50 bg-surface border-b border-border flex-shrink-0">
            <div class="flex items-center h-11 px-4">

                <!-- Wordmark -->
                <a :href="route('dashboard')" class="flex items-baseline mr-7 flex-shrink-0 font-mono text-sm tracking-widest uppercase">
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
                <div class="flex items-center gap-3 ml-4 flex-shrink-0">

                    <!-- Search -->
                    <a
                        :href="route('search')"
                        class="flex items-center gap-2 px-2.5 py-1 bg-surface-2 border border-border rounded text-muted-2 text-xs font-mono hover:border-border-2 hover:text-primary transition-colors"
                    >
                        <svg width="12" height="12" viewBox="0 0 16 16" fill="none">
                            <circle cx="6.5" cy="6.5" r="5" stroke="currentColor" stroke-width="1.5"/>
                            <path d="M10.5 10.5L14 14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                        </svg>
                        <span>Search</span>
                        <kbd class="px-1 py-px bg-surface border border-border rounded text-[10px]">/</kbd>
                    </a>

                    <!-- Flash -->
                    <Transition name="flash">
                        <div
                            v-if="$page.props.flash?.success"
                            class="text-xs font-mono text-success border border-success/25 bg-success/10 rounded px-2.5 py-1"
                        >
                            {{ $page.props.flash.success }}
                        </div>
                    </Transition>

                    <!-- User dropdown -->
                    <Dropdown align="right" width="44">
                        <template #trigger>
                            <button
                                type="button"
                                class="flex items-center gap-1.5 px-2.5 py-1 bg-surface-2 border border-border rounded text-muted-2 text-xs font-mono hover:border-border-2 hover:text-primary transition-colors"
                            >
                                <span>{{ $page.props.auth.user.name }}</span>
                                <svg class="w-3 h-3 opacity-50" viewBox="0 0 20 20" fill="currentColor">
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

            <div
                v-if="activeSubnavItems.length"
                class="flex items-center gap-1 px-4 h-9 border-t border-border bg-surface-2/70 overflow-x-auto scrollbar-none"
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
                class="flex-1 min-w-0 p-7"
                :class="{ 'max-w-6xl mx-auto w-full': !$slots.sidebar }"
            >
                <div v-if="$slots.header" class="mb-6 pb-5 border-b border-border">
                    <slot name="header" />
                </div>
                <slot />
            </main>

        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import Dropdown from '@/Components/Dropdown.vue'
import DropdownLink from '@/Components/DropdownLink.vue'

const page = usePage()

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
        matches: ['/entities'],
        children: [
            { key: 'entities', label: 'All Entities', href: route('entities.index'), matches: ['/entities'] },
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

const headerOffset = computed(() => (activeSubnavItems.value.length ? '80px' : '44px'))

const isDomainActive = (key) => activeDomain.value?.key === key
</script>

<style>
.scrollbar-none { scrollbar-width: none; }
.scrollbar-none::-webkit-scrollbar { display: none; }

.domain-nav-item {
    display: flex;
    align-items: center;
    gap: 5px;
    height: 100%;
    padding: 0 10px;
    font-size: 11px;
    font-family: ui-monospace, monospace;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--text-muted-3);
    border-bottom: 2px solid transparent;
    white-space: nowrap;
    flex-shrink: 0;
    transition: color 0.15s, border-color 0.15s;
}
.domain-nav-item:hover { color: var(--text-muted); }
.domain-nav-item.active {
    color: var(--text-primary);
    border-bottom-color: var(--accent-cyan);
}
.domain-nav-item.active span:first-child {
    opacity: 1 !important;
    color: var(--accent-cyan);
}

.subdomain-nav-item {
    display: inline-flex;
    align-items: center;
    height: 26px;
    padding: 0 10px;
    border-radius: 4px;
    font-size: 10px;
    font-family: ui-monospace, monospace;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-muted-3);
    white-space: nowrap;
    transition: color 0.15s, background 0.15s, border-color 0.15s;
    border: 1px solid transparent;
}
.subdomain-nav-item:hover {
    color: var(--text-muted);
    background: rgb(0 245 255 / 0.04);
}
.subdomain-nav-item.active {
    color: var(--accent-cyan);
    border-color: rgb(0 245 255 / 0.2);
    background: rgb(0 245 255 / 0.08);
}

.sidebar-section {
    padding: 0 16px 5px;
    font-size: 9px;
    font-weight: 500;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--text-muted-3);
    margin-top: 16px;
    opacity: 0.5;
}
.sidebar-section:first-child { margin-top: 0; }

.sidebar-link {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 16px;
    font-size: 12px;
    font-family: ui-monospace, monospace;
    color: var(--text-muted-3);
    border-left: 2px solid transparent;
    transition: color 0.15s, background 0.15s, border-color 0.15s;
}
.sidebar-link:hover {
    color: var(--text-muted);
    background: rgb(0 245 255 / 0.03);
}
.sidebar-link.active {
    color: var(--text-primary);
    border-left-color: var(--accent-cyan);
    background: rgb(0 245 255 / 0.05);
}

.flash-enter-active, .flash-leave-active { transition: opacity 0.2s, transform 0.2s; }
.flash-enter-from { opacity: 0; transform: translateY(-4px); }
.flash-leave-to  { opacity: 0; }

::-webkit-scrollbar { width: 5px; height: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: var(--border-color-2); }
</style>
