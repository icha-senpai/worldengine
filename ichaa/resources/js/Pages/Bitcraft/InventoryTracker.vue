<template>
    <WidgetPageShell
        :setup="setupPageVisible"
        title="Inventory Tracker"
        description="Configure tracked items, cargo, and goals for the OBS widget."
    >
        <main
            class="inventory-tracker-source"
            :class="{ 'inventory-tracker-source--setup': setupVisible, 'inventory-tracker-source--in-app': setupPageVisible }"
            :style="widgetThemeStyle"
        >
        <form v-if="setupVisible" class="inventory-tracker-setup" @submit.prevent="submitSetup(true)">
            <div class="inventory-tracker-setup__grid">
                <label>
                    <span>Title</span>
                    <input v-model.trim="form.title" type="text" maxlength="80" />
                </label>

                <div class="inventory-tracker-emoji-picker">
                    <span>Emojis</span>
                    <div class="inventory-tracker-emoji-picker__controls">
                        <select v-model="emojiChoice" @change="addEmojiChoice">
                            <option value="">Add emoji...</option>
                            <option
                                v-for="option in emojiOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                        <button v-if="selectedEmojiList.length" type="button" @click="clearEmojis">Clear</button>
                    </div>
                    <div v-if="selectedEmojiList.length" class="inventory-tracker-emoji-picker__selected">
                        <button
                            v-for="emoji in selectedEmojiList"
                            :key="emoji"
                            type="button"
                            @click="removeEmoji(emoji)"
                        >
                            {{ emoji }}
                        </button>
                    </div>
                </div>

                <label>
                    <span>Character</span>
                    <input v-model.trim="form.character" type="text" maxlength="80" />
                </label>

                <label>
                    <span>Default Need</span>
                    <input v-model.number="form.need" type="number" min="1" max="999999999" />
                </label>
            </div>

            <WidgetThemeControls :model="form" @update="updateTheme" />

            <div ref="pickerElement" class="inventory-tracker-picker">
                <label>
                    <span>Item / cargo</span>
                    <input
                        v-model.trim="form.itemSearch"
                        type="search"
                        autocomplete="off"
                        placeholder="Search inventory, items, or cargo"
                        @focus="pickerOpen = true"
                    />
                </label>

                <div v-if="pickerOpen" class="inventory-tracker-picker__menu">
                    <button
                        v-for="option in filteredOptions"
                        :key="option.key"
                        type="button"
                        class="inventory-tracker-picker__option"
                        :class="{ 'selected': form.itemKeys.includes(option.key) }"
                        @click="selectOption(option)"
                    >
                        <span>{{ option.name }}</span>
                        <small>{{ option.kind }}<template v-if="option.quantity"> · {{ formatNumber(option.quantity) }} held</template></small>
                    </button>

                    <p v-if="filteredOptions.length === 0">No matches loaded.</p>
                </div>
            </div>

            <div v-if="selectedOptions.length" class="inventory-tracker-selected">
                <span v-for="option in selectedOptions" :key="option.key">
                    <strong>{{ option.name }}</strong>
                    <label>
                        <small>Need</small>
                        <input
                            v-model.number="form.itemNeeds[option.key]"
                            type="number"
                            min="1"
                            max="999999999"
                            :placeholder="form.need ? String(form.need) : 'Need'"
                        />
                    </label>
                    <button type="button" @click="removeOption(option.key)">Remove</button>
                </span>
            </div>

            <div class="inventory-tracker-setup__actions">
                <button type="submit">Search / Update</button>
                <button type="button" @click="submitSetup(false)">Widget Mode</button>
            </div>
        </form>

        <section class="inventory-tracker-widget" aria-label="Live Bitcraft inventory tracker">
            <header class="inventory-tracker-widget__header">
                <h1>{{ titleLabel }}</h1>
                <p v-if="iconsLabel">{{ iconsLabel }}</p>
            </header>

            <div v-if="error" class="inventory-tracker-widget__error">
                {{ error }}
            </div>

            <div v-else-if="!tracker" class="inventory-tracker-widget__empty">
                Select item/cargo
            </div>

            <template v-else>
                <article
                    v-for="item in trackerItems"
                    :key="item.key"
                    class="inventory-tracker-widget__tracked-item"
                >
                    <div class="inventory-tracker-widget__row">
                        <div class="inventory-tracker-widget__item">
                            <p>
                                {{ item.name }}
                                <span v-if="itemTierLabel(item)">{{ itemTierLabel(item) }}</span>
                            </p>
                            <small>{{ itemMetaLabel(item) }}</small>
                        </div>

                        <div class="inventory-tracker-widget__count">
                            <small>Have / Need</small>
                            <strong>{{ haveNeedLabel(item) }}</strong>
                        </div>
                    </div>

                    <div class="inventory-tracker-widget__bar">
                        <span :style="{ width: `${itemProgressPercent(item)}%` }" />
                    </div>

                    <div class="inventory-tracker-widget__footer">
                        <strong>{{ progressLabel(item) }}</strong>
                        <span>{{ remainingLabel(item) }}</span>
                    </div>

                    <div v-if="item.sources.length" class="inventory-tracker-widget__sources">
                        <span v-for="source in item.sources" :key="source.name">
                            {{ source.name }}: {{ formatNumber(source.quantity) }}
                        </span>
                    </div>
                </article>
            </template>
        </section>
        </main>
    </WidgetPageShell>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import WidgetThemeControls from './Components/WidgetThemeControls.vue'
