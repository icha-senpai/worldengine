<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="page-hero">
                <div class="page-hero__copy">
                    <div class="page-hero__eyebrow">
                        <span>Bitcraft tools</span>
                    </div>
                    <h1 class="page-hero__title page-hero__title--lg">Settlement Projects</h1>
                    <p class="page-hero__subtitle">{{ settlementLabel }}</p>
                </div>
            </div>
        </template>

        <form class="index-panel settlement-projects-filter" @submit.prevent="submit">
            <div class="settlement-projects-filter__grid">
                <label class="field-group">
                    <span class="field-label">Empire</span>
                    <TextInput v-model.trim="form.empire" type="text" />
                </label>

                <label class="field-group">
                    <span class="field-label">Claim</span>
                    <TextInput v-model.trim="form.claimQ" type="text" />
                </label>

                <label class="field-group">
                    <span class="field-label">Donation buildings</span>
                    <TextInput v-model.trim="form.donationQ" type="text" />
                </label>

                <label class="field-group">
                    <span class="field-label">Since</span>
                    <TextInput v-model="form.since" type="date" />
                </label>
            </div>

            <div class="settlement-projects-filter__footer">
                <label class="market-search-toggle" :class="{ 'is-active': form.includeCompletedCrafts }">
                    <input v-model="form.includeCompletedCrafts" type="checkbox" class="market-search-toggle__input" />
                    <span class="market-search-toggle__copy">
                        <span class="market-search-toggle__label">Completed crafts</span>
                        <span class="market-search-toggle__hint">Include recent finished jobs</span>
                    </span>
                </label>

                <div class="settlement-projects-filter__actions">
                    <AppButton type="submit" variant="primary" :disabled="loading">{{ loading ? 'Loading...' : 'Refresh' }}</AppButton>
                    <AppButton type="button" variant="ghost" @click="reset">Reset</AppButton>
                </div>
            </div>
        </form>

        <div v-if="error" class="mt-5 rounded-md border border-[rgb(var(--accent-pink-rgb)/0.28)] bg-[rgb(var(--accent-pink-rgb)/0.08)] px-4 py-3 text-sm text-(--accent-pink)">
            {{ error }}
        </div>

        <section class="settlement-projects-summary">
            <button type="button" class="settlement-projects-stat settlement-projects-stat--button" @click="openBuildingModal">
                <span>Buildings</span>
                <strong>{{ formatNumber(metrics.buildingCount) }}</strong>
            </button>
            <article class="settlement-projects-stat">
                <span>Donation buildings</span>
                <strong>{{ formatNumber(metrics.donationBuildingCount) }}</strong>
            </article>
            <button type="button" class="settlement-projects-stat settlement-projects-stat--button" @click="openBuildingModal">
                <span>Selected buildings</span>
                <strong>{{ formatNumber(metrics.selectedBuildingCount) }}</strong>
            </button>
            <article class="settlement-projects-stat">
                <span>Log events</span>
                <strong>{{ formatNumber(metrics.storageLogCount) }}</strong>
            </article>
            <article class="settlement-projects-stat">
                <span>Active crafts</span>
                <strong>{{ formatNumber(metrics.activeCraftCount) }}</strong>
            </article>
            <article class="settlement-projects-stat">
                <span>Construction projects</span>
                <strong>{{ formatNumber(metrics.constructionProjectCount) }}</strong>
            </article>
        </section>

        <div class="mt-5 grid gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
            <section class="surface-section min-w-0">
                <div class="surface-section__header">
                    <div class="surface-section__copy">
                        <h2 class="surface-section__title">Contributors</h2>
                        <p class="surface-section__subtitle">{{ contributors.length }} Bitjita contributor{{ contributors.length === 1 ? '' : 's' }}</p>
                    </div>
                </div>

                <div class="surface-section__body">
                    <div v-if="contributors.length" class="index-surface index-surface--nested">
                        <article v-for="contributor in contributors" :key="contributorKey(contributor)" class="index-record settlement-contributor">
                            <div>
                                <p class="index-record__title prose-wrap">{{ contributor.playerName }}</p>
                                <p class="index-record__subtitle prose-wrap">{{ contributor.playerEntityId || 'No player id in payload' }}</p>
                            </div>

                            <div class="settlement-contributor__stats">
                                <span class="tag tag--success">{{ formatNumber(contributor.storageQuantity) }} donated</span>
                                <span class="tag">{{ formatNumber(contributor.storageEvents) }} storage</span>
                                <span class="tag">{{ formatNumber(contributor.craftProgress) }} craft progress</span>
                                <span class="tag">{{ formatPercent(contributor.craftPercentTotal) }} craft share</span>
                            </div>
                        </article>
                    </div>

                    <div v-else class="empty-state-panel">
                        <p class="text-muted-3 text-sm font-ui">No contributors found for this claim and time window.</p>
                    </div>
                </div>
            </section>

            <aside class="settlement-projects-sidebar">
                <section class="surface-section min-w-0">
                    <div class="surface-section__header">
                        <div class="surface-section__copy">
                            <h2 class="surface-section__title">Donation Sources</h2>
                            <p class="surface-section__subtitle">{{ donationBuildings.length }} matched building{{ donationBuildings.length === 1 ? '' : 's' }}</p>
                        </div>
                    </div>

                    <div class="surface-section__body">
                        <div v-if="donationBuildings.length" class="space-y-3">
                            <article v-for="building in donationBuildings" :key="building.entityId" class="index-record">
                                <p class="index-record__title prose-wrap">{{ buildingLabel(building) }}</p>
                                <p class="index-record__subtitle prose-wrap">{{ building.buildingName }} · {{ building.entityId }}</p>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <span v-if="building.storageSlots" class="tag">{{ formatNumber(building.storageSlots) }} storage slots</span>
                                    <span v-if="building.cargoSlots" class="tag">{{ formatNumber(building.cargoSlots) }} cargo slots</span>
                                    <span v-if="building.inventory?.length" class="tag">{{ building.inventory.length }} tracked stacks</span>
                                </div>
                            </article>
                        </div>

                        <div v-else class="empty-state-panel">
                            <p class="text-muted-3 text-sm font-ui">No donation-named buildings matched.</p>
                        </div>
                    </div>
                </section>
            </aside>
        </div>

        <div class="mt-4 grid gap-4 xl:grid-cols-2">
            <section class="surface-section min-w-0">
                <div class="surface-section__header">
                    <div class="surface-section__copy">
                        <h2 class="surface-section__title">Construction Needs</h2>
                        <p class="surface-section__subtitle">{{ construction.requirements.length }} material target{{ construction.requirements.length === 1 ? '' : 's' }}</p>
                    </div>
                </div>

                <div class="surface-section__body">
                    <div v-if="construction.requirements.length" class="space-y-3">
                        <article v-for="requirement in construction.requirements" :key="`${requirement.kind}:${requirement.id}:${requirement.name}`" class="settlement-requirement">
                            <div class="settlement-requirement__row">
                                <div>
                                    <p>{{ requirement.name }}</p>
                                    <small>{{ requirement.kind }}</small>
                                </div>
                                <strong>{{ haveNeedLabel(requirement) }}</strong>
                            </div>
                            <div class="settlement-requirement__bar">
                                <span :style="{ width: `${requirementPercent(requirement)}%` }" />
                            </div>
                        </article>
                    </div>

                    <div v-else class="empty-state-panel">
                        <p class="text-muted-3 text-sm font-ui">No active construction requirements returned.</p>
                    </div>
                </div>
            </section>

            <section class="surface-section min-w-0">
                <div class="surface-section__header">
                    <div class="surface-section__copy">
                        <h2 class="surface-section__title">Craft Jobs</h2>
                        <p class="surface-section__subtitle">{{ crafts.length }} job{{ crafts.length === 1 ? '' : 's' }} loaded</p>
                    </div>
                </div>

                <div class="surface-section__body">
                    <div v-if="crafts.length" class="space-y-3">
                        <article v-for="craft in crafts" :key="craft.entityId || craft.name" class="index-record">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="index-record__title prose-wrap">{{ craft.name }}</p>
                                    <p class="index-record__subtitle prose-wrap">{{ craftBuildingLabel(craft) }}</p>
                                </div>
                                <span class="tag" :class="craft.completed ? 'tag--success' : ''">{{ craft.completed ? 'Complete' : 'Active' }}</span>
                            </div>

                            <div v-if="craft.contributors.length" class="mt-4 space-y-2">
                                <div
                                    v-for="contributor in craft.contributors"
                                    :key="`${craft.entityId}:${contributorKey(contributor)}`"
                                    class="settlement-craft-contributor"
                                >
                                    <span>{{ contributor.playerName }}</span>
                                    <strong>{{ craftContributorLabel(contributor) }}</strong>
                                </div>
                            </div>
                            <p v-else class="mt-4 text-sm text-muted-3">No contributors returned.</p>
                        </article>
                    </div>

                    <div v-else class="empty-state-panel">
                        <p class="text-muted-3 text-sm font-ui">No craft jobs returned.</p>
                    </div>
                </div>
            </section>
        </div>

        <section class="surface-section mt-4 min-w-0">
            <div class="surface-section__header">
                <div class="surface-section__copy">
                    <h2 class="surface-section__title">Storage Logs</h2>
                    <p class="surface-section__subtitle">{{ storageLogs.length }} event{{ storageLogs.length === 1 ? '' : 's' }} from selected buildings</p>
                </div>
            </div>

            <div class="surface-section__body">
                <div v-if="storageLogs.length" class="settlement-log-table">
                    <div class="settlement-log-table__head">
                        <span>Player</span>
                        <span>Item</span>
                        <span>Qty</span>
                        <span>Source</span>
                        <span>Time</span>
                    </div>
                    <div v-for="log in storageLogs" :key="log.id" class="settlement-log-table__row">
                        <span>{{ log.playerName }}</span>
                        <span>{{ log.itemName }}</span>
                        <strong :class="log.direction === 'in' ? 'text-(--success)' : 'text-(--accent-pink)'">
                            {{ log.direction === 'in' ? '+' : '-' }}{{ formatNumber(log.quantity) }}
                        </strong>
                        <span>{{ log.buildingNickname || log.buildingName }}</span>
                        <span>{{ formatDateTime(log.createdAt) }}</span>
                    </div>
                </div>

                <div v-else class="empty-state-panel">
                    <p class="text-muted-3 text-sm font-ui">No storage logs returned for selected buildings.</p>
                </div>
            </div>
        </section>

        <Modal :show="showBuildingModal" max-width="5xl" @close="closeBuildingModal">
            <div class="settlement-building-modal">
                <div class="settlement-building-modal__header">
                    <div>
                        <p class="settlement-building-modal__eyebrow">{{ settlement?.claim?.name ?? form.claimQ }}</p>
                        <h2 class="settlement-building-modal__title">Buildings</h2>
                    </div>
                    <AppButton type="button" variant="ghost" @click="closeBuildingModal">Close</AppButton>
                </div>

                <div class="settlement-building-modal__toolbar">
                    <label class="field-group">
                        <span class="field-label">Search</span>
                        <TextInput v-model.trim="buildingSearch" type="search" placeholder="Search buildings" />
                    </label>
                    <div class="settlement-building-modal__counts">
                        <span class="tag">{{ formatNumber(filteredBuildings.length) }} shown</span>
                        <span class="tag tag--success">{{ selectedBuildingIds.size }} selected</span>
                    </div>
                </div>

                <div v-if="filteredBuildings.length" class="settlement-building-grid">
                    <button
                        v-for="building in filteredBuildings"
                        :key="building.entityId"
                        type="button"
                        class="settlement-building-card"
                        :class="{
                            'settlement-building-card--matched': isDonationBuilding(building),
                            'settlement-building-card--selected': isSelectedBuilding(building),
                        }"
                        @click="toggleBuilding(building)"
                    >
                        <div>
                            <p>{{ buildingLabel(building) }}</p>
                            <small>{{ building.buildingName }}</small>
                        </div>
                        <span>{{ building.entityId }}</span>
                        <div class="settlement-building-card__tags">
                            <span v-if="isSelectedBuilding(building)" class="tag tag--success">Selected</span>
                            <span v-else-if="isDonationBuilding(building)" class="tag">Matched</span>
                            <span v-if="building.inventory?.length" class="tag">{{ building.inventory.length }} stacks</span>
                        </div>
                    </button>
                </div>

                <div v-else class="empty-state-panel">
                    <p class="text-muted-3 text-sm font-ui">No buildings matched that search.</p>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import AppButton from '@/Components/ui/AppButton.vue'
