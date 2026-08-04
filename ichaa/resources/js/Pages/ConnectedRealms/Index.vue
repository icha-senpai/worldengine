<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="page-hero">
                <div class="page-hero__copy">
                    <div class="page-hero__eyebrow">Datacrypt</div>
                    <h1 class="page-hero__title page-hero__title--md">Evergather</h1>
                </div>
            </div>
        </template>

        <nav class="mb-5 border-y border-border/70 py-3" aria-label="Evergather command board">
            <div class="grid gap-3 xl:grid-cols-[minmax(0,1fr)_minmax(18rem,22rem)] xl:items-start">
                <div class="min-w-0 space-y-3">
                    <div class="flex min-w-0 gap-2 overflow-x-auto pb-1" aria-label="Evergather workspaces">
                        <button
                            v-for="tab in workspaceTabs"
                            :key="tab.key"
                            type="button"
                            class="tag shrink-0 transition hover:border-focus/60"
                            :class="{ 'border-focus/70 bg-focus/10 text-primary': activePanel === tab.key }"
                            :aria-pressed="activePanel === tab.key"
                            @click="selectWorkspaceTab(tab.key)"
                        >
                            {{ tab.label }} · {{ tab.count }}
                        </button>
                    </div>

                    <div class="flex min-w-0 gap-2 overflow-x-auto pb-1" aria-label="Evergather sections">
                        <button
                            v-for="tab in activeWorkspaceSubTabs"
                            :key="tab.key"
                            type="button"
                            class="shrink-0 rounded-md border border-border bg-surface-2 px-3 py-2 text-xs font-ui text-muted-2 transition hover:border-focus/60 hover:text-primary"
                            :class="{ 'border-focus/70 bg-focus/10 text-primary': activeSubPanel === tab.key }"
                            :aria-pressed="activeSubPanel === tab.key"
                            @focus="warmSubPanel(tab.key)"
                            @pointerenter="warmSubPanel(tab.key)"
                            @click="selectSubPanel(tab.key)"
                        >
                            {{ tab.label }} · {{ tab.count }}
                        </button>
                    </div>
                </div>

                <div class="grid min-w-0 gap-2 sm:grid-cols-[minmax(0,1fr)_auto]">
                    <label class="relative min-w-0">
                        <span class="sr-only">Search Evergather</span>
                        <input
                            v-model="searchQuery"
                            type="search"
                            class="w-full rounded-md border-border bg-surface-2 text-sm text-primary focus:border-focus focus:ring-focus"
                            placeholder="Search Evergather..."
                        >
                    </label>

                    <button
                        type="button"
                        class="app-btn app-btn--primary app-btn--sm"
                        :disabled="repeatProcessing || !last_result"
                        @click="repeatLast"
                    >
                        {{ repeatButtonLabel }}
                    </button>
                </div>
            </div>
        </nav>

        <div
            v-if="repeatDialog.open"
            class="fixed inset-0 z-50 grid place-items-center bg-black/60 px-4 py-6"
            role="dialog"
            aria-modal="true"
            aria-labelledby="repeat-dialog-title"
        >
            <div class="w-full max-w-md rounded-md border border-border bg-surface px-4 py-4 shadow-xl">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p id="repeat-dialog-title" class="text-sm font-ui text-primary">{{ repeatDialog.title }}</p>
                        <p class="mt-2 text-sm text-muted-2">{{ repeatDialog.message }}</p>
                    </div>
                    <button
                        type="button"
                        class="rounded-md border border-border bg-surface-2 px-2 py-1 text-xs font-ui text-muted-2 transition hover:border-focus/60 hover:text-primary"
                        @click="closeRepeatDialog"
                    >
                        Close
                    </button>
                </div>

                <div v-if="repeatDialog.details.length" class="mt-4 grid gap-2">
                    <div
                        v-for="detail in repeatDialog.details"
                        :key="detail"
                        class="rounded-md border border-border bg-canvas px-3 py-2 text-xs text-muted-2"
                    >
                        {{ detail }}
                    </div>
                </div>
            </div>
        </div>

        <div class="grid gap-5 xl:grid-cols-[minmax(0,1.25fr)_minmax(24rem,0.75fr)]">
            <section v-if="activePanel === 'overview' && activeSubPanel === 'character'" class="surface-section xl:col-span-2">
                <div class="surface-section__header">
                    <div class="surface-section__copy">
                        <span class="surface-section__title">{{ player.display_name }}</span>
                        <p class="surface-section__subtitle">
                            {{ player.title || player.species_label }} · {{ player.home_region_label }}
                        </p>
                    </div>

                    <span class="tag" :class="{ 'tag--success': player.can_act_now }">
                        {{ player.can_act_now ? 'Ready' : 'Cooling down' }}
                    </span>
                </div>

                <div class="surface-section__body">
                    <div class="grid gap-4 lg:grid-cols-[14rem_minmax(0,1fr)]">
                        <div class="rounded-md border border-border bg-surface-2 p-4">
                            <div
                                class="relative mx-auto grid aspect-square w-full max-w-44 place-items-center rounded-md border"
                                :class="avatarPaletteClass"
                            >
                                <div class="grid place-items-center gap-2">
                                    <div class="h-16 w-16 rounded-full border-2 border-current bg-surface/50" />
                                    <div class="h-16 w-24 rounded-t-full border-2 border-current bg-surface/40" />
                                </div>
                            </div>

                            <div v-if="player.reward_loadout?.title_label" class="mt-4 flex flex-wrap justify-center gap-2">
                                <span class="tag">{{ player.reward_loadout.title_label }}</span>
                            </div>

                            <div class="mt-4 grid gap-2 text-xs text-muted-2">
                                <div class="flex items-center justify-between gap-3">
                                    <span>Body</span>
                                    <span class="text-primary">{{ appearanceLabel('body_style', player.appearance.body_style) }}</span>
                                </div>
                                <div class="flex items-center justify-between gap-3">
                                    <span>Hair</span>
                                    <span class="text-primary">{{ appearanceLabel('hair_style', player.appearance.hair_style) }}</span>
                                </div>
                                <div class="flex items-center justify-between gap-3">
                                    <span>Outfit</span>
                                    <span class="text-primary">{{ appearanceLabel('outfit', player.appearance.outfit) }}</span>
                                </div>
                            </div>
                        </div>

                        <form class="grid gap-4" @submit.prevent="submitCharacter">
                            <div class="grid gap-3 sm:grid-cols-2">
                                <label class="grid gap-1">
                                    <span class="text-xs font-ui uppercase tracking-[0.14em] text-muted-3">Name</span>
                                    <input
                                        v-model="characterForm.display_name"
                                        type="text"
                                        class="rounded-md border-border bg-surface-2 text-sm text-primary focus:border-focus focus:ring-focus"
                                    >
                                    <span v-if="characterForm.errors.display_name" class="text-xs text-(--accent-pink)">
                                        {{ characterForm.errors.display_name }}
                                    </span>
                                </label>

                                <label class="grid gap-1">
                                    <span class="text-xs font-ui uppercase tracking-[0.14em] text-muted-3">Title</span>
                                    <input
                                        v-model="characterForm.title"
                                        type="text"
                                        class="rounded-md border-border bg-surface-2 text-sm text-primary focus:border-focus focus:ring-focus"
                                    >
                                    <span v-if="characterForm.errors.title" class="text-xs text-(--accent-pink)">
                                        {{ characterForm.errors.title }}
                                    </span>
                                </label>

                                <label class="grid gap-1">
                                    <span class="text-xs font-ui uppercase tracking-[0.14em] text-muted-3">Species</span>
                                    <select
                                        v-model="characterForm.species"
                                        class="rounded-md border-border bg-surface-2 text-sm text-primary focus:border-focus focus:ring-focus"
                                    >
                                        <option
                                            v-for="option in character_options.species"
                                            :key="option.key"
                                            :value="option.key"
                                        >
                                            {{ option.label }}
                                        </option>
                                    </select>
                                    <span v-if="characterForm.errors.species" class="text-xs text-(--accent-pink)">
                                        {{ characterForm.errors.species }}
                                    </span>
                                </label>

                                <label class="grid gap-1">
                                    <span class="text-xs font-ui uppercase tracking-[0.14em] text-muted-3">Pronouns</span>
                                    <input
                                        v-model="characterForm.pronouns"
                                        type="text"
                                        class="rounded-md border-border bg-surface-2 text-sm text-primary focus:border-focus focus:ring-focus"
                                    >
                                    <span v-if="characterForm.errors.pronouns" class="text-xs text-(--accent-pink)">
                                        {{ characterForm.errors.pronouns }}
                                    </span>
                                </label>

                                <label class="grid gap-1 sm:col-span-2">
                                    <span class="text-xs font-ui uppercase tracking-[0.14em] text-muted-3">Home Region</span>
                                    <select
                                        v-model="characterForm.home_region"
                                        class="rounded-md border-border bg-surface-2 text-sm text-primary focus:border-focus focus:ring-focus"
                                    >
                                        <option
                                            v-for="option in character_options.home_regions"
                                            :key="option.key"
                                            :value="option.key"
                                        >
                                            {{ option.label }}
                                        </option>
                                    </select>
                                    <span v-if="characterForm.errors.home_region" class="text-xs text-(--accent-pink)">
                                        {{ characterForm.errors.home_region }}
                                    </span>
                                </label>
                            </div>

                            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                <label
                                    v-for="field in appearanceFields"
                                    :key="field.key"
                                    class="grid gap-1"
                                >
                                    <span class="text-xs font-ui uppercase tracking-[0.14em] text-muted-3">{{ field.label }}</span>
                                    <select
                                        v-model="characterForm.appearance[field.key]"
                                        class="rounded-md border-border bg-surface-2 text-sm text-primary focus:border-focus focus:ring-focus"
                                    >
                                        <option
                                            v-for="option in character_options.appearance[field.key]"
                                            :key="option.key"
                                            :value="option.key"
                                        >
                                            {{ option.label }}
                                        </option>
                                    </select>
                                </label>
                            </div>

                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div class="flex flex-wrap gap-2">
                                    <span class="tag">{{ player.gold }} gold</span>
                                    <span class="tag">Level {{ summary.account_level }}</span>
                                    <span class="tag">{{ summary.total_experience }} XP</span>
                                    <span class="tag">{{ summary.inventory_quantity }} items</span>
                                    <span class="tag">{{ summary.inventory_weight }} wt</span>
                                </div>

                                <button
                                    type="submit"
                                    class="app-btn app-btn--primary"
                                    :disabled="characterForm.processing"
                                >
                                    Save Character
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </section>

            <ProgressionPanel v-if="activePanel === 'overview' && activeSubPanel === 'progression'" class="xl:col-span-2" :progression="progression" :summary="summary" />

            <LatestResultPanel v-if="last_result && activeSubPanel === 'result'" class="xl:col-span-2" :result="last_result" />

            <GatheringPanel v-if="activePanel === 'gather' && activeSubPanel === 'actions'" class="xl:col-span-2" :actions="actions" :player="player" :search-term="searchQuery" />

            <SkillActivitiesPanel v-if="activePanel === 'gather' && activeSubPanel === 'activities'" class="xl:col-span-2" :activities="skill_activities" :player="player" :search-term="searchQuery" />

            <EquipmentPanel v-if="activePanel === 'craft' && activeSubPanel === 'equipment'" class="xl:col-span-2" :equipment="equipment" :tool-inventory="tool_inventory" :tool-rarity-upgrades="tool_rarity_upgrades" :tool-tier-upgrades="tool_tier_upgrades" />

            <CraftingPanel v-if="activePanel === 'craft' && activeSubPanel === 'recipes'" class="xl:col-span-2" :recipes="crafting_recipes" :search-term="searchQuery" />

            <JobsPanel v-if="activePanel === 'craft' && activeSubPanel === 'jobs'" class="xl:col-span-2" :jobs="jobs" :search-term="searchQuery" />

            <ExpeditionsPanel v-if="activePanel === 'craft' && activeSubPanel === 'expeditions'" class="xl:col-span-2" :expeditions="expeditions" :search-term="searchQuery" />

            <MarketplacePanel v-if="activePanel === 'trade' && activeSubPanel === 'marketplace'" class="xl:col-span-2" :marketplace="marketplace" :search-term="searchQuery" />

            <ShopPanel v-if="activePanel === 'trade' && activeSubPanel === 'shop'" class="xl:col-span-2" :shop="shop" :search-term="searchQuery" />

            <Deferred v-if="activePanel === 'progress' && activeSubPanel === 'events'" data="world_events">
                <WorldEventsPanel class="xl:col-span-2" :world-events="world_events" />

                <template #fallback>
                    <DeferredPanelSkeleton title="World Events" subtitle="Warming the event calendar..." />
                </template>
            </Deferred>

            <Deferred v-if="activePanel === 'progress' && activeSubPanel === 'leaderboards'" data="leaderboards">
                <LeaderboardPanel class="xl:col-span-2" :leaderboards="leaderboards" />

                <template #fallback>
                    <DeferredPanelSkeleton title="Ranks" subtitle="Pulling the leaderboard tables..." />
                </template>
            </Deferred>

            <SkillsPanel v-if="activePanel === 'progress' && activeSubPanel === 'skills'" class="xl:col-span-2" :skills="skills" :catalog="skill_catalog" :search-term="searchQuery" />

            <Deferred v-if="activePanel === 'trade' && activeSubPanel === 'inventory'" data="item_guide">
                <section class="surface-section xl:col-span-2">
                    <div class="surface-section__header">
                        <div class="surface-section__copy">
                            <span class="surface-section__title">Inventory Guide</span>
                            <p class="surface-section__subtitle">{{ item_guide.summary.tracked_items }} tracked items · {{ item_guide.summary.items_with_sinks }} with known uses.</p>
                        </div>
                    </div>

                    <div class="surface-section__body">
                        <div class="grid gap-4 xl:grid-cols-[14rem_minmax(0,1fr)_minmax(18rem,0.8fr)]">
                            <div class="rounded-md border border-border bg-surface-2 px-3 py-3">
                            <p class="text-sm font-ui text-primary">Item Index</p>
                            <div class="mt-3 flex flex-wrap gap-2 xl:grid">
                                <button
                                    v-for="category in itemGuideCategories"
                                    :key="category.key"
                                    type="button"
                                    class="rounded-md border border-border bg-canvas px-3 py-2 text-left text-xs font-ui text-muted-2 transition hover:border-focus/60 hover:text-primary"
                                    :class="{ 'border-focus/70 bg-focus/10 text-primary': selectedInventoryCategory === category.key }"
                                    @click="selectedInventoryCategory = category.key"
                                >
                                    {{ category.label }} · {{ category.count }}
                                </button>
                            </div>
                            <div class="mt-4 grid gap-2 text-xs">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-muted-2">Visible</span>
                                    <span class="text-primary">{{ visibleInventory.length }}</span>
                                </div>
                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-muted-2">Weight</span>
                                    <span class="text-primary">{{ visibleInventoryWeight }} wt</span>
                                </div>
                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-muted-2">Value</span>
                                    <span class="text-primary">{{ visibleInventoryValue }}g</span>
                                </div>
                            </div>
                        </div>

                        <div v-if="visibleInventory.length" class="grid max-h-[42rem] gap-2 overflow-y-auto pr-1">
                            <button
                                v-for="(item, index) in visibleInventory"
                                :key="item.item_key"
                                type="button"
                                class="grid gap-3 rounded-md border border-border bg-surface-2 px-3 py-3 text-left transition hover:border-focus/60 md:grid-cols-[3rem_minmax(0,1fr)_auto]"
                                :class="{ 'border-focus/70 bg-focus/10': selectedInventoryItem?.item_key === item.item_key }"
                                @click="selectedInventoryKey = item.item_key"
                            >
                                <div class="grid h-9 w-9 place-items-center rounded-md border border-border bg-canvas text-sm font-ui text-primary">
                                    #{{ index + 1 }}
                                </div>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="min-w-0 truncate text-sm font-ui text-primary">{{ item.item_name }}</p>
                                        <span class="tag capitalize">{{ item.rarity }}</span>
                                        <span class="tag capitalize">{{ item.quality }}</span>
                                    </div>
                                    <p class="mt-1 text-xs text-muted-2">
                                        {{ item.item_class }} · {{ item.material_family }} · {{ item.market_price_band }}
                                    </p>
                                </div>
                                <div class="text-left md:text-right">
                                    <p class="text-sm font-ui text-primary">x{{ item.owned_quantity }}</p>
                                    <p class="mt-1 text-xs text-muted-3">{{ item.weight }} wt ea · {{ item.total_weight }} wt</p>
                                </div>
                            </button>
                        </div>

                        <p v-else class="rounded-md border border-border bg-surface-2 px-3 py-3 text-sm text-muted-2">
                            No inventory matches.
                        </p>

                        <aside v-if="selectedInventoryItem" class="rounded-md border border-border bg-surface-2 px-3 py-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm font-ui text-primary">{{ selectedInventoryItem.item_name }}</p>
                                <span class="tag capitalize">{{ selectedInventoryItem.rarity }}</span>
                                <span class="tag capitalize">{{ selectedInventoryItem.quality }}</span>
                            </div>

                            <div class="mt-3 grid gap-2 text-xs">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-muted-2">Owned</span>
                                    <span class="text-primary">{{ selectedInventoryItem.owned_quantity }}</span>
                                </div>
                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-muted-2">NPC floor</span>
                                    <span class="text-primary">{{ selectedInventoryItem.npc_buy_price }}g</span>
                                </div>
                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-muted-2">Market band</span>
                                    <span class="text-primary">{{ selectedInventoryItem.market_price_band }}</span>
                                </div>
                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-muted-2">Weight</span>
                                    <span class="text-primary">{{ selectedInventoryItem.weight }} wt</span>
                                </div>
                            </div>

                            <div class="mt-4 rounded-md border border-border bg-canvas px-3 py-3">
                                <p class="text-xs font-ui uppercase tracking-[0.14em] text-muted-3">Best Source</p>
                                <p v-if="selectedInventoryItem.best_source" class="mt-2 text-sm text-primary">{{ selectedInventoryItem.best_source.label }}</p>
                                <p v-if="selectedInventoryItem.best_source" class="mt-1 text-xs text-muted-2">
                                    {{ selectedInventoryItem.best_source.type }} · {{ selectedInventoryItem.best_source.context }} · Lv {{ selectedInventoryItem.best_source.required_level }}
                                </p>
                                <p v-else class="mt-2 text-xs text-muted-2">No source mapped yet.</p>
                            </div>

                            <div class="mt-3 rounded-md border border-border bg-canvas px-3 py-3">
                                <p class="text-xs font-ui uppercase tracking-[0.14em] text-muted-3">Best Use</p>
                                <p v-if="selectedInventoryItem.best_sink" class="mt-2 text-sm text-primary">{{ selectedInventoryItem.best_sink.label }}</p>
                                <p v-if="selectedInventoryItem.best_sink" class="mt-1 text-xs text-muted-2">
                                    {{ selectedInventoryItem.best_sink.type }} · {{ selectedInventoryItem.best_sink.context }} · Lv {{ selectedInventoryItem.best_sink.required_level }}
                                </p>
                                <p v-if="selectedInventoryItem.purpose" class="mt-2 text-xs text-muted-2">
                                    {{ selectedInventoryItem.purpose }}
                                </p>
                                <p v-else class="mt-2 text-xs text-muted-2">No sink mapped yet.</p>
                            </div>

                            <div class="mt-3 grid gap-2">
                                <p class="text-xs font-ui uppercase tracking-[0.14em] text-muted-3">Known Paths</p>
                                <div class="flex flex-wrap gap-2">
                                    <span
                                        v-for="source in selectedInventoryItem.sources.slice(0, 4)"
                                        :key="`source-${source.type}-${source.label}`"
                                        class="tag"
                                    >
                                        {{ source.type }}
                                    </span>
                                    <span
                                        v-for="sink in selectedInventoryItem.sinks.slice(0, 4)"
                                        :key="`sink-${sink.type}-${sink.label}`"
                                        class="tag"
                                    >
                                        {{ sink.type }}
                                    </span>
                                </div>
                            </div>
                            </aside>
                        </div>
                    </div>
                </section>

                <template #fallback>
                    <DeferredPanelSkeleton title="Inventory Guide" subtitle="Building item sources, uses, and market paths..." />
                </template>
            </Deferred>

            <section v-if="activePanel === 'progress' && activeSubPanel === 'recent'" class="surface-section xl:col-span-2">
                <div class="surface-section__header">
                    <div class="surface-section__copy">
                        <span class="surface-section__title">Recent Actions</span>
                        <p class="surface-section__subtitle">{{ summary.action_count }} logged actions.</p>
                    </div>
                </div>

                <div class="surface-section__body">
                    <div class="grid gap-4 xl:grid-cols-[16rem_minmax(0,1fr)]">
                        <div class="rounded-md border border-border bg-surface-2 px-3 py-3">
                            <p class="text-sm font-ui text-primary">Action Ledger</p>
                            <div class="mt-3 grid gap-2 text-xs">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-muted-2">Visible</span>
                                    <span class="text-primary">{{ recent_actions.length }}</span>
                                </div>
                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-muted-2">XP</span>
                                    <span class="text-primary">{{ recentActionExperience }}</span>
                                </div>
                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-muted-2">Gold</span>
                                    <span class="text-primary">{{ recentActionGold }}</span>
                                </div>
                            </div>
                        </div>

                        <div v-if="recent_actions.length" class="grid gap-3">
                            <article
                                v-for="(entry, index) in recent_actions"
                                :key="entry.id"
                                class="grid gap-3 rounded-md border border-border bg-surface-2 px-3 py-3 md:grid-cols-[3rem_minmax(0,1fr)_auto]"
                            >
                                <div class="grid h-9 w-9 place-items-center rounded-md border border-border bg-canvas text-sm font-ui text-primary">
                                    #{{ index + 1 }}
                                </div>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="min-w-0 truncate text-sm font-ui text-primary">{{ actionLabel(entry.action) }}</p>
                                        <span class="tag">{{ entry.platform }}</span>
                                        <span v-if="entry.event_label" class="tag tag--success">{{ entry.event_label }}</span>
                                    </div>
                                    <p v-if="entry.tool_item_name" class="mt-1 text-xs text-muted-3">
                                        {{ entry.tool_item_name }}
                                    </p>
                                </div>
                                <div class="text-left md:text-right">
                                    <p class="text-sm font-ui text-primary">+{{ entry.experience_awarded }} XP</p>
                                    <p class="mt-1 text-xs text-muted-2">+{{ entry.gold_awarded }} gold</p>
                                </div>
                            </article>
                        </div>

                        <p v-else class="rounded-md border border-border bg-surface-2 px-3 py-3 text-sm text-muted-2">
                            No actions yet.
                        </p>
                    </div>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { Deferred, router, useForm, usePage } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import CraftingPanel from './CraftingPanel.vue'
