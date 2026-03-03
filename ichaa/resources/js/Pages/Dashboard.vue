<template>
    <AuthenticatedLayout>

        <template #header>
            <div class="flex items-baseline justify-between">
                <h1 class="text-primary text-xl font-light tracking-wide">Overview</h1>
                <span class="text-muted-3 text-xs font-mono">
                    {{ new Date().toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' }) }}
                </span>
            </div>
        </template>

        <!-- SESSION STATS STRIP -->
        <div v-if="sessionStats" class="flex border border-border rounded-md overflow-hidden mb-6">
            <div v-for="stat in statItems" :key="stat.key" class="flex-1 flex flex-col px-5 py-3 bg-surface-2 border-r border-border last:border-r-0">
                <span class="text-primary text-xl font-light font-mono">{{ stat.value }}</span>
                <span class="text-muted-3 text-[10px] uppercase tracking-widest mt-1">{{ stat.label }}</span>
            </div>
        </div>

        <!-- TWO COLUMN GRID -->
        <div class="grid grid-cols-2 gap-5 items-start">

            <!-- LEFT: Writing -->
            <div class="flex flex-col gap-5">

                <!-- ACTIVE PROJECTS -->
                <div class="bg-surface-2 border border-border rounded-md">
                    <div class="flex items-center justify-between px-4 py-3 border-b border-border">
                        <span class="text-[10px] font-mono uppercase tracking-widest text-muted-2">Active Projects</span>
                        <a href="/meta" class="text-[10px] font-mono uppercase tracking-wider text-muted-3 hover:text-muted transition-colors">All →</a>
                    </div>

                    <div v-if="activeMeta.length === 0" class="px-4 py-8 text-center text-muted-3 text-xs font-mono">
                        No active projects.
                    </div>

                    <div v-for="meta in activeMeta" :key="meta.id" class="px-4 py-3 border-b border-border last:border-b-0">
                        <div class="flex items-center justify-between mb-2">
                            <a :href="`/meta/${meta.id}`" class="text-primary text-sm font-light hover:text-focus transition-colors">
                                {{ meta.title }}
                            </a>
                            <span class="tag">{{ meta.status }}</span>
                        </div>

                        <!-- Progress -->
                        <div v-if="meta.target_words" class="mb-2">
                            <div class="flex justify-between text-[10px] text-muted-3 font-mono mb-1">
                                <span>{{ (meta.word_count ?? 0).toLocaleString() }} / {{ meta.target_words.toLocaleString() }}</span>
                                <span>{{ meta.progress_percent ?? 0 }}%</span>
                            </div>
                            <div class="h-px bg-surface-3 rounded-full overflow-hidden">
                                <div
                                    class="h-full bg-focus rounded-full transition-all duration-300"
                                    :style="{ width: `${meta.progress_percent ?? 0}%` }"
                                />
                            </div>
                        </div>

                        <!-- Pipeline items -->
                        <div v-if="meta.pending_items?.length" class="flex flex-col gap-1 mt-2">
                            <div
                                v-for="item in meta.pending_items.slice(0, 4)"
                                :key="item.id"
                                class="flex items-center gap-2 text-xs text-muted-2 font-mono"
                            >
                                <span
                                    class="w-1.5 h-1.5 rounded-full flex-shrink-0"
                                    :class="item.priority === 'critical' ? 'bg-danger' : item.priority === 'high' ? 'bg-focus' : 'bg-muted-3'"
                                />
                                <span class="flex-1 truncate">{{ item.title }}</span>
                                <span class="tag" :class="item.priority === 'critical' ? 'tag--danger' : ''">{{ item.priority }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- BLOCKING QUESTIONS -->
                <div class="bg-surface-2 border border-border rounded-md">
                    <div class="flex items-center justify-between px-4 py-3 border-b border-border">
                        <span class="text-[10px] font-mono uppercase tracking-widest text-muted-2">Blocking Questions</span>
                        <span class="count count--danger">{{ blockingQuestions.length }}</span>
                    </div>

                    <div v-if="blockingQuestions.length === 0" class="px-4 py-8 text-center text-muted-3 text-xs font-mono">
                        No blocking questions.
                    </div>

                    <div v-for="q in blockingQuestions" :key="q.id" class="px-4 py-3 border-b border-border last:border-b-0">
                        <div class="flex items-center justify-between mb-1">
                            <a :href="`/entities/${q.entity.id}`" class="text-[10px] font-mono uppercase tracking-wider text-muted-2 hover:text-focus transition-colors">
                                {{ q.entity.name }}
                            </a>
                            <span class="tag" :class="q.priority === 'critical' ? 'tag--danger' : 'tag--warn'">{{ q.priority }}</span>
                        </div>
                        <p class="text-primary text-xs leading-relaxed">{{ q.question }}</p>
                    </div>
                </div>

                <!-- INCOMPLETE ENTITIES -->
                <div v-if="incompleteEntities.length > 0" class="bg-surface-2 border border-border rounded-md">
                    <div class="flex items-center justify-between px-4 py-3 border-b border-border">
                        <span class="text-[10px] font-mono uppercase tracking-widest text-muted-2">Incomplete Entities</span>
                        <a href="/entities?incomplete=1" class="text-[10px] font-mono uppercase tracking-wider text-muted-3 hover:text-muted transition-colors">All →</a>
                    </div>
                    <div v-for="entity in incompleteEntities" :key="entity.id" class="flex items-center gap-3 px-4 py-2.5 border-b border-border last:border-b-0">
                        <div class="flex-1 min-w-0">
                            <a :href="`/entities/${entity.id}`" class="text-primary text-sm font-light hover:text-focus transition-colors truncate block">
                                {{ entity.name }}
                            </a>
                            <span class="text-muted-3 text-[10px] font-mono">{{ entity.entity_type }}</span>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0">
                            <div class="w-16 h-px bg-surface-3 rounded-full overflow-hidden">
                                <div class="h-full bg-focus/60 rounded-full" :style="{ width: `${entity.completion_score ?? 0}%` }" />
                            </div>
                            <span class="text-[10px] font-mono text-muted-3">{{ entity.completion_score ?? 0 }}%</span>
                        </div>
                    </div>
                </div>

            </div>

            <!-- RIGHT: Tension & Risk -->
            <div class="flex flex-col gap-5">

                <!-- LATENT TENSION -->
                <div class="bg-surface-2 border border-border rounded-md">
                    <div class="flex items-center justify-between px-4 py-3 border-b border-border">
                        <div class="flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-focus animate-pulse" />
                            <span class="text-[10px] font-mono uppercase tracking-widest text-muted-2">Latent Tension</span>
                        </div>
                        <span class="count count--focus">{{ latentTension.length }}</span>
                    </div>
                    <p class="px-4 pt-2 pb-0 text-[10px] text-muted-3 font-mono">
                        Knows something true · has not acted
                    </p>

                    <div v-if="latentTension.length === 0" class="px-4 py-8 text-center text-muted-3 text-xs font-mono">
                        No latent tension recorded.
                    </div>

                    <div v-for="state in latentTension" :key="state.id" class="px-4 py-2.5 border-b border-border last:border-b-0">
                        <div class="flex items-center justify-between mb-0.5">
                            <a :href="`/entities/${state.knower.id}`" class="text-primary text-sm font-light hover:text-focus transition-colors">
                                {{ state.knower.name }}
                            </a>
                            <span class="tag tag--warn">{{ state.knowledge_type.replace('_', ' ') }}</span>
                        </div>
                        <div v-if="state.subject_name" class="flex items-center gap-1.5 text-[11px] text-muted-3 font-mono">
                            <span class="italic">re:</span>
                            <span class="text-muted-2">{{ state.subject_name }}</span>
                            <span class="ml-auto tag">{{ state.belief_state }}</span>
                        </div>
                    </div>
                </div>

                <!-- EXPOSURE RISK -->
                <div class="bg-surface-2 border border-border rounded-md">
                    <div class="flex items-center justify-between px-4 py-3 border-b border-border">
                        <div class="flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-danger" />
                            <span class="text-[10px] font-mono uppercase tracking-widest text-muted-2">Exposure Risk</span>
                        </div>
                        <span class="count count--danger">{{ exposureRisk.length }}</span>
                    </div>
                    <p class="px-4 pt-2 pb-0 text-[10px] text-muted-3 font-mono">
                        High-risk secrets · sorted by structural pressure
                    </p>

                    <div v-if="exposureRisk.length === 0" class="px-4 py-8 text-center text-muted-3 text-xs font-mono">
                        No high-risk secrets.
                    </div>

                    <div v-for="secret in exposureRisk" :key="secret.id" class="px-4 py-2.5 border-b border-border last:border-b-0">
                        <div class="flex items-center justify-between mb-1">
                            <a :href="`/secrets/${secret.id}`" class="text-primary text-sm font-light hover:text-focus transition-colors">
                                {{ secret.title }}
                            </a>
                            <span class="tag" :class="secret.exposure_risk === 'critical' ? 'tag--danger' : 'tag--warn'">
                                {{ secret.exposure_risk }}
                            </span>
                        </div>
                        <div class="flex items-center gap-2 text-[10px] text-muted-3 font-mono">
                            <span>{{ secret.holder_count }} holding</span>
                            <span class="text-border-2">·</span>
                            <span :class="secret.is_leaking ? 'text-focus' : ''">{{ secret.known_by_count }} know</span>
                            <span v-if="secret.is_leaking" class="ml-auto tag tag--focus text-[9px]">leaking</span>
                        </div>
                    </div>
                </div>

                <!-- PERCEPTION GAPS -->
                <div v-if="immuneTension.length > 0" class="bg-surface-2 border border-border rounded-md">
                    <div class="flex items-center justify-between px-4 py-3 border-b border-border">
                        <div class="flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-focus/50" />
                            <span class="text-[10px] font-mono uppercase tracking-widest text-muted-2">Perception Gaps</span>
                        </div>
                        <span class="count count--focus">{{ immuneTension.length }}</span>
                    </div>

                    <div v-for="state in immuneTension.slice(0, 6)" :key="state.id" class="px-4 py-2.5 border-b border-border last:border-b-0">
                        <div class="flex items-center justify-between mb-0.5">
                            <a :href="`/perception-states/${state.id}`" class="text-muted-2 text-xs font-mono italic hover:text-primary transition-colors">
                                {{ state.subject_type }} #{{ state.subject_id }}
                            </a>
                            <span class="tag" :class="state.revelation_risk === 'inevitable' ? 'tag--danger' : 'tag--warn'">
                                {{ state.revelation_risk }}
                            </span>
                        </div>
                        <div class="flex items-center gap-2 text-[10px] text-muted-3 font-mono">
                            <span>{{ state.immune_count }} immune</span>
                            <span class="text-border-2">·</span>
                            <span>{{ state.maintainer_count }} maintaining</span>
                            <span class="text-border-2">·</span>
                            <span :class="state.tension_ratio >= 1 ? 'text-focus' : ''">ratio {{ state.tension_ratio }}</span>
                        </div>
                    </div>
                </div>

                <!-- UNRESOLVED POWER INTERACTIONS -->
                <div v-if="unresolvedInteractions.length > 0" class="bg-surface-2 border border-border rounded-md">
                    <div class="flex items-center justify-between px-4 py-3 border-b border-border">
                        <span class="text-[10px] font-mono uppercase tracking-widest text-muted-2">Unresolved Interactions</span>
                        <span class="count">{{ unresolvedInteractions.length }}</span>
                    </div>

                    <div v-for="i in unresolvedInteractions" :key="i.id" class="px-4 py-2.5 border-b border-border last:border-b-0">
                        <a :href="`/power-interactions/${i.id}`" class="block text-primary text-sm font-light hover:text-focus transition-colors mb-1">
                            {{ i.name }}
                        </a>
                        <div class="flex items-center gap-2 text-[10px] text-muted-3 font-mono">
                            <span>{{ i.system_a }}</span>
                            <span class="text-border-2">×</span>
                            <span>{{ i.system_b }}</span>
                            <span
                                class="ml-auto tag"
                                :class="['catastrophic','existential_risk'].includes(i.danger_rating) ? 'tag--danger' : ''"
                            >{{ i.danger_rating?.replace('_', ' ') }}</span>
                        </div>
                    </div>
                </div>

            </div>
        </div>

    </AuthenticatedLayout>
</template>

<script setup>
import { computed } from 'vue'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

const props = defineProps({
    activeMeta:             { type: Array, default: () => [] },
    sessionStats:           { type: Object, default: null },
    latentTension:          { type: Array, default: () => [] },
    exposureRisk:           { type: Array, default: () => [] },
    immuneTension:          { type: Array, default: () => [] },
    blockingQuestions:      { type: Array, default: () => [] },
    unresolvedInteractions: { type: Array, default: () => [] },
    incompleteEntities:     { type: Array, default: () => [] },
})

const statItems = computed(() => {
    if (!props.sessionStats) return []
    return [
        { key: 'sessions',  value: props.sessionStats.session_count,                        label: 'Sessions / 30d' },
        { key: 'words',     value: (props.sessionStats.total_words ?? 0).toLocaleString(),   label: 'Words written' },
        { key: 'created',   value: props.sessionStats.entities_created,                      label: 'Entities created' },
        { key: 'modified',  value: props.sessionStats.entities_modified,                     label: 'Entities modified' },
        { key: 'mood',      value: props.sessionStats.average_mood ? `${props.sessionStats.average_mood}/5` : '—', label: 'Avg mood' },
    ]
})
</script>

<style scoped>
/* Tag pill */
.tag {
    display: inline-flex;
    align-items: center;
    padding: 1px 6px;
    border-radius: 2px;
    font-size: 9px;
    font-family: ui-monospace, monospace;
    font-weight: 400;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    border: 1px solid var(--border-color);
    color: var(--text-muted-3);
    background: var(--bg-surface-3);
    flex-shrink: 0;
}
.tag--warn {
    color: var(--accent-cyan);
    border-color: rgb(0 245 255 / 0.25);
    background: rgb(0 245 255 / 0.07);
}
.tag--danger {
    color: var(--accent-pink);
    border-color: rgb(255 46 159 / 0.25);
    background: rgb(255 46 159 / 0.07);
}
.tag--focus {
    color: var(--accent-cyan-2);
    border-color: rgb(0 245 255 / 0.2);
    background: rgb(0 245 255 / 0.05);
}

/* Count badge */
.count {
    font-size: 11px;
    font-family: ui-monospace, monospace;
    padding: 1px 8px;
    border-radius: 10px;
    border: 1px solid var(--border-color);
    color: var(--text-muted-3);
    background: var(--bg-surface-3);
}
.count--focus {
    color: var(--accent-cyan);
    border-color: rgb(0 245 255 / 0.25);
    background: rgb(0 245 255 / 0.07);
}
.count--danger {
    color: var(--accent-pink);
    border-color: rgb(255 46 159 / 0.25);
    background: rgb(255 46 159 / 0.07);
}
</style>