import Modal from '@/Components/Modal.vue'
import TextInput from '@/Components/TextInput.vue'

const props = defineProps({
    filters: { type: Object, default: () => ({}) },
    state: { type: Object, default: () => ({}) },
    error: { type: String, default: null },
    sampledAt: { type: String, default: null },
})

const loading = ref(false)
const showBuildingModal = ref(false)
const buildingSearch = ref('')
const form = reactive({
    empire: props.filters.empire ?? 'Earth Kingdom',
    claimQ: props.filters.claimQ ?? 'Ba Sing Se',
    donationQ: props.filters.donationQ ?? 'donation',
    since: dateInputValue(props.filters.since),
    includeCompletedCrafts: Boolean(props.filters.includeCompletedCrafts),
    selectedBuildingIds: selectedBuildingIdsFromFilters(props.filters),
})

watch(() => props.filters, (filters) => {
    form.empire = filters.empire ?? 'Earth Kingdom'
    form.claimQ = filters.claimQ ?? 'Ba Sing Se'
    form.donationQ = filters.donationQ ?? 'donation'
    form.since = dateInputValue(filters.since)
    form.includeCompletedCrafts = Boolean(filters.includeCompletedCrafts)
    form.selectedBuildingIds = selectedBuildingIdsFromFilters(filters)
})

