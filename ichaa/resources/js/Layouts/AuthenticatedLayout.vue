<template>
    <div class="min-h-screen bg-canvas md:flex">

        <!-- MOBILE NAV -->
        <header class="sticky top-0 z-50 shrink-0 bg-surface border-b border-border md:hidden">
            <div class="md:hidden px-4 py-3">
                <div class="flex items-center gap-3">
                    <a :href="route('dashboard')" class="flex items-baseline shrink-0 font-ui text-base tracking-widest uppercase">
                        <span class="text-primary font-light">Data</span><span class="text-focus font-medium">verse</span>
                    </a>

                    <div class="min-w-0 flex-1">
                        <p class="text-[11px] font-ui uppercase tracking-[0.16em] text-muted-3">Current</p>
                        <p class="truncate text-sm font-ui text-primary">{{ activeShellLabel }}</p>
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
                        class="mt-3 text-sm font-ui text-(--accent-pink) border border-[rgb(var(--accent-pink-rgb)/0.28)] bg-[rgb(var(--accent-pink-rgb)/0.08)] rounded-md px-3 py-2"
                    >
                        {{ $page.props.flash.error }}
                    </div>
                </Transition>
            </div>

            <div v-if="mobileNavOpen" class="md:hidden border-t border-border bg-surface-2/70 px-4 py-4 space-y-4">
                <div>
                    <p class="mobile-section-label">Sections</p>
                    <nav class="grid gap-2" aria-label="Primary mobile">
                        <button
                            type="button"
                            class="mobile-domain-toggle"
                            :class="{ 'active': isWorldEngineActive }"
                            :aria-expanded="mobileWorldEngineOpen ? 'true' : 'false'"
                            @click="mobileWorldEngineOpen = !mobileWorldEngineOpen"
                        >
                            <span class="flex items-center gap-3 min-w-0">
                                <span class="truncate">World Engine</span>
                            </span>

                            <svg
                                class="mobile-domain-chevron"
                                :class="{ 'open': mobileWorldEngineOpen }"
                                width="14"
                                height="14"
                                viewBox="0 0 16 16"
                                fill="none"
                            >
                                <path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>

                        <div v-if="mobileWorldEngineOpen" class="mobile-workspace-children">
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
                                            <span class="opacity-60 flex items-center shrink-0" v-html="domain.icon" />
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
                        </div>

                        <button
                            type="button"
                            class="mobile-domain-toggle"
                            :class="{ 'active': isBitcraftToolsActive }"
                            :aria-expanded="mobileBitcraftToolsOpen ? 'true' : 'false'"
                            @click="mobileBitcraftToolsOpen = !mobileBitcraftToolsOpen"
                        >
                            <span class="flex items-center gap-3 min-w-0">
                                <span class="truncate">Bitcraft Tools</span>
                            </span>

                            <svg
                                class="mobile-domain-chevron"
                                :class="{ 'open': mobileBitcraftToolsOpen }"
                                width="14"
                                height="14"
                                viewBox="0 0 16 16"
                                fill="none"
                            >
                                <path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>

                        <div v-if="mobileBitcraftToolsOpen" class="mobile-workspace-children">
                            <Link
                                v-for="item in bitcraftTools.children"
                                :key="item.key"
                                :href="item.href"
                                class="mobile-domain-child-link"
                                :class="{ 'active': isNavItemActive(item) }"
                                @click="mobileNavOpen = false"
                            >
                                {{ item.label }}
                            </Link>
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

        </header>

        <!-- DESKTOP SIDEBAR -->
        <header class="desktop-shell-sidebar hidden md:flex">
            <div class="desktop-shell-sidebar__brand">
                <a :href="route('dashboard')" class="desktop-shell-sidebar__wordmark">
                    <span class="text-primary font-light">Data</span><span class="text-focus font-medium">verse</span>
                </a>

                <p class="desktop-shell-sidebar__current">{{ activeShellLabel }}</p>
            </div>

            <div class="desktop-shell-sidebar__quick-actions">
                <Link
                    :href="route('search')"
                    class="desktop-shell-action"
                    :class="{ 'active': currentPath === '/search' }"
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
                    class="desktop-shell-action"
                    :class="{ 'desktop-shell-action--danger': currentPath === '/trash' }"
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

            <nav class="desktop-shell-nav" aria-label="Primary">
                <button
                    type="button"
                    class="desktop-shell-nav__workspace"
                    :class="{ 'active': isWorldEngineActive }"
                    :aria-expanded="desktopWorldEngineOpen ? 'true' : 'false'"
                    @click="desktopWorldEngineOpen = !desktopWorldEngineOpen"
                >
                    <span class="truncate">World Engine</span>

                    <svg
                        class="desktop-shell-nav__chevron"
                        :class="{ 'open': desktopWorldEngineOpen }"
                        width="14"
                        height="14"
                        viewBox="0 0 16 16"
                        fill="none"
                    >
                        <path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>

                <div v-if="desktopWorldEngineOpen" class="desktop-shell-nav__workspace-children">
                    <template v-for="domain in domains" :key="domain.key">
                        <Link
                            v-if="!domain.children?.length"
                            :href="domain.href"
                            class="desktop-shell-nav__domain"
                            :class="{ 'active': isDomainActive(domain.key) }"
                        >
                            <span class="desktop-shell-nav__icon" v-html="domain.icon" />
                            <span class="truncate">{{ domain.label }}</span>
                        </Link>

                        <button
                            v-else
                            type="button"
                            class="desktop-shell-nav__domain"
                            :class="{
                                'active': isDomainActive(domain.key),
                                'expanded': isDesktopDomainExpanded(domain),
                            }"
                            :aria-expanded="isDesktopDomainExpanded(domain) ? 'true' : 'false'"
                            @click="toggleDesktopDomain(domain)"
                        >
                            <span class="desktop-shell-nav__domain-label">
                                <span class="desktop-shell-nav__icon" v-html="domain.icon" />
                                <span class="truncate">{{ domain.label }}</span>
                            </span>

                            <svg
                                class="desktop-shell-nav__chevron"
                                :class="{ 'open': isDesktopDomainExpanded(domain) }"
                                width="14"
                                height="14"
                                viewBox="0 0 16 16"
                                fill="none"
                            >
                                <path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>

                        <div
                            v-if="isDesktopDomainExpanded(domain)"
                            class="desktop-shell-nav__children"
                        >
                            <Link
                                v-for="item in domain.children"
                                :key="item.key"
                                :href="item.href"
                                class="desktop-shell-nav__child"
                                :class="{ 'active': isNavItemActive(item) }"
                            >
                                {{ item.label }}
                            </Link>
                        </div>
                    </template>
                </div>

                <button
                    type="button"
                    class="desktop-shell-nav__workspace"
                    :class="{ 'active': isBitcraftToolsActive }"
                    :aria-expanded="desktopBitcraftToolsOpen ? 'true' : 'false'"
                    @click="desktopBitcraftToolsOpen = !desktopBitcraftToolsOpen"
                >
                    <span class="truncate">Bitcraft Tools</span>

                    <svg
                        class="desktop-shell-nav__chevron"
                        :class="{ 'open': desktopBitcraftToolsOpen }"
                        width="14"
                        height="14"
                        viewBox="0 0 16 16"
                        fill="none"
                    >
                        <path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>

                <div v-if="desktopBitcraftToolsOpen" class="desktop-shell-nav__workspace-children">
                    <Link
                        v-for="item in bitcraftTools.children"
                        :key="item.key"
                        :href="item.href"
                        class="desktop-shell-nav__child"
                        :class="{ 'active': isNavItemActive(item) }"
                    >
                        {{ item.label }}
                    </Link>
                </div>
            </nav>

            <div class="desktop-shell-sidebar__footer">
                <div class="desktop-shell-sidebar__sync">
                    <NotionSyncButton resource="all" label="Sync All" compact />
                </div>

                <div class="desktop-shell-account">
                    <p class="truncate">{{ $page.props.auth.user.name }}</p>

                    <div class="desktop-shell-account__links">
                        <Link :href="route('profile.edit')" class="desktop-shell-account-link">Profile</Link>
                        <Link :href="route('logout')" method="post" as="button" class="desktop-shell-account-link">Log Out</Link>
                    </div>
                </div>
            </div>
        </header>

        <!-- BODY -->
        <div class="flex flex-1 min-h-0 md:min-w-0">

            <!-- SIDEBAR — optional, provided by each page via slot -->
            <aside v-if="$slots.sidebar" class="w-48 shrink-0 bg-surface border-r border-border">
                <div class="sticky top-0 py-4">
                    <slot name="sidebar" />
                </div>
            </aside>

            <!-- MAIN -->
            <main
                class="flex-1 min-w-0 p-4 md:p-7 xl:p-8"
                :class="{ 'max-w-6xl mx-auto w-full': !$slots.sidebar }"
            >
                <div v-if="$page.props.flash?.success || $page.props.flash?.error" class="hidden md:block mb-6">
                    <Transition name="flash">
                        <div
                            v-if="$page.props.flash?.success"
                            class="text-sm font-ui text-success border border-success/25 bg-success/10 rounded-md px-3 py-2"
                        >
                            {{ $page.props.flash.success }}
                        </div>
                    </Transition>

                    <Transition name="flash">
                        <div
                            v-if="$page.props.flash?.error"
                            class="text-sm font-ui text-(--accent-pink) border border-[rgb(var(--accent-pink-rgb)/0.28)] bg-[rgb(var(--accent-pink-rgb)/0.08)] rounded-md px-3 py-2"
                        >
                            {{ $page.props.flash.error }}
                        </div>
                    </Transition>
                </div>

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
import NotionSyncButton from '@/Components/NotionSyncButton.vue'

