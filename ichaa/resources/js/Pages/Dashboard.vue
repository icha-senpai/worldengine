<template>
    <AuthenticatedLayout>

        <template #header>
            <div class="page-hero">
                <div class="page-hero__copy">
                    <div class="page-hero__eyebrow">Workspace overview</div>
                    <h1 class="page-hero__title page-hero__title--md">Overview</h1>
                    <p class="page-hero__subtitle">
                        Track recent writing movement, active pressure points, and the threads most likely to bend the canon next.
                    </p>
                </div>
                <div class="page-hero__meta">
                    <span class="tag">
                        {{ new Date().toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' }) }}
                    </span>
                </div>
            </div>
        </template>

        <div class="dashboard-metric-strip mb-6">
            <div class="dashboard-metric">
                <span class="dashboard-metric__value">{{ sessionStats.session_count }}</span>
                <span class="dashboard-metric__label">Sessions / 30d</span>
            </div>
            <div class="dashboard-metric">
                <span class="dashboard-metric__value">{{ sessionStats.major_count }}</span>
                <span class="dashboard-metric__label">Major sessions</span>
            </div>
            <div class="dashboard-metric">
                <span class="dashboard-metric__value">{{ latentTension.length }}</span>
                <span class="dashboard-metric__label">Latent tensions</span>
            </div>
            <div class="dashboard-metric">
                <span class="dashboard-metric__value">{{ exposureRisk.length }}</span>
                <span class="dashboard-metric__label">High-risk secrets</span>
            </div>
            <div class="dashboard-metric">
                <span class="dashboard-metric__value">{{ blockingQuestions.length }}</span>
                <span class="dashboard-metric__label">Blocking questions</span>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-5 items-start xl:grid-cols-2">
            <div class="flex flex-col gap-5">
                <div class="surface-section">
                    <div class="surface-section__header">
                        <div class="surface-section__copy">
                            <span class="surface-section__title">Recent Writing</span>
                            <p class="surface-section__subtitle">Newest movement across pipeline items, chapter work, and supporting notes.</p>
                        </div>
                        <a :href="route('pipeline.index')" class="dashboard-link">All -></a>
                    </div>

                    <div v-if="recentPipeline.length === 0" class="surface-row text-center text-muted-3 text-sm font-ui">
                        No pipeline items yet.
                    </div>

                    <div v-for="item in recentPipeline" :key="item.id" class="surface-row flex flex-col gap-3 sm:flex-row sm:items-center">
                        <div class="flex-1 min-w-0">
                            <a :href="route('pipeline.show', item.id)" class="prose-wrap block text-primary text-base font-light hover:text-focus transition-colors">
                                {{ item.title }}
                            </a>
                            <div class="mt-1 flex flex-wrap items-center gap-2">
                                <span class="text-muted-3 text-xs font-ui">{{ item.pipeline_type }}</span>
                                <span class="text-border-2">&middot;</span>
                                <span class="text-muted-3 text-xs font-ui">{{ item.pipeline_stage }}</span>
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-2 self-start sm:self-auto">
                            <span v-if="item.word_count" class="text-muted-3 text-xs font-ui">
                                {{ item.word_count.toLocaleString() }}w
                            </span>
                            <span class="tag">{{ item.pipeline_stage }}</span>
                        </div>
                    </div>
                </div>

                <div class="surface-section">
                    <div class="surface-section__header">
                        <div class="surface-section__copy">
                            <span class="surface-section__title">Blocking Questions</span>
                            <p class="surface-section__subtitle">Open uncertainties still blocking choices, scenes, or structural calls.</p>
                        </div>
                        <span class="count count--danger">{{ blockingQuestions.length }}</span>
                    </div>

                    <div v-if="blockingQuestions.length === 0" class="surface-row text-center text-muted-3 text-sm font-ui">
                        No blocking questions.
                    </div>

                    <div v-for="q in blockingQuestions" :key="q.id" class="surface-row">
                        <div class="mb-1 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <a :href="`/entities/${q.entity.id}`" class="text-xs font-ui uppercase tracking-wider text-muted-2 hover:text-focus transition-colors">
                                {{ q.entity.name }}
                            </a>
                            <span v-if="q.priority" class="tag" :class="q.priority === 'critical' ? 'tag--danger' : 'tag--warn'">
                                {{ q.priority }}
                            </span>
                        </div>
                        <p class="text-primary text-sm leading-relaxed">{{ q.question }}</p>
                    </div>
                </div>
            </div>

            <div class="flex flex-col gap-5">
                <div class="surface-section">
                    <div class="surface-section__header">
                        <div class="surface-section__copy">
                            <div class="flex items-center gap-2">
                                <span class="w-1.5 h-1.5 rounded-full bg-focus animate-pulse" />
                                <span class="surface-section__title">Latent Tension</span>
                            </div>
                            <p class="surface-section__subtitle">Knows something true and still has not acted on it.</p>
                        </div>
                        <span class="count count--focus">{{ latentTension.length }}</span>
                    </div>

                    <div v-if="latentTension.length === 0" class="surface-row text-center text-muted-3 text-sm font-ui">
                        No latent tension recorded.
                    </div>

                    <div v-for="state in latentTension" :key="state.id" class="surface-row">
                        <div class="mb-0.5 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <a :href="`/entities/${state.knower.id}`" class="text-primary text-base font-light hover:text-focus transition-colors">
                                {{ state.knower.name }}
                            </a>
                            <span class="tag tag--warn">{{ formatLabel(state.knowledge_type) }}</span>
                        </div>
                        <div v-if="state.subject_name" class="flex flex-wrap items-center gap-1.5 text-[11px] text-muted-3 font-ui">
                            <span class="italic">re:</span>
                            <span class="text-muted-2">{{ state.subject_name }}</span>
                            <span v-if="state.current_belief_state" class="ml-auto tag">{{ state.current_belief_state }}</span>
                        </div>
                    </div>
                </div>

                <div class="surface-section">
                    <div class="surface-section__header">
                        <div class="surface-section__copy">
                            <div class="flex items-center gap-2">
                                <span class="w-1.5 h-1.5 rounded-full bg-danger" />
                                <span class="surface-section__title">Exposure Risk</span>
                            </div>
                            <p class="surface-section__subtitle">High-risk secrets that are active and likely to break containment.</p>
                        </div>
                        <span class="count count--danger">{{ exposureRisk.length }}</span>
                    </div>

                    <div v-if="exposureRisk.length === 0" class="surface-row text-center text-muted-3 text-sm font-ui">
                        No high-risk secrets.
                    </div>

                    <div v-for="secret in exposureRisk" :key="secret.id" class="surface-row">
                        <div class="mb-1 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <a :href="`/secrets/${secret.id}`" class="text-primary text-base font-light hover:text-focus transition-colors">
                                {{ secret.title }}
                            </a>
                            <span class="tag" :class="secret.exposure_risk === 'critical' || secret.exposure_risk === 'inevitable' ? 'tag--danger' : 'tag--warn'">
                                {{ secret.exposure_risk }}
                            </span>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 text-xs text-muted-3 font-ui">
                            <span>{{ secret.holder_count }} holding</span>
                            <span class="text-border-2">&middot;</span>
                            <span :class="secret.is_leaking ? 'text-focus' : ''">{{ secret.known_by_count }} know</span>
                            <span v-if="secret.is_leaking" class="ml-auto tag tag--focus" style="font-size:9px;">leaking</span>
                        </div>
                    </div>
                </div>

                <div v-if="perceptionGaps.length > 0" class="surface-section">
                    <div class="surface-section__header">
                        <div class="surface-section__copy">
                            <div class="flex items-center gap-2">
                                <span class="w-1.5 h-1.5 rounded-full bg-focus/50" />
                                <span class="surface-section__title">Perception Gaps</span>
                            </div>
                            <p class="surface-section__subtitle">Subjects at risk of revelation because too few people can sustain the illusion.</p>
                        </div>
                        <span class="count count--focus">{{ perceptionGaps.length }}</span>
                    </div>

                    <div v-for="state in perceptionGaps" :key="state.id" class="surface-row">
                        <div class="mb-0.5 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <a :href="`/perception-states/${state.id}`" class="text-muted-2 text-sm font-ui italic hover:text-primary transition-colors">
                                {{ state.subject_type }} #{{ state.subject_id }}
                            </a>
                            <span class="tag" :class="state.revelation_risk === 'inevitable' || state.revelation_risk === 'imminent' ? 'tag--danger' : 'tag--warn'">
                                {{ state.revelation_risk }}
                            </span>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 text-xs text-muted-3 font-ui">
                            <span>{{ state.immune_count }} immune</span>
                            <span class="text-border-2">&middot;</span>
                            <span>{{ state.maintainer_count }} maintaining</span>
                            <span class="text-border-2">&middot;</span>
                            <span :class="state.tension_ratio >= 1 ? 'text-focus' : ''">ratio {{ state.tension_ratio }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { formatLabel } from '@/Components/scaffold/formatters'

defineProps({
    recentPipeline:     { type: Array,  default: () => [] },
    sessionStats:       { type: Object, default: () => ({ session_count: 0, major_count: 0, tools_used: [] }) },
    latentTension:      { type: Array,  default: () => [] },
    exposureRisk:       { type: Array,  default: () => [] },
    perceptionGaps:     { type: Array,  default: () => [] },
    blockingQuestions:  { type: Array,  default: () => [] },
})
</script>