import DeferredPanelSkeleton from './DeferredPanelSkeleton.vue'
import EquipmentPanel from './EquipmentPanel.vue'
import ExpeditionsPanel from './ExpeditionsPanel.vue'
import GatheringPanel from './GatheringPanel.vue'
import JobsPanel from './JobsPanel.vue'
import LeaderboardPanel from './LeaderboardPanel.vue'
import LatestResultPanel from './LatestResultPanel.vue'
import MarketplacePanel from './MarketplacePanel.vue'
import ProgressionPanel from './ProgressionPanel.vue'
import ShopPanel from './ShopPanel.vue'
import SkillActivitiesPanel from './SkillActivitiesPanel.vue'
import SkillsPanel from './SkillsPanel.vue'
import WorldEventsPanel from './WorldEventsPanel.vue'
import {
    actionReloadProps,
    activityReloadProps,
    characterReloadProps,
    craftingReloadProps,
    equipmentReloadProps,
    expeditionReloadProps,
    jobReloadProps,
    marketplaceReloadProps,
    shopReloadProps,
} from './reloadProps'

const props = defineProps({
    player: {
        type: Object,
        required: true,
    },
    character_options: {
        type: Object,
        required: true,
    },
    actions: {
        type: Array,
        required: true,
    },
    skill_activities: {
        type: Array,
        required: true,
    },
    skills: {
        type: Array,
        required: true,
    },
    skill_catalog: {
        type: Object,
        required: true,
    },
    item_catalog: {
        type: Object,
        required: true,
    },
    item_guide: {
        type: Object,
        default: () => ({
            summary: {
                tracked_items: 0,
                owned_items: 0,
                items_with_sinks: 0,
            },
            categories: [],
            items: [],
        }),
    },
    inventory: {
        type: Array,
        required: true,
    },
    equipment: {
        type: Array,
        required: true,
    },
    tool_inventory: {
        type: Array,
        required: true,
    },
    tool_rarity_upgrades: {
        type: Object,
        required: true,
    },
    tool_tier_upgrades: {
        type: Object,
        required: true,
    },
    crafting_recipes: {
        type: Array,
        required: true,
    },
    jobs: {
        type: Array,
        required: true,
    },
    expeditions: {
        type: Array,
        required: true,
    },
    marketplace: {
        type: Object,
        required: true,
    },
    shop: {
        type: Object,
        required: true,
    },
    progression: {
        type: Object,
        required: true,
    },
    world_events: {
        type: Object,
        default: () => ({
            active: [],
            upcoming: [],
            categories: [],
        }),
    },
    recent_actions: {
        type: Array,
        required: true,
    },
    summary: {
        type: Object,
        required: true,
    },
    leaderboards: {
        type: Object,
        default: () => ({
            groups: [],
            boards: [],
            wealth: [],
            realm_score: [],
            skills: [],
        }),
    },
    last_result: {
        type: Object,
        default: null,
    },
})

