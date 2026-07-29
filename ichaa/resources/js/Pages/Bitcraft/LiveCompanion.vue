<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="page-hero">
                <div class="page-hero__copy">
                    <div class="page-hero__eyebrow">
                        <span>Bitcraft tools</span>
                    </div>
                    <h1 class="page-hero__title page-hero__title--lg">Live Companion</h1>
                    <p class="page-hero__subtitle">{{ statusSubtitle }}</p>
                </div>
            </div>
        </template>

        <section class="live-companion-status">
            <article class="live-companion-stat" :class="{ 'live-companion-stat--online': snapshot.online }">
                <span>Bridge</span>
                <strong>{{ snapshot.online ? 'Online' : snapshot.stale ? 'Stale' : 'Offline' }}</strong>
            </article>
            <article class="live-companion-stat">
                <span>Inventory</span>
                <strong>{{ formatNumber(inventory.length) }}</strong>
            </article>
            <article class="live-companion-stat">
                <span>Deployables</span>
                <strong>{{ formatNumber(deployables.length) }}</strong>
            </article>
            <article class="live-companion-stat">
                <span>Storage stacks</span>
                <strong>{{ formatNumber(deployableStackCount) }}</strong>
            </article>
        </section>

        <div v-if="snapshot.error" class="mt-5 rounded-md border border-[rgb(var(--accent-pink-rgb)/0.28)] bg-[rgb(var(--accent-pink-rgb)/0.08)] px-4 py-3 text-sm text-(--accent-pink)">
            {{ snapshot.error }}
        </div>

        <div class="mt-5 grid gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
            <section class="surface-section min-w-0">
                <div class="surface-section__header">
                    <div class="surface-section__copy">
                        <h2 class="surface-section__title">Inventory</h2>
                        <p class="surface-section__subtitle">{{ inventory.length }} live stack{{ inventory.length === 1 ? '' : 's' }}</p>
                    </div>
                </div>

                <div class="surface-section__body">
                    <div v-if="inventory.length" class="live-companion-list">
                        <article v-for="stack in inventory" :key="stackKey(stack)" class="index-record live-companion-row">
                            <div>
                                <p class="index-record__title prose-wrap">{{ stack.name || stackLabel(stack) }}</p>
                                <p class="index-record__subtitle prose-wrap">{{ stackMeta(stack) }}</p>
                            </div>
                            <strong>{{ formatNumber(stack.quantity) }}</strong>
                        </article>
                    </div>

                    <div v-else class="empty-state-panel">
                        <p class="text-muted-3 text-sm font-ui">{{ inventoryEmptyLabel }}</p>
                    </div>
                </div>
            </section>

            <aside class="live-companion-sidebar">
                <section class="surface-section min-w-0">
                    <div class="surface-section__header">
                        <div class="surface-section__copy">
                            <h2 class="surface-section__title">Biome</h2>
                            <p class="surface-section__subtitle">{{ biomeSourceLabel }}</p>
                        </div>
                    </div>

                    <div class="surface-section__body">
                        <div class="live-companion-biome">
                            <strong>{{ biomeName }}</strong>
                            <span>{{ biomeConfidenceLabel }}</span>
                        </div>
                    </div>
                </section>

                <section class="surface-section min-w-0">
                    <div class="surface-section__header">
                        <div class="surface-section__copy">
                            <h2 class="surface-section__title">Bridge File</h2>
                            <p class="surface-section__subtitle">{{ capturedAtLabel }}</p>
                        </div>
                    </div>

                    <div class="surface-section__body">
                        <p class="live-companion-path">{{ snapshot.path || 'No bridge path configured' }}</p>
                    </div>
                </section>
            </aside>
        </div>

        <section class="surface-section mt-4 min-w-0">
            <div class="surface-section__header">
                <div class="surface-section__copy">
                    <h2 class="surface-section__title">Deployables</h2>
                    <p class="surface-section__subtitle">{{ deployables.length }} loaded deployable{{ deployables.length === 1 ? '' : 's' }}</p>
                </div>
            </div>

            <div class="surface-section__body">
                <div v-if="deployables.length" class="live-companion-deployables">
                    <article v-for="deployable in deployables" :key="deployable.entityId" class="index-record live-companion-deployable">
                        <div class="live-companion-deployable__head">
                            <div>
                                <p class="index-record__title prose-wrap">{{ deployable.name || deployable.entityId }}</p>
                                <p class="index-record__subtitle prose-wrap">{{ deployableCoords(deployable) || deployable.entityId }}</p>
                            </div>
                            <span class="tag">{{ deployable.inventory.length }} stack{{ deployable.inventory.length === 1 ? '' : 's' }}</span>
                        </div>

                        <div v-if="deployable.inventory.length" class="live-companion-nested">
                            <div v-for="stack in deployable.inventory" :key="`${deployable.entityId}:${stackKey(stack)}`" class="live-companion-nested__row">
                                <span>{{ stack.name || stackLabel(stack) }}</span>
                                <strong>{{ formatNumber(stack.quantity) }}</strong>
                            </div>
                        </div>
                    </article>
                </div>

                <div v-else class="empty-state-panel">
                    <p class="text-muted-3 text-sm font-ui">{{ deployablesEmptyLabel }}</p>
                </div>
            </div>
        </section>
    </AuthenticatedLayout>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

const props = defineProps({
    snapshot: { type: Object, required: true },
    snapshotUrl: { type: String, required: true },
})

const POLL_INTERVAL_MS = 1000
const snapshot = ref(props.snapshot)
let pollTimer = null

