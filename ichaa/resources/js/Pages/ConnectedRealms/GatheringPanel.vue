<template>
    <section class="surface-section">
        <div class="surface-section__header">
            <div class="surface-section__copy">
                <span class="surface-section__title">Resource Runs</span>
                <p class="surface-section__subtitle">{{ unlockedCount }} unlocked · {{ actionStateLabel }}</p>
            </div>
        </div>

        <div class="surface-section__body">
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="filter in filters"
                    :key="filter.key"
                    type="button"
                    class="rounded-md border border-border bg-surface-2 px-3 py-2 text-xs font-ui text-muted-2 transition hover:border-focus/60 hover:text-primary"
                    :class="{ 'border-focus/70 bg-focus/10 text-primary': selectedFilter === filter.key }"
                    @click="selectedFilter = filter.key"
                >
                    {{ filter.label }} · {{ filter.count }}
                </button>
            </div>

            <div class="mt-4 grid gap-4 xl:grid-cols-[16rem_minmax(0,1fr)]">
                <div class="rounded-md border border-border bg-surface-2 px-3 py-3">
                    <p class="text-sm font-ui text-primary">{{ activeFilter.label }}</p>
                    <div class="mt-3 grid gap-2 text-xs">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-2">Unlocked</span>
                            <span class="text-primary">{{ activeUnlockedCount }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-2">Locked</span>
                            <span class="text-primary">{{ visibleActions.length - activeUnlockedCount }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-2">State</span>
                            <span class="text-primary">{{ canActNow ? 'Ready' : 'Cooldown' }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-muted-2">Timer</span>
                            <span class="text-primary">{{ cooldownLabel }}</span>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="app-btn app-btn--ghost app-btn--sm mt-4 w-full"
                        :class="{ 'border-focus/70 bg-focus/10 text-primary': autoRepeatEnabled }"
                        :disabled="!repeatActionKey"
                        @click="autoRepeatEnabled = !autoRepeatEnabled"
                    >
                        {{ autoRepeatEnabled ? 'Repeating' : 'Repeat Last' }}
                    </button>
                </div>

                <div class="grid gap-3">
                    <button
                        v-for="(action, index) in visibleActions"
                        :key="action.key"
                        type="button"
                        class="grid gap-3 rounded-md border border-border bg-surface-2 px-3 py-3 text-left transition hover:border-focus/60 disabled:cursor-not-allowed disabled:opacity-55 md:grid-cols-[3rem_minmax(0,1fr)_5.75rem]"
                        :disabled="form.processing || !canActNow || !action.is_unlocked"
                        @click="submitAction(action.key)"
                    >
                        <span class="grid h-9 w-9 place-items-center rounded-md border border-border bg-canvas text-sm font-ui text-primary">
                            #{{ index + 1 }}
                        </span>

                        <span class="min-w-0">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="min-w-0 truncate text-sm font-ui text-primary">{{ action.label }}</span>
                                <span class="tag">{{ action.skill_label }}</span>
                                <span class="tag">Lv {{ action.required_level }}</span>
                                <span v-if="action.active_event" class="tag border-success/40 bg-success/10 text-success">{{ action.active_event.label }}</span>
                            </span>
                            <span class="mt-1 block text-xs text-muted-2">{{ action.location }}</span>
                            <span v-if="action.equipped_tool" class="mt-2 block text-xs text-muted-2">
                                {{ action.equipped_tool.item_name }} · {{ action.equipped_tool.signature_trait }} · +{{ action.equipped_tool.experience_bonus }} XP · +{{ action.equipped_tool.yield_bonus }} yield
                            </span>
                            <span class="mt-3 flex flex-wrap gap-2">
                                <span
                                    v-for="item in action.loot_preview.slice(0, 3)"
                                    :key="item.item_key"
                                    class="tag capitalize"
                                >
                                    {{ item.item_name }}
                                    <span class="text-muted-3">· {{ item.rarity }} · {{ item.weight }} wt</span>
                                </span>
                                <span
                                    v-for="perk in (action.equipped_tool?.perks ?? []).slice(0, 2)"
                                    :key="`${action.key}-${perk.key}`"
                                    class="tag"
                                >
                                    {{ perk.label }}
                                </span>
                            </span>
                        </span>

                        <span class="text-left md:text-right">
                            <span v-if="!action.is_unlocked" class="block text-xs text-muted-3">Level {{ action.skill_level }} / {{ action.required_level }}</span>
                            <span v-else-if="canActNow" class="block text-sm font-ui text-success">Start</span>
                            <span v-else class="block text-sm font-ui text-muted-3">{{ cooldownLabel }}</span>
                        </span>
                    </button>

                    <p v-if="!visibleActions.length" class="rounded-md border border-border bg-surface-2 px-3 py-3 text-sm text-muted-2">
                        No resource runs match.
                    </p>
                </div>
            </div>

            <p v-if="form.errors.action" class="mt-4 text-sm text-(--accent-pink)">
                {{ form.errors.action }}
            </p>
        </div>
    </section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { actionReloadProps } from './reloadProps'

const props = defineProps({
    actions: {
        type: Array,
        required: true,
    },
    player: {
        type: Object,
        required: true,
    },
    searchTerm: {
        type: String,
        default: '',
    },
})

const selectedFilter = ref('All')
const now = ref(Date.now())
const autoRepeatEnabled = ref(false)
const repeatActionKey = ref('')
const autoRepeatGuardUntil = ref(0)
let cooldownTimer = null
const form = useForm({
    action: null,
})

const filters = computed(() => ['All', ...new Set(props.actions.map((action) => action.skill_label))].map((filter) => ({
    key: filter,
    label: filter,
    count: props.actions.filter((action) => filter === 'All' || action.skill_label === filter).length,
})))
const activeFilter = computed(() => filters.value.find((filter) => filter.key === selectedFilter.value) ?? filters.value[0])
const unlockedCount = computed(() => props.actions.filter((action) => action.is_unlocked).length)
const visibleActions = computed(() => props.actions.filter((action) => {
    const matchesFilter = selectedFilter.value === 'All' || action.skill_label === selectedFilter.value

    if (!matchesFilter) {
        return false
    }

    return searchMatches(action, props.searchTerm)
}))
const activeUnlockedCount = computed(() => visibleActions.value.filter((action) => action.is_unlocked).length)
const nextActionAt = computed(() => props.player.next_action_at ? new Date(props.player.next_action_at).getTime() : null)
const cooldownRemainingMs = computed(() => {
    if (!nextActionAt.value) {
        return 0
    }

    return Math.max(0, nextActionAt.value - now.value)
})
const canActNow = computed(() => props.player.can_act_now || cooldownRemainingMs.value <= 0)
const cooldownLabel = computed(() => {
    if (canActNow.value) {
        return 'Ready'
    }

    const totalSeconds = Math.ceil(cooldownRemainingMs.value / 1000)
    const minutes = Math.floor(totalSeconds / 60)
    const seconds = totalSeconds % 60

    return `${minutes}:${seconds.toString().padStart(2, '0')}`
})
const actionStateLabel = computed(() => (canActNow.value ? 'Ready' : `Ready in ${cooldownLabel.value}`))

onMounted(() => {
    cooldownTimer = window.setInterval(() => {
        now.value = Date.now()
        maybeRepeatAction()
    }, 1000)
})

onBeforeUnmount(() => {
    if (cooldownTimer) {
        window.clearInterval(cooldownTimer)
    }
})

function submitAction(action) {
    repeatActionKey.value = action
    autoRepeatGuardUntil.value = Date.now() + 750
    form.action = action
    form.post(route('evergather.actions.store'), {
        preserveScroll: true,
        only: actionReloadProps,
    })
}

function maybeRepeatAction() {
    if (!autoRepeatEnabled.value || !repeatActionKey.value || form.processing || !canActNow.value || Date.now() < autoRepeatGuardUntil.value) {
        return
    }

    const action = props.actions.find((entry) => entry.key === repeatActionKey.value)

    if (!action?.is_unlocked) {
        autoRepeatEnabled.value = false

        return
    }

    submitAction(repeatActionKey.value)
}

function searchMatches(action, query) {
    const normalizedQuery = query.trim().toLowerCase()

    if (!normalizedQuery) {
        return true
    }

    return [
        action.label,
        action.skill_label,
        action.location,
        action.active_event?.label,
        action.equipped_tool?.item_name,
        action.equipped_tool?.signature_trait,
        ...(action.loot_preview ?? []).flatMap((item) => [
            item.item_name,
            item.rarity,
            item.quality,
            item.item_class,
            item.material_family,
            ...(item.tags ?? []),
        ]),
    ].filter(Boolean).join(' ').toLowerCase().includes(normalizedQuery)
}
</script>