const appearanceFields = [
    { key: 'body_style', label: 'Body' },
    { key: 'palette', label: 'Palette' },
    { key: 'hair_style', label: 'Hair' },
    { key: 'outfit', label: 'Outfit' },
]

const navigationStorageKey = 'evergather.navigation-state'
const defaultActivePanel = 'gather'
const defaultActiveSubPanels = {
    overview: 'character',
    gather: 'actions',
    craft: 'recipes',
    trade: 'marketplace',
    progress: 'skills',
}
const deferredPropsBySubPanel = {
    inventory: ['item_guide'],
    events: ['world_events'],
    leaderboards: ['leaderboards'],
}
const savedNavigationState = readSavedNavigationState()
const page = usePage()
const warmingDeferredProps = new Set()

const activePanel = ref(savedNavigationState.activePanel)
const activeSubPanels = ref(savedNavigationState.activeSubPanels)
const searchQuery = ref(savedNavigationState.searchQuery)
const selectedInventoryCategory = ref('all')
const selectedInventoryKey = ref('')
const repeatProcessing = ref(false)
const repeatDialog = ref({
    open: false,
    title: '',
    message: '',
    details: [],
})

const characterForm = useForm({
    display_name: props.player.display_name,
    title: props.player.title ?? '',
    species: props.player.species,
    pronouns: props.player.pronouns ?? '',
    home_region: props.player.home_region,
    appearance: { ...props.player.appearance },
})