const page = usePage()
const NAV_STORAGE_KEY = 'dataverse.shellNavState'
const savedNavState = readNavState()
const mobileNavOpen = ref(false)
const mobileWorldEngineOpen = ref(savedNavState.mobileWorldEngineOpen)
const desktopWorldEngineOpen = ref(savedNavState.desktopWorldEngineOpen)
const mobileBitcraftToolsOpen = ref(savedNavState.mobileBitcraftToolsOpen)
const desktopBitcraftToolsOpen = ref(savedNavState.desktopBitcraftToolsOpen)
const mobileExpandedDomainKey = ref(savedNavState.mobileExpandedDomainKey)
const desktopExpandedDomainKeys = ref(savedNavState.desktopExpandedDomainKeys)

function readNavState() {
    if (typeof window === 'undefined') {
        return defaultNavState()
    }

    try {
        const saved = JSON.parse(window.localStorage.getItem(NAV_STORAGE_KEY) ?? '{}')

        return {
            ...defaultNavState(),
            ...saved,
            desktopExpandedDomainKeys: Array.isArray(saved.desktopExpandedDomainKeys) ? saved.desktopExpandedDomainKeys : [],
            mobileExpandedDomainKey: typeof saved.mobileExpandedDomainKey === 'string' ? saved.mobileExpandedDomainKey : null,
        }
    } catch {
        return defaultNavState()
    }
}