const settlement = computed(() => props.state ?? {})
const metrics = computed(() => settlement.value.metrics ?? {})
const contributors = computed(() => settlement.value.contributors ?? [])
const allBuildings = computed(() => settlement.value.buildings ?? [])
const donationBuildings = computed(() => settlement.value.donationBuildings ?? [])
const selectedBuildings = computed(() => settlement.value.selectedBuildings ?? [])
const donationBuildingIds = computed(() => new Set(donationBuildings.value.map((building) => building.entityId)))
const filteredBuildings = computed(() => {
    const needle = buildingSearch.value.toLowerCase()

    if (!needle) {
        return allBuildings.value
    }

    return allBuildings.value.filter((building) => buildingSearchText(building).includes(needle))
})
const selectedBuildingIds = computed(() => {
    const explicitIds = form.selectedBuildingIds.filter(Boolean)

    if (explicitIds.length > 0) {
        return new Set(explicitIds)
    }

    return new Set(selectedBuildings.value.map((building) => building.entityId))
})
const construction = computed(() => settlement.value.construction ?? { projects: [], requirements: [] })
const crafts = computed(() => settlement.value.crafts ?? [])
const storageLogs = computed(() => settlement.value.storageLogs ?? [])
const settlementLabel = computed(() => {
    const empire = settlement.value.empire?.name ?? form.empire
    const claim = settlement.value.claim?.name ?? form.claimQ

    return `${empire} · ${claim}`
})