const actionLabels = computed(() => Object.fromEntries(
    [...props.actions, ...props.skill_activities].map((action) => [action.key, action.label]),
))

const workspaceTabs = computed(() => [
    { key: 'overview', label: 'Overview', count: props.player.can_act_now ? 'Ready' : 'Wait' },
    { key: 'gather', label: 'Gather', count: props.actions.length + props.skill_activities.length },
    { key: 'craft', label: 'Craft', count: props.crafting_recipes.length + props.jobs.length + props.expeditions.length },
    { key: 'trade', label: 'Trade', count: props.shop.offers.length + props.marketplace.active_listings.length },
    { key: 'progress', label: 'Progress', count: props.skills.length },
])

const workspaceSubTabs = computed(() => ({
    overview: [
        { key: 'character', label: 'Character', count: props.player.gold },
        { key: 'progression', label: 'Progression', count: props.summary.account_level },
        ...resultSubTab(),
    ],
    gather: [
        { key: 'actions', label: 'Actions', count: props.actions.length },
        { key: 'activities', label: 'Activities', count: props.skill_activities.length },
        ...resultSubTab(),
    ],
    craft: [
        { key: 'equipment', label: 'Equipment', count: props.equipment.length },
        { key: 'recipes', label: 'Recipes', count: props.crafting_recipes.length },
        { key: 'jobs', label: 'Jobs', count: props.jobs.length },
        { key: 'expeditions', label: 'Expeditions', count: props.expeditions.length },
        ...resultSubTab(),
    ],
    trade: [
        { key: 'marketplace', label: 'Marketplace', count: props.marketplace.active_listings.length },
        { key: 'shop', label: 'Shop', count: props.shop.offers.length },
        { key: 'inventory', label: 'Inventory', count: props.summary.inventory_quantity },
        ...resultSubTab(),
    ],
    progress: [
        { key: 'skills', label: 'Skills', count: props.skills.length },
        { key: 'events', label: 'World Events', count: deferredCount('world_events', props.world_events.active.length + props.world_events.upcoming.length) },
        { key: 'leaderboards', label: 'Ranks', count: deferredCount('leaderboards', props.leaderboards.groups.reduce((total, group) => total + group.count, 0)) },
        { key: 'recent', label: 'Recent', count: props.summary.action_count },
    ],
}))