import WidgetPageShell from './Components/WidgetPageShell.vue'
import { normalizeWidgetTheme, widgetThemePayload, widgetThemeStyle as resolveWidgetThemeStyle } from './widgetTheme'

const props = defineProps({
    filters: { type: Object, default: () => ({}) },
    snapshot: { type: Object, default: () => ({ tracker: null, options: [], error: null, sampledAt: null }) },
    snapshotUrl: { type: String, required: true },
})

const POLL_INTERVAL_MS = 15000
const STORAGE_KEY = 'bitcraft.inventoryTracker.lastSetup'

const tracker = ref(props.snapshot.tracker)
const options = ref(props.snapshot.options ?? [])
const error = ref(props.snapshot.error)
const pickerOpen = ref(false)
const pickerElement = ref(null)
const emojiChoice = ref('')
let pollTimer = null
const emojiOptions = [
    { value: '🐟', label: '🐟 Fish' },
    { value: '🎣', label: '🎣 Fishing' },
    { value: '🐠', label: '🐠 Tropical fish' },
    { value: '🐡', label: '🐡 Pufferfish' },
    { value: '🦀', label: '🦀 Crab' },
    { value: '🦞', label: '🦞 Lobster' },
    { value: '🦐', label: '🦐 Shrimp' },
    { value: '⛵', label: '⛵ Sailing' },
    { value: '🚤', label: '🚤 Skiff' },
    { value: '🛶', label: '🛶 Raft' },
    { value: '📦', label: '📦 Cargo' },
    { value: '🧺', label: '🧺 Basket' },
    { value: '🪵', label: '🪵 Wood' },
    { value: '🪨', label: '🪨 Stone' },
    { value: '⛏️', label: '⛏️ Mining' },
    { value: '🌾', label: '🌾 Farming' },
    { value: '🍄', label: '🍄 Mushroom' },
    { value: '✨', label: '✨ Sparkle' },
    { value: '⭐', label: '⭐ Star' },
    { value: '🔥', label: '🔥 Fire' },
    { value: '💎', label: '💎 Rare' },
    { value: '🏆', label: '🏆 Goal' },
    { value: '✅', label: '✅ Done' },
    { value: '💚', label: '💚 Green' },
    { value: '🩵', label: '🩵 Cyan' },
]