function defaultNavState() {
    return {
        mobileWorldEngineOpen: false,
        desktopWorldEngineOpen: false,
        mobileBitcraftToolsOpen: false,
        desktopBitcraftToolsOpen: false,
        mobileExpandedDomainKey: null,
        desktopExpandedDomainKeys: [],
    }
}

const saveNavState = () => {
    if (typeof window === 'undefined') {
        return
    }

    window.localStorage.setItem(NAV_STORAGE_KEY, JSON.stringify({
        mobileWorldEngineOpen: mobileWorldEngineOpen.value,
        desktopWorldEngineOpen: desktopWorldEngineOpen.value,
        mobileBitcraftToolsOpen: mobileBitcraftToolsOpen.value,
        desktopBitcraftToolsOpen: desktopBitcraftToolsOpen.value,
        mobileExpandedDomainKey: mobileExpandedDomainKey.value,
        desktopExpandedDomainKeys: desktopExpandedDomainKeys.value,
    }))
}

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
        matches: ['/relationships', '/group-relationships', '/group-relationship-memberships', '/faction-memberships'],
        children: [
            { key: 'relationships', label: 'Relationships', href: route('relationships.index'), matches: ['/relationships'] },
            { key: 'group-relationships', label: 'Group Relationships', href: route('group-relationships.index'), matches: ['/group-relationships'] },
            { key: 'group-relationship-memberships', label: 'Group Memberships', href: route('group-relationship-memberships.index'), matches: ['/group-relationship-memberships'] },
            { key: 'faction-memberships', label: 'Faction Memberships', href: route('faction-memberships.index'), matches: ['/faction-memberships'] },
        ],
        icon: `<svg width="12" height="12" viewBox="0 0 16 16" fill="none"><circle cx="3" cy="8" r="2" stroke="currentColor" stroke-width="1.5"/><circle cx="13" cy="4" r="2" stroke="currentColor" stroke-width="1.5"/><circle cx="13" cy="12" r="2" stroke="currentColor" stroke-width="1.5"/><path d="M5 8l6-4M5 8l6 4" stroke="currentColor" stroke-width="1.5"/></svg>`
    },
    {
        key: 'temporal',
        label: 'Temporal',
        href: route('timelines.index'),
        matches: ['/timelines', '/timeline-placements', '/character-states', '/state-relationships', '/concurrency-groups'],
        children: [
            { key: 'timelines', label: 'Timelines', href: route('timelines.index'), matches: ['/timelines'] },
            { key: 'timeline-placements', label: 'Timeline Placements', href: route('timeline-placements.index'), matches: ['/timeline-placements'] },
            { key: 'character-states', label: 'Character States', href: route('character-states.index'), matches: ['/character-states'] },
            { key: 'state-relationships', label: 'State Relationships', href: route('state-relationships.index'), matches: ['/state-relationships'] },
            { key: 'concurrency-groups', label: 'Concurrency Groups', href: route('concurrency-groups.index'), matches: ['/concurrency-groups'] },
        ],
        icon: `<svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M2 8h12M8 2v12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="8" cy="8" r="2.5" stroke="currentColor" stroke-width="1.5"/></svg>`
    },
    {
        key: 'lore',
        label: 'Lore',
        href: route('documents.index'),
        matches: ['/documents', '/document-entities', '/canon-references', '/canon-reference-entities', '/crossover-entry-points'],
        children: [
            { key: 'documents', label: 'Documents', href: route('documents.index'), matches: ['/documents'] },
            { key: 'document-entities', label: 'Document Links', href: route('document-entities.index'), matches: ['/document-entities'] },
            { key: 'canon-references', label: 'Canon References', href: route('canon-references.index'), matches: ['/canon-references'] },
            { key: 'canon-reference-entities', label: 'Reference Links', href: route('canon-reference-entities.index'), matches: ['/canon-reference-entities'] },
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
        matches: ['/power-interactions', '/power-interaction-instances', '/travel-routes', '/location-containment', '/location-control', '/galactic-regions'],
        children: [
            { key: 'power-interactions', label: 'Power Interactions', href: route('power-interactions.index'), matches: ['/power-interactions'] },
            { key: 'power-interaction-instances', label: 'Instances', href: route('power-interaction-instances.index'), matches: ['/power-interaction-instances'] },
            { key: 'travel-routes', label: 'Travel Routes', href: route('travel-routes.index'), matches: ['/travel-routes'] },
            { key: 'location-containment', label: 'Containment', href: route('location-containment.index'), matches: ['/location-containment'] },
            { key: 'location-control', label: 'Control', href: route('location-control.index'), matches: ['/location-control'] },
            { key: 'galactic-regions', label: 'Galactic Regions', href: route('galactic-regions.index'), matches: ['/galactic-regions'] },
        ],
        icon: `<svg width="12" height="12" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.5"/><path d="M2 8h12M8 2a9 9 0 010 12M8 2a9 9 0 000 12" stroke="currentColor" stroke-width="1.5"/></svg>`
    },
    {
        key: 'organize',
        label: 'Organize',
        href: route('collections.index'),
        matches: ['/collections', '/collection-entities', '/collection-documents', '/glossary'],
        children: [
            { key: 'collections', label: 'Collections', href: route('collections.index'), matches: ['/collections'] },
            { key: 'collection-entities', label: 'Collection Entities', href: route('collection-entities.index'), matches: ['/collection-entities'] },
            { key: 'collection-documents', label: 'Collection Docs', href: route('collection-documents.index'), matches: ['/collection-documents'] },
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
    {
        key: 'admin',
        label: 'Admin',
        href: route('admin.revisions.index'),
        matches: ['/admin/revisions', '/admin/notion-notes', '/admin/notion-sync-mappings'],
        children: [
            { key: 'admin-revisions', label: 'Revisions', href: route('admin.revisions.index'), matches: ['/admin/revisions'] },
            { key: 'admin-notion-notes', label: 'Notion Notes', href: route('admin.notion-notes.index'), matches: ['/admin/notion-notes'] },
            { key: 'admin-notion-sync-mappings', label: 'Sync Mappings', href: route('admin.notion-sync-mappings.index'), matches: ['/admin/notion-sync-mappings'] },
        ],
        icon: `<svg width="12" height="12" viewBox="0 0 16 16" fill="none"><rect x="2" y="3" width="12" height="10" rx="1.5" stroke="currentColor" stroke-width="1.5"/><path d="M5 6.5h6M5 9h6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>`
    },
]

const bitcraftTools = {
    key: 'bitcraft-tools',
    label: 'Bitcraft Tools',
    matches: ['/bitcraft'],
    children: [
        { key: 'bitcraft-market', label: 'Market Finder', href: route('bitcraft.market'), matches: ['/bitcraft/market'] },
        { key: 'bitcraft-barter-stalls', label: 'Barter Stalls', href: route('bitcraft.barter-stalls'), matches: ['/bitcraft/barter-stalls'] },
        { key: 'bitcraft-crafting', label: 'Crafting Calculator', href: route('bitcraft.crafting'), matches: ['/bitcraft/crafting'] },
        { key: 'bitcraft-activity', label: 'Live Activity', href: route('bitcraft.activity', { source: 'default', setup: 1 }), matches: ['/bitcraft/activity'] },
        { key: 'bitcraft-inventory-tracker', label: 'Inventory Tracker', href: route('bitcraft.inventory-tracker', { source: 'default', setup: 1 }), matches: ['/bitcraft/inventory-tracker'] },
        { key: 'bitcraft-task-tracker', label: 'Task Tracker', href: route('bitcraft.task-tracker', { source: 'default', setup: 1 }), matches: ['/bitcraft/task-tracker'] },
    ],
}

const currentPath = computed(() => {
    const path = page.url.split('?')[0] || '/'
    const worldEnginePrefix = '/datacrypt/worldengine'

    if (path === worldEnginePrefix) {
        return '/'
    }

    if (path.startsWith(`${worldEnginePrefix}/`)) {
        return path.slice(worldEnginePrefix.length)
    }

    if (path === '/datacrypt') {
        return '/'
    }

    if (path.startsWith('/datacrypt/')) {
        return path.slice('/datacrypt'.length)
    }

    return path
})

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

const isWorldEngineActive = computed(() =>
    ['/', '/profile', '/search', '/trash'].some((match) => pathMatches(currentPath.value, match))
        || domains.some((domain) => isNavItemActive(domain))
)

const isBitcraftToolsActive = computed(() => isNavItemActive(bitcraftTools))

const activeShellLabel = computed(() => {
    if (isBitcraftToolsActive.value) {
        const activeTool = bitcraftTools.children.find((item) => isNavItemActive(item))

        return activeTool?.label ?? bitcraftTools.label
    }

    return activeDomain.value.label
})

const isDomainActive = (key) => activeDomain.value?.key === key
const isMobileDomainExpanded = (domain) => mobileExpandedDomainKey.value === domain.key
const isDesktopDomainExpanded = (domain) => desktopExpandedDomainKeys.value.includes(domain.key)

const toggleMobileDomain = (domain) => {
    mobileExpandedDomainKey.value = mobileExpandedDomainKey.value === domain.key ? null : domain.key
}

const toggleDesktopDomain = (domain) => {
    if (!domain.children?.length) {
        return
    }

    desktopExpandedDomainKeys.value = isDesktopDomainExpanded(domain)
        ? desktopExpandedDomainKeys.value.filter((key) => key !== domain.key)
        : [...desktopExpandedDomainKeys.value, domain.key]
}

watch([
    mobileWorldEngineOpen,
    desktopWorldEngineOpen,
    mobileBitcraftToolsOpen,
    desktopBitcraftToolsOpen,
    mobileExpandedDomainKey,
    desktopExpandedDomainKeys,
], saveNavState, { deep: true })

watch(
    () => page.url,
    () => {
        mobileNavOpen.value = false
    },
)
</script>