const activeWorkspaceSubTabs = computed(() => workspaceSubTabs.value[activePanel.value] ?? [])
const activeSubPanel = computed(() => {
    const savedPanel = activeSubPanels.value[activePanel.value]

    return activeWorkspaceSubTabs.value.some((tab) => tab.key === savedPanel)
        ? savedPanel
        : activeWorkspaceSubTabs.value[0]?.key
})
const itemGuideCategories = computed(() => [
    { key: 'all', label: 'All Items', count: props.item_guide.summary.tracked_items },
    { key: 'owned', label: 'Owned', count: props.item_guide.summary.owned_items },
    { key: 'uses', label: 'Has Uses', count: props.item_guide.summary.items_with_sinks },
    ...(props.item_guide.categories ?? []),
])
const visibleInventory = computed(() => (props.item_guide.items ?? []).filter((item) => {
    const matchesCategory = selectedInventoryCategory.value === 'all'
        || (selectedInventoryCategory.value === 'owned' && item.owned_quantity > 0)
        || (selectedInventoryCategory.value === 'uses' && item.has_use)
        || item.item_class === selectedInventoryCategory.value

    return matchesCategory && searchMatches(item, searchQuery.value)
}))
const selectedInventoryItem = computed(() => visibleInventory.value.find((item) => item.item_key === selectedInventoryKey.value) ?? visibleInventory.value[0] ?? null)
const visibleInventoryWeight = computed(() => Math.round(visibleInventory.value.reduce((total, item) => total + item.total_weight, 0) * 100) / 100)
const visibleInventoryValue = computed(() => visibleInventory.value.reduce((total, item) => total + item.total_vendor_value, 0))
const recentActionExperience = computed(() => props.recent_actions.reduce((total, entry) => total + entry.experience_awarded, 0))
const recentActionGold = computed(() => props.recent_actions.reduce((total, entry) => total + entry.gold_awarded, 0))
const repeatCommand = computed(() => repeatCommandFor(props.last_result))
const repeatButtonLabel = computed(() => {
    if (repeatProcessing.value) {
        return 'Repeating...'
    }

    return props.last_result ? 'Repeat Last' : 'No Last'
})