const parseItemKeys = (value) => {
    const keys = Array.isArray(value) ? value : String(value ?? '').split(',')

    return [...new Set(keys
        .map((key) => String(key).trim())
        .filter((key) => /^(item|cargo):\d+$/.test(key)))]
}
const parseItemNeeds = (value) => {
    if (value && typeof value === 'object' && !Array.isArray(value)) {
        return Object.fromEntries(Object.entries(value)
            .map(([key, need]) => [String(key), Number(need)])
            .filter(([key, need]) => /^(item|cargo):\d+$/.test(key) && Number.isFinite(need) && need > 0))
    }

    return Object.fromEntries(String(value ?? '')
        .split(',')
        .map((entry) => {
            const separator = entry.indexOf('=')

            return separator === -1 ? ['', 0] : [
                entry.slice(0, separator).trim(),
                Number(entry.slice(separator + 1).trim()),
            ]
        })
        .filter(([key, need]) => /^(item|cargo):\d+$/.test(key) && Number.isFinite(need) && need > 0))
}
const formatItemNeeds = (needs) => Object.entries(needs)
    .filter(([key, need]) => /^(item|cargo):\d+$/.test(key) && Number.isFinite(Number(need)) && Number(need) > 0)
    .map(([key, need]) => `${key}=${Math.round(Number(need))}`)
    .join(',')

const form = reactive({
    source: props.filters.source ?? 'default',
    character: props.filters.character ?? 'icha',
    title: props.filters.title ?? 'Fishing Day!',
    icons: props.filters.icons ?? '',
    itemSearch: props.filters.itemSearch ?? '',
    itemKey: props.filters.itemKey ?? '',
    itemKeys: parseItemKeys(props.filters.itemKeys ?? props.filters.itemKey ?? ''),
    itemNeeds: parseItemNeeds(props.filters.itemNeeds ?? ''),
    need: props.filters.need ?? null,
    ...normalizeWidgetTheme(props.filters),
})

let restoredSetup = false

const setupPageVisible = computed(() => Boolean(props.filters.setup))
const setupVisible = computed(() => setupPageVisible.value || !form.itemKeys.length)
const titleLabel = computed(() => form.title || 'Inventory Tracker')
const iconsLabel = computed(() => form.icons || '')
const widgetThemeStyle = computed(() => resolveWidgetThemeStyle(form))
const selectedEmojiList = computed(() => form.icons.split(/\s+/).filter(Boolean))
const trackerItems = computed(() => {
    if (!tracker.value) {
        return []
    }

    if (Array.isArray(tracker.value.items) && tracker.value.items.length) {
        return tracker.value.items
    }

    return tracker.value.item ? [{
        ...tracker.value.item,
        quantity: tracker.value.quantity,
        need: tracker.value.need,
        remaining: tracker.value.remaining,
        progressPercent: tracker.value.progressPercent,
        sources: tracker.value.sources ?? [],
    }] : []
})
const selectedOptions = computed(() => form.itemKeys
    .map((key) => options.value.find((option) => option.key === key) ?? { key, name: key })
    .filter(Boolean))

const filteredOptions = computed(() => {
    const search = form.itemSearch.toLowerCase()

    return options.value
        .filter((option) => {
            if (!search) {
                return true
            }

            return [option.name, option.kind, option.tag, option.rarity]
                .filter(Boolean)
                .join(' ')
                .toLowerCase()
                .includes(search)
        })
        .slice(0, 40)
})

const itemMetaLabel = (item) => {
    return [
        item.kind,
        item.tag,
        item.rarity,
    ].filter(Boolean).join(' · ')
}

const itemProgressPercent = (item) => {
    if (item.progressPercent === null || item.progressPercent === undefined) {
        return item.quantity > 0 ? 100 : 0
    }

    return Math.max(0, Math.min(100, Number(item.progressPercent) || 0))
}