function submit() {
    router.get(route('bitcraft.settlement-projects'), payload(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => {
            loading.value = true
        },
        onFinish: () => {
            loading.value = false
        },
    })
}

function reset() {
    form.empire = 'Earth Kingdom'
    form.claimQ = 'Ba Sing Se'
    form.donationQ = 'donation'
    form.since = dateInputValue(new Date(Date.now() - (7 * 24 * 60 * 60 * 1000)).toISOString())
    form.includeCompletedCrafts = false
    form.selectedBuildingIds = []
    submit()
}

function payload() {
    const params = {
        empire: form.empire,
        claimQ: form.claimQ,
        donationQ: form.donationQ,
        since: form.since,
    }

    if (form.includeCompletedCrafts) {
        params.includeCompletedCrafts = 1
    }

    if (form.selectedBuildingIds.length > 0) {
        params.buildingEntityIds = form.selectedBuildingIds.join(',')
    }

    return params
}

function openBuildingModal() {
    showBuildingModal.value = true
}

function closeBuildingModal() {
    showBuildingModal.value = false
}

function contributorKey(contributor) {
    return contributor.playerEntityId || contributor.playerName
}

function buildingLabel(building) {
    return building.buildingNickname || building.buildingName || 'Unknown building'
}

function buildingSearchText(building) {
    return [
        building.buildingNickname,
        building.buildingName,
        building.entityId,
        building.ownerName,
    ].filter(Boolean).join(' ').toLowerCase()
}

function isDonationBuilding(building) {
    return donationBuildingIds.value.has(building.entityId)
}

function isSelectedBuilding(building) {
    return selectedBuildingIds.value.has(building.entityId)
}