const avatarPaletteClass = computed(() => ({
    moonlit: 'text-focus bg-focus/10',
    ember: 'text-(--accent-pink) bg-[rgb(var(--accent-pink-rgb)/0.1)]',
    verdant: 'text-success bg-success/10',
    tideglass: 'text-focus bg-focus/10',
}[props.player.appearance.palette] ?? 'text-focus bg-focus/10'))

watch([activePanel, activeSubPanels, searchQuery], persistNavigationState, {
    deep: true,
})

function actionLabel(action) {
    return actionLabels.value[action] ?? action
}

function appearanceLabel(field, value) {
    return props.character_options.appearance[field]?.find((option) => option.key === value)?.label ?? value
}

function submitCharacter() {
    characterForm.put(route('evergather.character.update'), {
        preserveScroll: true,
        only: characterReloadProps,
    })
}

function repeatLast() {
    const command = repeatCommand.value

    if (!props.last_result) {
        openRepeatDialog('Nothing to repeat', 'Complete an Evergather action first, then this command can replay it.')

        return
    }

    if (!command?.repeatable) {
        openRepeatDialog('Cannot repeat that', command?.message ?? 'That result needs a fresh choice before it can run again.')

        return
    }

    router.visit(command.url, {
        method: command.method,
        data: command.data,
        preserveScroll: true,
        only: command.only,
        onStart: () => {
            repeatProcessing.value = true
        },
        onError: (errors) => {
            const details = repeatErrorDetails(errors)

            openRepeatDialog(
                'Repeat stopped',
                repeatErrorMessage(props.last_result),
                details.length ? details : ['The game could not repeat that action with the resources you have right now.'],
            )
        },
        onSuccess: () => {
            closeRepeatDialog()
        },
        onFinish: () => {
            repeatProcessing.value = false
        },
    })
}