const haveNeedLabel = (item) => {
    if (!item) {
        return '--'
    }

    if (!item.need) {
        return formatNumber(item.quantity)
    }

    return `${formatNumber(item.quantity)} / ${formatNumber(item.need)}`
}

const progressLabel = (item) => {
    if (!item.need) {
        return `${formatNumber(item.quantity ?? 0)} held`
    }

    return `${Math.round(itemProgressPercent(item))}%`
}

const remainingLabel = (item) => {
    if (!item.need) {
        return 'No target set'
    }

    if (item.remaining <= 0) {
        return 'Target met'
    }

    return `${formatNumber(item.remaining)} left`
}

const formatNumber = (value) => new Intl.NumberFormat().format(Math.max(0, Math.round(Number(value) || 0)))
const formatTierLabel = (tier) => {
    if (tier === null || tier === undefined || tier === '') {
        return ''
    }

    const numericTier = Number(tier)

    if (!Number.isFinite(numericTier)) {
        return ''
    }

    return `T${Math.abs(Math.trunc(numericTier))}`
}
const itemTierLabel = (item) => formatTierLabel(item?.tier)
const saveEmojiList = (emojis) => {
    form.icons = emojis.join(' ')
    saveSetup()
}
const addEmojiChoice = () => {
    if (!emojiChoice.value) {
        return
    }

    saveEmojiList([...new Set([...selectedEmojiList.value, emojiChoice.value])])
    emojiChoice.value = ''
}
const removeEmoji = (emoji) => {
    saveEmojiList(selectedEmojiList.value.filter((selectedEmoji) => selectedEmoji !== emoji))
}
const clearEmojis = () => {
    saveEmojiList([])
}

const updateTheme = (updates) => {
    Object.assign(form, updates)
    saveSetup()
}

const selectOption = (option) => {
    if (!form.itemKeys.includes(option.key)) {
        form.itemKeys.push(option.key)
    }
    if (!form.itemNeeds[option.key] && form.need) {
        form.itemNeeds[option.key] = form.need
    }

    form.itemKey = form.itemKeys[0] ?? option.key
    form.itemSearch = option.name
    pickerOpen.value = false
    saveSetup()
}
const removeOption = (key) => {
    form.itemKeys = form.itemKeys.filter((itemKey) => itemKey !== key)
    delete form.itemNeeds[key]
    form.itemKey = form.itemKeys[0] ?? ''
    saveSetup()
}

const closePickerOnOutsidePointer = (event) => {
    if (!pickerElement.value || pickerElement.value.contains(event.target)) {
        return
    }

    pickerOpen.value = false
}

const payload = (setup) => ({
    source: form.source,
    character: form.character,
    title: form.title,
    icons: form.icons,
    itemSearch: form.itemSearch,
    itemKey: form.itemKey,
    itemKeys: form.itemKeys.join(','),
    itemNeeds: formatItemNeeds(form.itemNeeds),
    need: form.need,
    ...widgetThemePayload(form),
    setup: setup ? 1 : 0,
})

const browserStorage = () => {
    if (typeof window === 'undefined') {
        return null
    }

    return window.localStorage
}

const normalizeSetup = (setup) => ({
    source: typeof setup.source === 'string' && setup.source.trim() ? setup.source.trim() : 'default',
    character: typeof setup.character === 'string' && setup.character.trim() ? setup.character.trim() : 'icha',
    title: typeof setup.title === 'string' && setup.title.trim() ? setup.title.trim() : 'Fishing Day!',
    icons: typeof setup.icons === 'string' ? setup.icons.trim() : '',
    itemSearch: typeof setup.itemSearch === 'string' ? setup.itemSearch.trim() : '',
    itemKeys: parseItemKeys(setup.itemKeys ?? setup.itemKey),
    itemKey: parseItemKeys(setup.itemKeys ?? setup.itemKey)[0] ?? '',
    itemNeeds: parseItemNeeds(setup.itemNeeds),
    need: Number.isFinite(Number(setup.need)) && Number(setup.need) > 0 ? Number(setup.need) : null,
    ...normalizeWidgetTheme(setup),
})