function toggleBuilding(building) {
    const entityId = String(building.entityId ?? '')

    if (!entityId) {
        return
    }

    const ids = Array.from(selectedBuildingIds.value)
    const index = ids.indexOf(entityId)

    if (index >= 0) {
        if (ids.length === 1) {
            return
        }

        ids.splice(index, 1)
    } else {
        ids.push(entityId)
    }

    form.selectedBuildingIds = ids
    submit()
}

function craftBuildingLabel(craft) {
    return craft.buildingNickname || craft.buildingName || craft.buildingEntityId || 'Unknown building'
}

function haveNeedLabel(requirement) {
    const contributed = Number(requirement.contributed ?? 0)
    const quantity = Number(requirement.quantity ?? 0)

    if (quantity <= 0) {
        return formatNumber(contributed)
    }

    return `${formatNumber(contributed)} / ${formatNumber(quantity)}`
}

function requirementPercent(requirement) {
    const quantity = Number(requirement.quantity ?? 0)
    const contributed = Number(requirement.contributed ?? 0)

    if (quantity <= 0) {
        return 0
    }

    return Math.min(100, Math.round((contributed / quantity) * 1000) / 10)
}

function craftContributorLabel(contributor) {
    if (Number(contributor.percent) > 0) {
        return formatPercent(contributor.percent)
    }

    return `${formatNumber(contributor.progress)} progress`
}

function formatNumber(value) {
    const number = Number(value ?? 0)

    return new Intl.NumberFormat().format(Number.isFinite(number) ? number : 0)
}

function formatPercent(value) {
    const number = Number(value ?? 0)

    return `${new Intl.NumberFormat(undefined, { maximumFractionDigits: 1 }).format(Number.isFinite(number) ? number : 0)}%`
}

function formatDateTime(value) {
    if (!value) {
        return '-'
    }

    const date = new Date(value)

    if (Number.isNaN(date.getTime())) {
        return value
    }

    return date.toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    })
}

function dateInputValue(value) {
    if (!value) {
        return ''
    }

    const date = new Date(value)

    if (Number.isNaN(date.getTime())) {
        return String(value).slice(0, 10)
    }

    return date.toISOString().slice(0, 10)
}

function selectedBuildingIdsFromFilters(filters) {
    return Array.isArray(filters?.buildingEntityIds)
        ? filters.buildingEntityIds.map((id) => String(id)).filter(Boolean)
        : []
}
</script>

<style scoped>
.settlement-projects-filter {
    max-width: none;
}

.settlement-projects-filter__grid {
    display: grid;
    gap: 0.75rem;
}

.settlement-projects-filter__footer,
.settlement-projects-filter__actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.75rem;
}

.settlement-projects-filter__footer {
    justify-content: space-between;
    margin-top: 1rem;
}

.settlement-projects-summary {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.75rem;
    margin-top: 1.25rem;
}

.settlement-projects-stat {
    border: 1px solid rgb(var(--border-color-rgb) / 0.7);
    border-radius: 8px;
    background: rgb(var(--bg-surface-rgb) / 0.74);
    padding: 1rem;
    text-align: left;
}

.settlement-projects-stat span,
.settlement-projects-stat strong {
    display: block;
}

.settlement-projects-stat span {
    font-size: 0.75rem;
    color: var(--text-muted-3);
}

.settlement-projects-stat strong {
    margin-top: 0.35rem;
    font-family: var(--font-ui);
    font-size: 1.35rem;
    color: var(--text-primary);
}

.settlement-projects-stat--button {
    width: 100%;
    cursor: pointer;
    transition: border-color 120ms ease, background-color 120ms ease, transform 120ms ease;
}

.settlement-projects-stat--button:hover {
    border-color: rgb(var(--accent-cyan-rgb) / 0.65);
    background: rgb(var(--accent-cyan-rgb) / 0.07);
    transform: translateY(-1px);
}

.settlement-projects-sidebar {
    display: grid;
    gap: 1rem;
    align-content: start;
}

.settlement-contributor {
    display: grid;
    gap: 1rem;
}