function repeatCommandFor(result) {
    if (!result) {
        return null
    }

    if (result.action && result.type !== 'skill_activity') {
        return repeatRequest('post', 'evergather.actions.store', { action: result.action }, actionReloadProps)
    }

    switch (result.type) {
        case 'skill_activity':
            return repeatRequest('post', 'evergather.activities.store', { activity: result.activity }, activityReloadProps)
        case 'crafting':
            return repeatRequest('post', 'evergather.crafting.store', { recipe: result.recipe_key }, craftingReloadProps)
        case 'job':
            return repeatRequest('post', 'evergather.jobs.store', { job: result.job_key }, jobReloadProps)
        case 'expedition':
            return repeatRequest('post', 'evergather.expeditions.store', { expedition: result.expedition_key }, expeditionReloadProps)
        case 'shop':
            return repeatRequest('post', 'evergather.shop.purchases.store', { offer: result.offer_key }, shopReloadProps)
        case 'tool_rarity_upgrade':
            return repeatRequest('post', 'evergather.tools.rarity-upgrades.store', { slot: result.slot }, equipmentReloadProps)
        case 'tool_tier_upgrade':
            return repeatRequest('post', 'evergather.tools.tier-upgrades.store', { slot: result.slot }, equipmentReloadProps)
        case 'market_listing':
            return repeatMarketListingCommand(result)
        case 'npc_sale':
            return repeatRequest('post', 'evergather.marketplace.vendor-sales.store', {
                item_key: result.item_key,
                quantity: result.quantity,
            }, marketplaceReloadProps)
        case 'market_purchase':
            return nonRepeatable('Market purchases depend on a live listing. Pick the listing again from Marketplace so the price and seller are current.')
        case 'market_cancel':
            return nonRepeatable('Cancelled listings are already resolved. Create a fresh listing from Marketplace if you want to sell it again.')
        case 'tool_equip':
        case 'tool_unequip':
            return nonRepeatable('Tool equipment changes depend on a specific stored tool. Choose the tool again from Equipment.')
        case 'achievement_claim':
        case 'reward_loadout':
            return nonRepeatable('Progress rewards and loadouts are one-time choices, so they need a fresh selection.')
        default:
            return nonRepeatable('That result does not carry enough action data to repeat safely.')
    }
}