const loadSetup = () => {
    const storage = browserStorage()

    if (!storage) {
        return null
    }

    try {
        return normalizeSetup(JSON.parse(storage.getItem(STORAGE_KEY) ?? 'null') ?? {})
    } catch {
        return null
    }
}

const saveSetup = () => {
    const storage = browserStorage()

    if (!storage || !restoredSetup) {
        return
    }

    storage.setItem(STORAGE_KEY, JSON.stringify(normalizeSetup(form)))
}

const submitSetup = (setup) => {
    saveSetup()

    router.get(route('bitcraft.inventory-tracker'), payload(setup), {
        preserveScroll: true,
        preserveState: false,
        replace: true,
    })
}

const refresh = async () => {
    if (!form.itemKeys.length) {
        return
    }

    try {
        const response = await fetch(props.snapshotUrl, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })

        if (!response.ok) {
            throw new Error(`Snapshot request failed with ${response.status}`)
        }

        const payload = await response.json()
        tracker.value = payload.tracker
        options.value = payload.options ?? options.value
        error.value = payload.error
    } catch {
        error.value = 'Tracker refresh failed. Waiting for the next Bitjita check.'
    }
}

watch(() => props.snapshot, (snapshot) => {
    tracker.value = snapshot.tracker
    options.value = snapshot.options ?? []
    error.value = snapshot.error
})

watch(form, saveSetup, { deep: true })

onMounted(() => {
    const savedSetup = loadSetup()
    const params = new URLSearchParams(window.location.search)

    restoredSetup = true

    if (!params.has('source') && !params.has('itemKey') && !params.has('itemKeys') && savedSetup?.itemKeys?.length) {
        Object.assign(form, savedSetup)
        submitSetup(Boolean(props.filters.setup))

        return
    }

    saveSetup()
    pollTimer = window.setInterval(refresh, POLL_INTERVAL_MS)
    document.addEventListener('pointerdown', closePickerOnOutsidePointer)
})

onBeforeUnmount(() => {
    window.clearInterval(pollTimer)
    document.removeEventListener('pointerdown', closePickerOnOutsidePointer)
})
</script>

<style scoped>
.inventory-tracker-source {
    min-height: 100vh;
    display: grid;
    align-content: start;
    gap: 12px;
    background: transparent;
    color: var(--text-primary);
    font-family: var(--font-ui);
}

.inventory-tracker-source--setup {
    padding: 14px;
    background: var(--bg-canvas);
}

.inventory-tracker-source--in-app {
    min-height: auto;
    padding: 0;
    background: transparent;
}

.inventory-tracker-setup {
    width: min(720px, 100%);
    border: 1px solid rgb(var(--border-color-2-rgb) / 0.36);
    border-radius: 8px;
    padding: 12px;
    background:
        linear-gradient(180deg, rgb(var(--bg-surface-3-rgb) / 0.26), rgb(var(--bg-surface-rgb) / 0.94)),
        var(--bg-surface);
}

.inventory-tracker-setup__grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
}

.inventory-tracker-setup label,
.inventory-tracker-emoji-picker,
.inventory-tracker-picker label {
    display: grid;
    gap: 6px;
}

.inventory-tracker-setup span,
.inventory-tracker-emoji-picker span,
.inventory-tracker-picker span {
    color: var(--text-muted-3);
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
}

.inventory-tracker-setup input,
.inventory-tracker-emoji-picker select {
    min-height: 36px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    background: var(--bg-canvas);
    color: var(--text-primary);
    font-size: 13px;
}

.inventory-tracker-emoji-picker__controls {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 6px;
}

.inventory-tracker-emoji-picker__controls button,
.inventory-tracker-emoji-picker__selected button {
    min-height: 32px;
    border: 1px solid rgb(var(--accent-cyan-rgb) / 0.28);
    border-radius: 6px;
    background: rgb(var(--accent-cyan-rgb) / 0.08);
    color: var(--text-primary-2);
    font-size: 12px;
    font-weight: 900;
}

