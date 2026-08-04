<template>
    <section class="surface-section xl:col-span-2">
        <div class="surface-section__header">
            <div class="surface-section__copy">
                <span class="surface-section__title">Leaderboards</span>
                <p class="surface-section__subtitle">{{ totalEntries }} ranked records across {{ boardDefinitions.length }} boards.</p>
            </div>
        </div>

        <div class="surface-section__body">
            <div class="grid gap-4 xl:grid-cols-[16rem_minmax(0,1fr)]">
                <div class="rounded-md border border-border bg-surface-2 px-3 py-3">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-sm font-ui text-primary">Rank Circuit</p>
                        <span class="tag">{{ boardCount(activeBoard) }}</span>
                    </div>
                    <p class="mt-1 text-xs text-muted-3">{{ activeGroupLabel }} boards.</p>

                    <div class="mt-4 grid gap-2">
                        <button
                            v-for="board in activeBoards"
                            :key="board.key"
                            type="button"
                            class="grid gap-1 rounded-md border border-border bg-canvas px-3 py-2 text-left transition hover:border-focus/60"
                            :class="{ 'border-focus/70 bg-focus/10': activeBoard === board.key }"
                            @click="activeBoard = board.key"
                        >
                            <span class="flex min-w-0 items-center justify-between gap-2">
                                <span class="truncate text-xs font-ui text-primary">{{ board.label }}</span>
                                <span class="text-[11px] text-muted-3">{{ boardCount(board.key) }}</span>
                            </span>
                            <span class="text-[11px] text-muted-3">{{ board.description }}</span>
                        </button>
                    </div>

                    <div class="mt-4 border-t border-border/70 pt-3">
                        <p class="text-xs font-ui uppercase tracking-[0.14em] text-muted-3">Scope</p>
                    </div>

                    <div class="mt-2 grid gap-2">
                        <button
                            v-for="group in leaderboards.groups"
                            :key="group.key"
                            type="button"
                            class="grid gap-1 rounded-md border border-border bg-canvas px-3 py-2 text-left transition hover:border-focus/60"
                            :class="{ 'border-focus/70 bg-focus/10': activeGroup === group.key }"
                            @click="activeGroup = group.key"
                        >
                            <span class="flex min-w-0 items-center justify-between gap-2">
                                <span class="truncate text-xs font-ui text-primary">{{ group.label }}</span>
                                <span class="text-[11px] text-muted-3">{{ group.count }}</span>
                            </span>
                        </button>
                    </div>
                </div>

                <div class="grid content-start gap-4">
                    <div class="rounded-md border border-border bg-surface-2 px-3 py-3">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-ui text-primary">{{ activeBoardDefinition.label }}</p>
                                <p class="mt-1 text-xs text-muted-2">{{ activeBoardDefinition.description }}</p>
                            </div>
                            <span class="tag">{{ boardCount(activeBoard) }} ranked</span>
                        </div>

                        <div v-if="activeEntries.length" class="mt-4 grid gap-2">
                            <article
                                v-for="(entry, index) in activeEntries"
                                :key="`${activeBoard}-${entry.id ?? entry.skill ?? index}`"
                                class="grid min-h-24 items-start gap-3 rounded-md border border-border bg-canvas px-3 py-3 md:grid-cols-[3rem_minmax(0,1fr)_7rem]"
                            >
                                <div class="grid h-9 w-9 place-items-center rounded-md border border-border bg-surface-2 text-sm font-ui text-primary">
                                    #{{ index + 1 }}
                                </div>

                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="min-w-0 truncate text-sm font-ui text-primary">{{ entry.display_name }}</p>
                                        <span v-if="entry.species_label" class="tag">{{ entry.species_label }}</span>
                                        <span v-if="entry.skill_label" class="tag">{{ entry.skill_label }}</span>
                                    </div>
                                    <p v-if="entry.detail" class="mt-1 text-xs text-muted-2">{{ entry.detail }}</p>
                                </div>

                                <div class="text-left md:text-right">
                                    <p class="text-sm font-ui text-primary">{{ entry.score_label }}</p>
                                    <p v-if="entry.score !== undefined" class="mt-1 text-xs text-muted-3">{{ entry.score.toLocaleString() }} score</p>
                                </div>
                            </article>
                        </div>

                        <p v-else class="mt-4 text-sm text-muted-2">
                            No records yet.
                        </p>
                    </div>

                    <div class="grid gap-3 md:grid-cols-3">
                        <div
                            v-for="highlight in highlights"
                            :key="highlight.label"
                            class="rounded-md border border-border bg-surface-2 px-3 py-3"
                        >
                            <p class="text-xs uppercase tracking-[0.14em] text-muted-3">{{ highlight.label }}</p>
                            <p class="mt-2 text-lg font-ui text-primary">{{ highlight.value }}</p>
                            <p class="mt-1 text-xs text-muted-2">{{ highlight.detail }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({
    leaderboards: {
        type: Object,
        required: true,
    },
})

const activeGroup = ref(props.leaderboards.groups[0]?.key ?? 'summary')
const activeBoard = ref(props.leaderboards.groups[0]?.boards[0] ?? 'wealth')
const boardDefinitions = computed(() => props.leaderboards.boards ?? [])
const boardDefinitionMap = computed(() => Object.fromEntries(boardDefinitions.value.map((board) => [board.key, board])))
const activeGroupRecord = computed(() => props.leaderboards.groups.find((group) => group.key === activeGroup.value) ?? props.leaderboards.groups[0])
const activeGroupLabel = computed(() => activeGroupRecord.value?.label ?? 'Leaderboards')
const activeBoards = computed(() => (activeGroupRecord.value?.boards ?? []).map((key) => boardDefinitionMap.value[key]).filter(Boolean))
const activeBoardDefinition = computed(() => boardDefinitionMap.value[activeBoard.value] ?? activeBoards.value[0] ?? boardDefinitions.value[0])
const activeEntries = computed(() => props.leaderboards[activeBoard.value] ?? [])
const totalEntries = computed(() => boardDefinitions.value.reduce((total, board) => total + boardCount(board.key), 0))
const highlights = computed(() => [
    highlightFor('Top Gold', props.leaderboards.wealth[0], 'gold'),
    highlightFor('Top Renown', props.leaderboards.realm_score[0], 'score'),
    highlightFor('Top Skill', props.leaderboards.skills[0], 'experience'),
])

watch(activeGroupRecord, (group) => {
    if (!group?.boards.includes(activeBoard.value)) {
        activeBoard.value = group?.boards[0] ?? 'wealth'
    }
})

function boardCount(key) {
    return props.leaderboards[key]?.length ?? 0
}

function highlightFor(label, entry, metric) {
    if (!entry) {
        return { label, value: 'None', detail: 'No records yet.' }
    }

    return {
        label,
        value: entry.display_name,
        detail: entry.score_label ?? `${entry[metric]?.toLocaleString() ?? 0} ${metric}`,
    }
}
</script>