function repeatMarketListingCommand(result) {
    const listingType = result.listing_type ?? 'item'

    if (listingType === 'tool') {
        return nonRepeatable('Tool listings move a unique tool out of storage. Pick the next tool from Marketplace so the right one is listed.')
    }

    return repeatRequest('post', 'evergather.marketplace.listings.store', {
        listing_type: 'item',
        item_key: result.item_key,
        tool_id: null,
        quantity: result.quantity,
        unit_price: result.unit_price,
    }, marketplaceReloadProps)
}

function repeatRequest(method, routeName, data, only) {
    return {
        repeatable: true,
        method,
        url: route(routeName),
        data,
        only,
    }
}

function nonRepeatable(message) {
    return {
        repeatable: false,
        message,
    }
}

function repeatErrorMessage(result) {
    const label = result?.label ?? result?.item_name ?? 'That action'

    return `${label} could not be repeated. You may be out of materials, gold, cooldown time, or the target may no longer be available.`
}

function repeatErrorDetails(errors) {
    return Object.values(errors ?? {})
        .flatMap((error) => Array.isArray(error) ? error : [error])
        .flatMap((error) => typeof error === 'object' && error !== null ? Object.values(error) : [error])
        .flatMap((error) => Array.isArray(error) ? error : [error])
        .map((error) => String(error))
        .filter(Boolean)
}

function openRepeatDialog(title, message, details = []) {
    repeatDialog.value = {
        open: true,
        title,
        message,
        details,
    }
}

function closeRepeatDialog() {
    repeatDialog.value = {
        open: false,
        title: '',
        message: '',
        details: [],
    }
}

function selectWorkspaceTab(panel) {
    activePanel.value = panel

    if (!activeWorkspaceSubTabs.value.some((tab) => tab.key === activeSubPanel.value)) {
        selectSubPanel(activeWorkspaceSubTabs.value[0]?.key)
    }
}

function selectSubPanel(panel) {
    if (!panel) {
        return
    }

    warmSubPanel(panel)

    activeSubPanels.value = {
        ...activeSubPanels.value,
        [activePanel.value]: panel,
    }
}

function deferredCount(prop, count) {
    return deferredPropLoaded(prop) ? count : 'Load'
}

function deferredPropLoaded(prop) {
    return page.props[prop] !== undefined
}

function warmSubPanel(panel) {
    const propsToLoad = (deferredPropsBySubPanel[panel] ?? [])
        .filter((prop) => !deferredPropLoaded(prop) && !warmingDeferredProps.has(prop))

    if (!propsToLoad.length) {
        return
    }

    propsToLoad.forEach((prop) => warmingDeferredProps.add(prop))

    router.reload({
        only: propsToLoad,
        preserveScroll: true,
        onFinish: () => propsToLoad.forEach((prop) => warmingDeferredProps.delete(prop)),
    })
}

function resultSubTab() {
    return props.last_result ? [{ key: 'result', label: 'Latest Result', count: 1 }] : []
}

function searchMatches(entry, query) {
    const normalizedQuery = query.trim().toLowerCase()

    if (!normalizedQuery) {
        return true
    }

    return [
        entry.item_name,
        entry.rarity,
        entry.quality,
        entry.item_class,
        entry.material_family,
        ...(entry.tags ?? []),
    ].filter(Boolean).join(' ').toLowerCase().includes(normalizedQuery)
}

function readSavedNavigationState() {
    const fallbackState = {
        activePanel: defaultActivePanel,
        activeSubPanels: { ...defaultActiveSubPanels },
        searchQuery: '',
    }

    if (typeof window === 'undefined') {
        return fallbackState
    }

    try {
        const parsedState = JSON.parse(window.localStorage.getItem(navigationStorageKey) ?? '{}')
        const savedState = parsedState && typeof parsedState === 'object' ? parsedState : {}
        const activePanel = isWorkspacePanel(savedState.activePanel) ? savedState.activePanel : fallbackState.activePanel
        const activeSubPanels = { ...defaultActiveSubPanels }

        if (savedState.activeSubPanels && typeof savedState.activeSubPanels === 'object') {
            Object.entries(savedState.activeSubPanels).forEach(([panel, subPanel]) => {
                if (isWorkspacePanel(panel) && typeof subPanel === 'string') {
                    activeSubPanels[panel] = subPanel
                }
            })
        }

        return {
            activePanel,
            activeSubPanels,
            searchQuery: typeof savedState.searchQuery === 'string' ? savedState.searchQuery : fallbackState.searchQuery,
        }
    } catch {
        return fallbackState
    }
}

function persistNavigationState() {
    if (typeof window === 'undefined') {
        return
    }

    try {
        window.localStorage.setItem(navigationStorageKey, JSON.stringify({
            activePanel: activePanel.value,
            activeSubPanels: activeSubPanels.value,
            searchQuery: searchQuery.value,
        }))
    } catch {
        return
    }
}

function isWorkspacePanel(panel) {
    return typeof panel === 'string' && Object.prototype.hasOwnProperty.call(defaultActiveSubPanels, panel)
}
</script>