.inventory-tracker-emoji-picker__controls button {
    padding: 0 10px;
    color: var(--accent-pink);
}

.inventory-tracker-emoji-picker__selected {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
}

.inventory-tracker-emoji-picker__selected button {
    width: 32px;
    padding: 0;
    font-size: 16px;
    line-height: 1;
}

.inventory-tracker-picker {
    position: relative;
    margin-top: 10px;
}

.inventory-tracker-picker__menu {
    position: absolute;
    z-index: 5;
    top: calc(100% + 6px);
    left: 0;
    right: 0;
    max-height: 260px;
    overflow-y: auto;
    border: 1px solid rgb(var(--border-color-2-rgb) / 0.42);
    border-radius: 8px;
    background: var(--bg-surface);
    box-shadow: 0 18px 38px rgb(0 0 0 / 0.32);
}

.inventory-tracker-picker__option {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 10px 12px;
    border-bottom: 1px solid rgb(var(--border-color-2-rgb) / 0.16);
    color: var(--text-primary);
    text-align: left;
}

.inventory-tracker-picker__option:hover,
.inventory-tracker-picker__option.selected {
    background: rgb(var(--accent-cyan-rgb) / 0.1);
}

.inventory-tracker-picker__option small,
.inventory-tracker-picker__menu p {
    color: var(--text-muted-3);
    font-size: 12px;
}

.inventory-tracker-picker__menu p {
    padding: 12px;
}

.inventory-tracker-selected {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 10px;
}

.inventory-tracker-selected span {
    display: grid;
    grid-template-columns: minmax(130px, 1fr) 90px auto;
    align-items: center;
    gap: 6px;
    border: 1px solid rgb(var(--accent-cyan-rgb) / 0.28);
    border-radius: 8px;
    padding: 6px 8px;
    background: rgb(var(--accent-cyan-rgb) / 0.08);
    color: var(--text-primary-2);
    font-size: 12px;
    font-weight: 800;
}

.inventory-tracker-selected label {
    display: grid;
    gap: 2px;
}

.inventory-tracker-selected small {
    color: var(--text-muted-3);
    font-size: 9px;
    font-weight: 900;
    text-transform: uppercase;
}

.inventory-tracker-selected input {
    min-height: 28px;
    width: 100%;
    border: 1px solid var(--border-color);
    border-radius: 5px;
    background: var(--bg-canvas);
    color: var(--text-primary);
    font-size: 12px;
}

.inventory-tracker-selected button {
    color: var(--accent-pink);
    font-size: 11px;
    font-weight: 900;
}

.inventory-tracker-setup__actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 12px;
}

.inventory-tracker-setup__actions button {
    min-height: 34px;
    padding: 0 12px;
    border: 1px solid rgb(var(--accent-cyan-rgb) / 0.34);
    border-radius: 6px;
    background: rgb(var(--accent-cyan-rgb) / 0.1);
    color: var(--accent-cyan);
    font-size: 12px;
    font-weight: 800;
}

.inventory-tracker-widget {
    width: min(var(--tracker-width), 100vw);
    overflow: hidden;
    border: 1px solid color-mix(in srgb, var(--tracker-border) 46%, transparent);
    border-radius: var(--tracker-radius);
    background:
        linear-gradient(
            180deg,
            color-mix(in srgb, var(--tracker-panel) var(--tracker-panel-opacity), transparent),
            color-mix(in srgb, var(--tracker-panel) 96%, black)
        );
    color: var(--tracker-text);
    box-shadow: inset 0 1px 0 rgb(var(--text-primary-rgb) / 0.04);
}

.inventory-tracker-widget__header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 18px 22px 14px;
    border-bottom: 1px solid color-mix(in srgb, var(--tracker-border) 24%, transparent);
}