const state = computed(() => snapshot.value.state ?? {})
const inventory = computed(() => state.value.inventory ?? [])
const deployables = computed(() => state.value.deployables ?? [])
const deployableStackCount = computed(() => deployables.value.reduce((total, deployable) => total + (deployable.inventory?.length ?? 0), 0))
const biome = computed(() => state.value.biome ?? {})
const biomeName = computed(() => biome.value.name || 'Waiting for exact biome')
const biomeSourceLabel = computed(() => biome.value.source || 'mod bridge')
const biomeConfidenceLabel = computed(() => biome.value.confidence ? `Confidence: ${biome.value.confidence}` : 'No confidence loaded')
const capturedAtLabel = computed(() => {
    if (!snapshot.value.lastCapturedAt) {
        return 'No live capture yet'
    }

    return `Captured ${new Date(snapshot.value.lastCapturedAt).toLocaleString()}`
})
const statusSubtitle = computed(() => {
    if (snapshot.value.online) {
        return `Fresh bridge data, ${Math.round((snapshot.value.ageMs ?? 0) / 1000)}s old.`
    }

    if (snapshot.value.stale) {
        return `Last bridge data is stale, ${Math.round((snapshot.value.ageMs ?? 0) / 1000)}s old.`
    }

    return 'Waiting for BitCraft to write a bridge snapshot.'
})
const inventoryEmptyLabel = computed(() => snapshot.value.online ? 'Inventory reader returned no stacks.' : 'Start BitCraft with the live companion mod enabled.')
const deployablesEmptyLabel = computed(() => snapshot.value.online ? 'No loaded deployables found.' : 'Deployables will appear after the bridge is fresh.')

const formatNumber = (value) => new Intl.NumberFormat().format(Math.max(0, Math.round(Number(value) || 0)))
const stackLabel = (stack) => `${stack.kind || 'stack'} ${stack.id || ''}`.trim()
const stackKey = (stack) => `${stack.kind}:${stack.id}:${stack.slot}:${stack.name}`
const stackMeta = (stack) => [
    stack.kind,
    stack.id ? `id ${stack.id}` : null,
    stack.slot !== null && stack.slot !== undefined ? `slot ${stack.slot}` : null,
    stack.tier ? `T${Math.abs(Math.trunc(Number(stack.tier)))}` : null,
].filter(Boolean).join(' · ')
const deployableCoords = (deployable) => {
    if ([deployable.localX, deployable.localY, deployable.localZ].every((value) => value !== null && value !== undefined)) {
        return `local ${Number(deployable.localX).toFixed(1)}, ${Number(deployable.localY).toFixed(1)}, ${Number(deployable.localZ).toFixed(1)}`
    }

    if ([deployable.locationX, deployable.locationZ].every((value) => value !== null && value !== undefined)) {
        return `${Number(deployable.locationX).toFixed(0)}, ${Number(deployable.locationZ).toFixed(0)}`
    }

    return ''
}

const refresh = async () => {
    const response = await fetch(props.snapshotUrl, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    })

    if (!response.ok) {
        return
    }

    snapshot.value = await response.json()
}

onMounted(() => {
    pollTimer = window.setInterval(refresh, POLL_INTERVAL_MS)
})

onBeforeUnmount(() => {
    window.clearInterval(pollTimer)
})
</script>

<style scoped>
.live-companion-status {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
}

.live-companion-stat {
    display: grid;
    gap: 6px;
    border: 1px solid rgb(var(--border-color-2-rgb) / 0.36);
    border-radius: 8px;
    padding: 14px;
    background: var(--bg-surface);
}

.live-companion-stat span {
    color: var(--text-muted-3);
    font-family: var(--font-ui);
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
}

.live-companion-stat strong {
    color: var(--text-primary);
    font-family: var(--font-ui);
    font-size: 24px;
    font-weight: 900;
}

.live-companion-stat--online strong {
    color: var(--success);
}

.live-companion-list,
.live-companion-deployables {
    display: grid;
    gap: 10px;
}

.live-companion-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: center;
    gap: 14px;
}

.live-companion-row > strong {
    color: var(--accent-cyan);
    font-family: var(--font-ui);
    font-size: 18px;
    font-weight: 900;
}

.live-companion-sidebar {
    display: grid;
    align-content: start;
    gap: 12px;
}

.live-companion-biome {
    display: grid;
    min-height: 96px;
    place-items: center;
    gap: 6px;
    border: 1px solid rgb(var(--accent-cyan-rgb) / 0.24);
    border-radius: 8px;
    background: rgb(var(--accent-cyan-rgb) / 0.08);
    text-align: center;
}

.live-companion-biome strong {
    color: var(--text-primary);
    font-family: var(--font-ui);
    font-size: 20px;
    font-weight: 900;
}

.live-companion-biome span,
.live-companion-path {
    color: var(--text-muted-3);
    font-family: var(--font-ui);
    font-size: 12px;
    overflow-wrap: anywhere;
}

.live-companion-deployable {
    display: grid;
    gap: 12px;
}

.live-companion-deployable__head {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: start;
    gap: 12px;
}

.live-companion-nested {
    display: grid;
    gap: 6px;
    border-left: 2px solid rgb(var(--accent-cyan-rgb) / 0.28);
    padding-left: 10px;
}

.live-companion-nested__row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 8px;
    color: var(--text-muted);
    font-family: var(--font-ui);
    font-size: 13px;
}

@media (max-width: 900px) {
    .live-companion-status {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 520px) {
    .live-companion-status,
    .live-companion-row,
    .live-companion-deployable__head {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>