.settlement-contributor__stats {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.settlement-requirement {
    border: 1px solid rgb(var(--border-color-rgb) / 0.7);
    border-radius: 8px;
    padding: 0.85rem;
}

.settlement-requirement__row,
.settlement-craft-contributor {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.settlement-requirement__row p,
.settlement-requirement__row small {
    margin: 0;
}

.settlement-requirement__row p {
    color: var(--text-primary);
    font-weight: 700;
}

.settlement-requirement__row small {
    color: var(--text-muted-3);
    text-transform: uppercase;
}

.settlement-requirement__bar {
    height: 0.45rem;
    overflow: hidden;
    border-radius: 999px;
    background: rgb(var(--border-color-rgb) / 0.55);
    margin-top: 0.8rem;
}

.settlement-requirement__bar span {
    display: block;
    height: 100%;
    border-radius: inherit;
    background: rgb(var(--success-rgb) / 0.9);
}

.settlement-craft-contributor {
    border-top: 1px solid rgb(var(--border-color-rgb) / 0.55);
    padding-top: 0.5rem;
    color: var(--text-muted-2);
    font-size: 0.875rem;
}

.settlement-craft-contributor strong {
    color: var(--text-primary);
}

.settlement-building-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 0.75rem;
}

.settlement-building-card {
    width: 100%;
    min-height: 9.25rem;
    display: grid;
    align-content: space-between;
    gap: 0.75rem;
    border: 1px solid rgb(var(--border-color-rgb) / 0.55);
    border-radius: 8px;
    background: rgb(var(--bg-surface-rgb) / 0.64);
    padding: 0.85rem;
    text-align: left;
    transition: border-color 120ms ease, background-color 120ms ease;
}

.settlement-building-card:hover {
    border-color: rgb(var(--accent-cyan-rgb) / 0.55);
    background: rgb(var(--accent-cyan-rgb) / 0.06);
}

.settlement-building-card--matched {
    border-color: rgb(var(--success-rgb) / 0.65);
}

.settlement-building-card--selected {
    border-color: rgb(var(--success-rgb) / 0.85);
    background: rgb(var(--success-rgb) / 0.08);
}

.settlement-building-card p,
.settlement-building-card small,
.settlement-building-card > span {
    margin: 0;
}

.settlement-building-card p {
    color: var(--text-primary);
    font-weight: 700;
}

.settlement-building-card small,
.settlement-building-card > span {
    color: var(--text-muted-3);
    word-break: break-word;
}

.settlement-building-card__tags {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
}

.settlement-building-modal {
    display: grid;
    gap: 1rem;
    padding: 1rem;
}

.settlement-building-modal__header,
.settlement-building-modal__toolbar {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
}

.settlement-building-modal__toolbar {
    align-items: end;
}

.settlement-building-modal__toolbar .field-group {
    flex: 1 1 20rem;
}

.settlement-building-modal__eyebrow,
.settlement-building-modal__title {
    margin: 0;
}

.settlement-building-modal__eyebrow {
    color: var(--text-muted-3);
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
}

.settlement-building-modal__title {
    margin-top: 0.2rem;
    color: var(--text-primary);
    font-family: var(--font-ui);
    font-size: 1.35rem;
}

.settlement-building-modal__counts {
    display: flex;
    flex: 0 0 auto;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 0.5rem;
    padding-bottom: 0.1rem;
}

.settlement-log-table {
    overflow-x: auto;
}

.settlement-log-table__head,
.settlement-log-table__row {
    display: grid;
    grid-template-columns: minmax(140px, 1.1fr) minmax(160px, 1.2fr) 80px minmax(140px, 1fr) minmax(130px, 0.8fr);
    gap: 1rem;
    align-items: center;
    min-width: 760px;
    padding: 0.75rem 0.5rem;
}

.settlement-log-table__head {
    border-bottom: 1px solid rgb(var(--border-color-rgb) / 0.75);
    color: var(--text-muted-3);
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
}

.settlement-log-table__row {
    border-bottom: 1px solid rgb(var(--border-color-rgb) / 0.45);
    color: var(--text-muted-2);
    font-size: 0.875rem;
}

@media (min-width: 768px) {
    .settlement-projects-filter__grid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }

    .settlement-projects-summary {
        grid-template-columns: repeat(6, minmax(0, 1fr));
    }

    .settlement-building-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .settlement-contributor {
        grid-template-columns: minmax(0, 1fr) minmax(280px, 0.9fr);
        align-items: center;
    }
}

@media (min-width: 1100px) {
    .settlement-building-grid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }
}

@media (max-width: 640px) {
    .settlement-building-modal__header,
    .settlement-building-modal__toolbar {
        display: grid;
    }

    .settlement-building-modal__counts {
        justify-content: flex-start;
    }
}
</style>