.inventory-tracker-widget__header h1 {
    min-width: 0;
    color: var(--tracker-text);
    font-size: calc(24px * var(--tracker-font-scale));
    font-weight: 900;
    line-height: 1.1;
}

.inventory-tracker-widget__header p {
    color: var(--tracker-accent);
    font-size: calc(20px * var(--tracker-font-scale));
    line-height: 1;
}

.inventory-tracker-widget__tracked-item + .inventory-tracker-widget__tracked-item {
    border-top: 1px solid color-mix(in srgb, var(--tracker-border) 24%, transparent);
}

.inventory-tracker-widget__row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 20px;
    padding: 22px 24px 6px;
}

.inventory-tracker-widget__item p {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--tracker-text);
    font-size: calc(18px * var(--tracker-font-scale));
    font-weight: 900;
    line-height: 1.25;
}

.inventory-tracker-widget__item p span {
    display: inline-flex;
    align-items: center;
    min-height: 22px;
    padding: 0 7px;
    border-radius: 5px;
    background: color-mix(in srgb, var(--tracker-accent) 28%, transparent);
    color: var(--tracker-text);
    font-size: calc(13px * var(--tracker-font-scale));
}

.inventory-tracker-widget__item small,
.inventory-tracker-widget__count small {
    display: block;
    margin-top: 5px;
    color: var(--tracker-muted);
    font-size: calc(11px * var(--tracker-font-scale));
    font-weight: 800;
}

.inventory-tracker-widget__count {
    text-align: right;
}

.inventory-tracker-widget__count small {
    color: var(--tracker-highlight);
}

.inventory-tracker-widget__count strong {
    display: block;
    margin-top: 3px;
    color: var(--tracker-text);
    font-size: calc(19px * var(--tracker-font-scale));
    font-weight: 900;
}

.inventory-tracker-widget__bar {
    height: 6px;
    margin: 8px 24px 0;
    overflow: hidden;
    border-radius: 999px;
    background: color-mix(in srgb, var(--tracker-panel) 68%, black);
}

.inventory-tracker-widget__bar span {
    display: block;
    height: 100%;
    border-radius: inherit;
    background: linear-gradient(90deg, var(--tracker-highlight), var(--tracker-accent));
    transition: width 320ms ease;
}

.inventory-tracker-widget__footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 8px 24px 0;
    color: color-mix(in srgb, var(--tracker-text) 72%, var(--tracker-accent));
    font-size: calc(13px * var(--tracker-font-scale));
    font-weight: 900;
}

.inventory-tracker-widget__sources {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    padding: 14px 22px 20px;
}

.inventory-tracker-widget__sources span {
    border: 1px solid color-mix(in srgb, var(--tracker-border) 32%, transparent);
    border-radius: 999px;
    padding: 4px 8px;
    background: color-mix(in srgb, var(--tracker-panel) 72%, black);
    color: var(--tracker-muted);
    font-size: calc(11px * var(--tracker-font-scale));
    font-weight: 800;
}

.inventory-tracker-widget__empty,
.inventory-tracker-widget__error {
    margin: 16px;
    padding: 14px;
    border-radius: 8px;
    font-size: calc(13px * var(--tracker-font-scale));
    font-weight: 800;
}

.inventory-tracker-widget__empty {
    border: 1px dashed color-mix(in srgb, var(--tracker-border) 42%, transparent);
    color: var(--tracker-muted);
}

.inventory-tracker-widget__error {
    border: 1px solid rgb(var(--accent-pink-rgb) / 0.42);
    background: rgb(var(--accent-pink-rgb) / 0.1);
    color: var(--accent-pink);
}

@media (max-width: 520px) {
    .inventory-tracker-setup__grid,
    .inventory-tracker-widget__row {
        grid-template-columns: minmax(0, 1fr);
    }

    .inventory-tracker-widget__count {
        text-align: left;
    }
}
</style>
