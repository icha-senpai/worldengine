<template>
    <AuthenticatedLayout>

        <template #header>
            <div class="flex flex-col gap-2 sm:flex-row sm:items-baseline sm:justify-between">
                <h1 class="text-primary text-2xl font-light tracking-wide">Overview</h1>
                <span class="text-muted-3 text-sm font-ui">
                    {{ new Date().toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' }) }}
                </span>
            </div>
        </template>

        <!-- SESSION STATS STRIP -->
        <div class="grid grid-cols-2 border border-border rounded-md overflow-hidden mb-6 md:grid-cols-5">
            <div class="flex flex-col px-5 py-3 bg-surface-2 border-b border-r border-border md:border-b-0">
                <span class="text-primary text-xl font-light font-ui">{{ sessionStats.session_count }}</span>
                <span class="text-muted-3 text-xs uppercase tracking-widest mt-1">Sessions / 30d</span>
            </div>
            <div class="flex flex-col px-5 py-3 bg-surface-2 border-b border-border md:border-b-0 md:border-r">
                <span class="text-primary text-xl font-light font-ui">{{ sessionStats.major_count }}</span>
                <span class="text-muted-3 text-xs uppercase tracking-widest mt-1">Major sessions</span>
            </div>
            <div class="flex flex-col px-5 py-3 bg-surface-2 border-r border-border md:border-r">
                <span class="text-primary text-xl font-light font-ui">{{ latentTension.length }}</span>
                <span class="text-muted-3 text-xs uppercase tracking-widest mt-1">Latent tensions</span>
            </div>
            <div class="flex flex-col px-5 py-3 bg-surface-2 border-border md:border-r">
                <span class="text-primary text-xl font-light font-ui">{{ exposureRisk.length }}</span>
                <span class="text-muted-3 text-xs uppercase tracking-widest mt-1">High-risk secrets</span>
            </div>
            <div class="col-span-2 flex flex-col px-5 py-3 bg-surface-2 border-t border-border md:col-span-1 md:border-t-0">
                <span class="text-primary text-xl font-light font-ui">{{ blockingQuestions.length }}</span>
                <span class="text-muted-3 text-xs uppercase tracking-widest mt-1">Blocking questions</span>
            </div>
        </div>

        <!-- TWO COLUMN GRID -->
        <div class="grid grid-cols-1 gap-5 items-start xl:grid-cols-2">

            <!-- LEFT -->
            <div class="flex flex-col gap-5">

                <!-- RECENT PIPELINE -->
                <div class="bg-surface-2 border border-border rounded-md">
                    <div class="flex items-center justify-between px-4 py-3 border-b border-border">
                        <span class="text-xs font-ui uppercase tracking-widest text-muted-2">Recent Writing</span>
                        <a href="/writing-pipeline" class="text-xs font-ui uppercase tracking-wider text-muted-3 hover:text-muted transition-colors">All →</a>
                    </div>

                    <div v-if="recentPipeline.length === 0" class="px-4 py-8 text-center text-muted-3 text-sm font-ui">
                        No pipeline items yet.
                    </div>

                    <div v-for="item in recentPipeline" :key="item.id" class="flex flex-col gap-3 px-4 py-2.5 border-b border-border last:border-b-0 sm:flex-row sm:items-center">
                        <div class="flex-1 min-w-0">
                            <a :href="`/writing-pipeline/${item.id}`" class="prose-wrap block text-primary text-base font-light hover:text-focus transition-colors">
                                {{ item.title }}
                            </a>
                            <div class="flex flex-wrap items-center gap-2 mt-0.5">
                                <span class="text-muted-3 text-xs font-ui">{{ item.pipeline_type }}</span>
                                <span class="text-border-2">·</span>
                                <span class="text-muted-3 text-xs font-ui">{{ item.pipeline_stage }}</span>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0 self-start sm:self-auto">
                            <span v-if="item.word_count" class="text-muted-3 text-xs font-ui">
                                {{ item.word_count.toLocaleString() }}w
                            </span>
                            <span class="tag">{{ item.pipeline_stage }}</span>
                        </div>
                    </div>
                </div>

                <!-- BLOCKING QUESTIONS -->
                <div class="bg-surface-2 border border-border rounded-md">
                    <div class="flex items-center justify-between px-4 py-3 border-b border-border">
                        <span class="text-xs font-ui uppercase tracking-widest text-muted-2">Blocking Questions</span>
                        <span class="count count--danger">{{ blockingQuestions.length }}</span>
                    </div>

                    <div v-if="blockingQuestions.length === 0" class="px-4 py-8 text-center text-muted-3 text-sm font-ui">
                        No blocking questions.
                    </div>

                    <div v-for="q in blockingQuestions" :key="q.id" class="px-4 py-3 border-b border-border last:border-b-0">
                        <div class="flex flex-col gap-2 mb-1 sm:flex-row sm:items-center sm:justify-between">
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

            <!-- RIGHT -->
            <div class="flex flex-col gap-5">

                <!-- LATENT TENSION -->
                <div class="bg-surface-2 border border-border rounded-md">
                    <div class="flex items-center justify-between px-4 py-3 border-b border-border">
                        <div class="flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-focus animate-pulse" />
                            <span class="text-xs font-ui uppercase tracking-widest text-muted-2">Latent Tension</span>
                        </div>
                        <span class="count count--focus">{{ latentTension.length }}</span>
                    </div>
                    <p class="px-4 pt-2 text-xs text-muted-3 font-ui">Knows something true · has not acted</p>

                    <div v-if="latentTension.length === 0" class="px-4 py-8 text-center text-muted-3 text-sm font-ui">
                        No latent tension recorded.
                    </div>

                    <div v-for="state in latentTension" :key="state.id" class="px-4 py-2.5 border-b border-border last:border-b-0">
                        <div class="flex flex-col gap-2 mb-0.5 sm:flex-row sm:items-center sm:justify-between">
                            <a :href="`/entities/${state.knower.id}`" class="text-primary text-base font-light hover:text-focus transition-colors">
                                {{ state.knower.name }}
                            </a>
                            <span class="tag tag--warn">{{ state.knowledge_type.replace(/_/g, ' ') }}</span>
                        </div>
                        <div v-if="state.subject_name" class="flex flex-wrap items-center gap-1.5 text-[11px] text-muted-3 font-ui">
                            <span class="italic">re:</span>
                            <span class="text-muted-2">{{ state.subject_name }}</span>
                            <span v-if="state.current_belief_state" class="ml-auto tag">{{ state.current_belief_state }}</span>
                        </div>
                    </div>
                </div>

                <!-- EXPOSURE RISK -->
                <div class="bg-surface-2 border border-border rounded-md">
                    <div class="flex items-center justify-between px-4 py-3 border-b border-border">
                        <div class="flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-danger" />
                            <span class="text-xs font-ui uppercase tracking-widest text-muted-2">Exposure Risk</span>
                        </div>
                        <span class="count count--danger">{{ exposureRisk.length }}</span>
                    </div>
                    <p class="px-4 pt-2 text-xs text-muted-3 font-ui">High-risk secrets · active only</p>

                    <div v-if="exposureRisk.length === 0" class="px-4 py-8 text-center text-muted-3 text-sm font-ui">
                        No high-risk secrets.
                    </div>

                    <div v-for="secret in exposureRisk" :key="secret.id" class="px-4 py-2.5 border-b border-border last:border-b-0">
                        <div class="flex flex-col gap-2 mb-1 sm:flex-row sm:items-center sm:justify-between">
                            <a :href="`/secrets/${secret.id}`" class="text-primary text-base font-light hover:text-focus transition-colors">
                                {{ secret.title }}
                            </a>
                            <span class="tag" :class="secret.exposure_risk === 'critical' || secret.exposure_risk === 'inevitable' ? 'tag--danger' : 'tag--warn'">
                                {{ secret.exposure_risk }}
                            </span>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 text-xs text-muted-3 font-ui">
                            <span>{{ secret.holder_count }} holding</span>
                            <span class="text-border-2">·</span>
                            <span :class="secret.is_leaking ? 'text-focus' : ''">{{ secret.known_by_count }} know</span>
                            <span v-if="secret.is_leaking" class="ml-auto tag tag--focus" style="font-size:9px;">leaking</span>
                        </div>
                    </div>
                </div>

                <!-- PERCEPTION GAPS -->
                <div v-if="perceptionGaps.length > 0" class="bg-surface-2 border border-border rounded-md">
                    <div class="flex items-center justify-between px-4 py-3 border-b border-border">
                        <div class="flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-focus/50" />
                            <span class="text-xs font-ui uppercase tracking-widest text-muted-2">Perception Gaps</span>
                        </div>
                        <span class="count count--focus">{{ perceptionGaps.length }}</span>
                    </div>

                    <div v-for="state in perceptionGaps" :key="state.id" class="px-4 py-2.5 border-b border-border last:border-b-0">
                        <div class="flex flex-col gap-2 mb-0.5 sm:flex-row sm:items-center sm:justify-between">
                            <a :href="`/perception-states/${state.id}`" class="text-muted-2 text-sm font-ui italic hover:text-primary transition-colors">
                                {{ state.subject_type }} #{{ state.subject_id }}
                            </a>
                            <span class="tag" :class="state.revelation_risk === 'inevitable' || state.revelation_risk === 'imminent' ? 'tag--danger' : 'tag--warn'">
                                {{ state.revelation_risk }}
                            </span>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 text-xs text-muted-3 font-ui">
                            <span>{{ state.immune_count }} immune</span>
                            <span class="text-border-2">·</span>
                            <span>{{ state.maintainer_count }} maintaining</span>
                            <span class="text-border-2">·</span>
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

defineProps({
    recentPipeline:     { type: Array,  default: () => [] },
    sessionStats:       { type: Object, default: () => ({ session_count: 0, major_count: 0, tools_used: [] }) },
    latentTension:      { type: Array,  default: () => [] },
    exposureRisk:       { type: Array,  default: () => [] },
    perceptionGaps:     { type: Array,  default: () => [] },
    blockingQuestions:  { type: Array,  default: () => [] },
})
</script>
