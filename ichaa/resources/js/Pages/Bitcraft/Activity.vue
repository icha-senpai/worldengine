<template>
    <WidgetPageShell
        :setup="setupPageVisible"
        title="Live Activity"
        description="Configure XP goals, watched skills, and the live OBS widget styling."
    >
        <main
            class="activity-source"
            :class="{ 'activity-source--setup': setupVisible, 'activity-source--in-app': setupPageVisible }"
            :style="widgetThemeStyle"
        >
        <form v-if="setupVisible" class="activity-setup" @submit.prevent="submitSetup(true)">
            <div class="activity-setup__grid">
                <label>
                    <span>Title</span>
                    <input v-model.trim="form.title" type="text" maxlength="80" />
                </label>

                <label>
                    <span>Emojis</span>
                    <input v-model.trim="form.icons" type="text" maxlength="40" />
                </label>

                <label>
                    <span>Character</span>
                    <input v-model.trim="form.character" type="text" maxlength="80" />
                </label>

                <label>
                    <span>Skill Scope</span>
                    <select v-model="form.skill">
                        <option value="all">All skills</option>
                        <option
                            v-for="skill in skillOptions"
                            :key="skill.id"
                            :value="String(skill.id)"
                        >
                            {{ skill.name }}
                        </option>
                    </select>
                </label>
            </div>

            <WidgetThemeControls :model="form" @update="updateTheme" />

            <div ref="pickerElement" class="activity-picker">
                <label>
                    <span>XP / level goals</span>
                    <input
                        v-model.trim="form.skillSearch"
                        type="search"
                        autocomplete="off"
                        placeholder="Search skills"
                        @focus="pickerOpen = true"
                    />
                </label>

                <div v-if="pickerOpen" class="activity-picker__menu">
                    <button
                        v-for="option in filteredSkillOptions"
                        :key="option.id"
                        type="button"
                        class="activity-picker__option"
                        :class="{ 'selected': form.skillKeys.includes(String(option.id)) }"
                        @click="selectSkill(option)"
                    >
                        <span>{{ option.name }}</span>
                        <small>Lv {{ formatNumber(option.level) }} · {{ formatNumber(option.xp) }} XP</small>
                    </button>

                    <p v-if="filteredSkillOptions.length === 0">No skills loaded.</p>
                </div>
            </div>

            <div v-if="selectedSkillOptions.length" class="activity-selected">
                <span v-for="skill in selectedSkillOptions" :key="skill.id">
                    <strong>{{ skill.name }}</strong>
                    <label>
                        <small>Goal level</small>
                        <input
                            v-model.number="form.skillGoalLevels[String(skill.id)]"
                            type="number"
                            min="1"
                            max="999"
                            :placeholder="skill.nextLevel ? String(skill.nextLevel) : 'Level'"
                        />
                    </label>
                    <label>
                        <small>Goal XP</small>
                        <input
                            v-model.number="form.skillGoalXp[String(skill.id)]"
                            type="number"
                            min="1"
                            max="999999999"
                            :placeholder="skill.nextLevelXp ? String(skill.nextLevelXp) : 'XP'"
                        />
                    </label>
                    <button type="button" @click="removeSkill(String(skill.id))">Remove</button>
                </span>
            </div>

            <div class="activity-setup__actions">
                <button type="submit">Search / Update</button>
                <button type="button" @click="submitSetup(false)">Widget Mode</button>
            </div>
        </form>

        <section class="activity-widget" aria-label="Live Bitcraft activity tracker">
            <header class="activity-widget__header">
                <div class="activity-widget__identity">
                    <p class="activity-widget__eyebrow">{{ titleLabel }}</p>
                    <h1>{{ tracker?.player?.username ?? form.character }}</h1>
                </div>

                <div class="activity-widget__level">
                    <p>{{ activitySummaryLabel }}</p>
                    <time>{{ iconsLabel || clockLabel }}</time>
                </div>
            </header>

            <div v-if="blockingError" class="activity-widget__error">
                {{ error }}
            </div>

            <template v-else>
                <div v-if="error" class="activity-widget__warning">
                    {{ error }}
                </div>

                <div class="activity-widget__progress" aria-hidden="true">
                    <span :style="{ width: `${summaryProgressPercent}%` }" />
                </div>

                <div class="activity-widget__metrics">
                    <article v-if="!goalMode && activeSkillStats.length" class="activity-widget__metric activity-widget__metric--accent">
                        <div class="activity-widget__metric-row">
                            <div class="activity-widget__metric-item">
                                <p>Total XP Rate</p>
                                <small>{{ totalRecentXpLabel }}</small>
                            </div>

                            <div class="activity-widget__metric-count">
                                <small>Current Rate</small>
                                <strong>{{ totalXpRateLabel }}</strong>
                            </div>
                        </div>
                    </article>

                    <article
                        v-for="stat in displaySkillStats"
                        :key="stat.skill.id"
                        class="activity-widget__metric"
                    >
                        <div class="activity-widget__metric-row">
                            <div class="activity-widget__metric-item">
                                <p>{{ stat.skill.name }}</p>
                                <small>{{ skillDetailLabel(stat) }}</small>
                            </div>

                            <div class="activity-widget__metric-count">
                                <small>{{ skillCountLabel(stat) }}</small>
                                <strong>{{ skillPrimaryLabel(stat) }}</strong>
                            </div>
                        </div>

                        <div class="activity-widget__mini-progress" aria-hidden="true">
                            <span :style="{ width: `${displayProgressPercent(stat)}%` }" />
                        </div>
                    </article>

                    <article v-if="!displaySkillStats.length" class="activity-widget__metric activity-widget__metric--accent">
                        <div class="activity-widget__metric-row">
                            <div class="activity-widget__metric-item">
                                <p>{{ goalMode ? 'XP Goals' : 'All XP' }}</p>
                                <small>{{ waitingLabel }}</small>
                            </div>

                            <div class="activity-widget__metric-count">
                                <small>Status</small>
                                <strong>Waiting</strong>
                            </div>
                        </div>
                    </article>
                </div>
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
    filters: { type: Object, default: () => ({ character: 'icha', skill: 'all' }) },
    snapshot: { type: Object, default: () => ({ tracker: null, error: null, sampledAt: null }) },
    pollUrl: { type: String, required: true },
})

const POLL_INTERVAL_MS = 10 * 1000
const MIN_RATE_SAMPLE_MS = 60 * 1000
const RATE_SAMPLE_MS = 5 * 60 * 1000
const SAMPLE_WINDOW_MS = RATE_SAMPLE_MS + MIN_RATE_SAMPLE_MS
const ACTIVE_STAT_GRACE_MS = 15 * 60 * 1000
const STORAGE_KEY = 'bitcraft.activityTracker.lastSetup'
const SAMPLE_STORAGE_PREFIX = 'bitcraft.activityTracker.samples.'

const tracker = ref(props.snapshot.tracker)
const error = ref(props.snapshot.error)
const sampledAt = ref(props.snapshot.sampledAt)
const samples = ref([])
const now = ref(new Date())
const pickerOpen = ref(false)
const pickerElement = ref(null)
const lastActiveSkillStats = ref([])
const lastActiveSkillStatsAt = ref(0)
let pollTimer = null
let clockTimer = null
let restoredSetup = false

const parseSkillKeys = (value) => {
    const keys = Array.isArray(value) ? value : String(value ?? '').split(',')

    return [...new Set(keys
        .map((key) => String(key).trim())
        .filter((key) => /^\d+$/.test(key)))]
}

const parseGoalMap = (value) => {
    if (value && typeof value === 'object' && !Array.isArray(value)) {
        return Object.fromEntries(Object.entries(value)
            .map(([key, goal]) => [String(key), Number(goal)])
            .filter(([key, goal]) => /^\d+$/.test(key) && Number.isFinite(goal) && goal > 0))
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
        .filter(([key, goal]) => /^\d+$/.test(key) && Number.isFinite(goal) && goal > 0))
}

const formatGoalMap = (goals) => Object.entries(goals)
    .filter(([key, goal]) => /^\d+$/.test(key) && Number.isFinite(Number(goal)) && Number(goal) > 0)
    .map(([key, goal]) => `${key}=${Math.round(Number(goal))}`)
    .join(',')

const form = reactive({
    source: props.filters.source ?? 'default',
    character: props.filters.character ?? 'icha',
    skill: props.filters.skill ?? 'all',
    title: props.filters.title ?? 'XP Goals',
    icons: props.filters.icons ?? '',
    skillSearch: props.filters.skillSearch ?? '',
    skillKeys: parseSkillKeys(props.filters.skillKeys ?? ''),
    skillGoalLevels: parseGoalMap(props.filters.skillGoalLevels ?? ''),
    skillGoalXp: parseGoalMap(props.filters.skillGoalXp ?? ''),
    ...normalizeWidgetTheme(props.filters),
})

const trackerSkills = (nextTracker) => {
    if (!nextTracker) {
        return []
    }

    if (Array.isArray(nextTracker.skills) && nextTracker.skills.length) {
        return nextTracker.skills
    }

    return nextTracker.skill ? [{
        ...nextTracker.skill,
        xp: nextTracker.xp,
        level: nextTracker.level,
        nextLevel: nextTracker.nextLevel,
        xpRemaining: nextTracker.xpRemaining,
        progressPercent: nextTracker.progressPercent,
    }] : []
}

const addSample = (nextTracker, nextSampledAt) => {
    if (!nextTracker) {
        return
    }

    const sampledTime = Date.parse(nextSampledAt) || Date.now()
    const sample = {
        at: sampledTime,
        xpBySkill: Object.fromEntries(trackerSkills(nextTracker).map((skill) => [
            String(skill.id),
            Number(skill.xp ?? 0),
        ])),
    }

    samples.value = [...samples.value, sample]
        .filter((item) => sampledTime - item.at <= SAMPLE_WINDOW_MS)
        .filter((item, index, list) => index === 0 || item.at !== list[index - 1].at)
    saveSamples()
}

const refresh = async () => {
    try {
        const url = new URL(props.pollUrl, window.location.origin)
        url.searchParams.set('_', Date.now().toString())

        const response = await fetch(url.toString(), {
            cache: 'no-store',
            headers: {
                Accept: 'application/json',
                'Cache-Control': 'no-cache',
                Pragma: 'no-cache',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })

        if (!response.ok) {
            throw new Error(`Snapshot request failed with ${response.status}`)
        }

        const payload = await response.json()

        if (payload.tracker) {
            tracker.value = payload.tracker
            sampledAt.value = payload.sampledAt
            addSample(payload.tracker, payload.sampledAt)
        }

        error.value = payload.error
    } catch {
        error.value = 'Tracker refresh failed. Waiting for the next Bitjita check.'
    }
}

onMounted(() => {
    const savedSetup = loadSetup()
    const params = new URLSearchParams(window.location.search)

    restoredSetup = true

    if (!params.has('source') && !params.has('skillKeys') && savedSetup?.skillKeys?.length) {
        Object.assign(form, savedSetup)
        submitSetup(Boolean(props.filters.setup))

        return
    }

    saveSetup()
    samples.value = loadSamples()
    addSample(tracker.value, sampledAt.value)
    pollTimer = window.setInterval(refresh, POLL_INTERVAL_MS)
    clockTimer = window.setInterval(() => {
        now.value = new Date()
    }, 1000)
    document.addEventListener('pointerdown', closePickerOnOutsidePointer)
})

onBeforeUnmount(() => {
    window.clearInterval(pollTimer)
    window.clearInterval(clockTimer)
    document.removeEventListener('pointerdown', closePickerOnOutsidePointer)
})

const formatNumber = (value) => new Intl.NumberFormat().format(Math.max(0, Math.round(Number(value) || 0)))

const formatCompact = (value) => new Intl.NumberFormat(undefined, {
    notation: 'compact',
    maximumFractionDigits: Number(value) >= 1000000 ? 1 : 0,
}).format(Math.max(0, Number(value) || 0))

const formatDuration = (minutes) => {
    if (!Number.isFinite(minutes) || minutes <= 0) {
        return '--'
    }

    const roundedMinutes = Math.ceil(minutes)

    if (roundedMinutes < 60) {
        return `${roundedMinutes}m`
    }

    const hours = Math.floor(roundedMinutes / 60)
    const remainingMinutes = roundedMinutes % 60

    return remainingMinutes ? `${hours}h ${remainingMinutes}m` : `${hours}h`
}

const payload = (setup) => ({
    source: form.source,
    character: form.character,
    skill: form.skill,
    title: form.title,
    icons: form.icons,
    skillSearch: form.skillSearch,
    skillKeys: form.skillKeys.join(','),
    skillGoalLevels: formatGoalMap(form.skillGoalLevels),
    skillGoalXp: formatGoalMap(form.skillGoalXp),
    ...widgetThemePayload(form),
    setup: setup ? 1 : 0,
})

const browserStorage = () => {
    if (typeof window === 'undefined') {
        return null
    }

    return window.localStorage
}

const sampleStorageKey = () => {
    const url = new URL(props.pollUrl, window.location.origin)

    url.searchParams.delete('_')

    return `${SAMPLE_STORAGE_PREFIX}${url.pathname}?${url.searchParams.toString()}`
}

const normalizeSamples = (value) => {
    if (!Array.isArray(value)) {
        return []
    }

    const nowMs = Date.now()

    return value
        .map((sample) => ({
            at: Number(sample?.at),
            xpBySkill: sample?.xpBySkill && typeof sample.xpBySkill === 'object' ? sample.xpBySkill : null,
        }))
        .filter((sample) => Number.isFinite(sample.at) && sample.xpBySkill && nowMs - sample.at <= SAMPLE_WINDOW_MS)
        .sort((a, b) => a.at - b.at)
}

const loadSamples = () => {
    const storage = browserStorage()

    if (!storage) {
        return []
    }

    try {
        return normalizeSamples(JSON.parse(storage.getItem(sampleStorageKey()) ?? '[]'))
    } catch {
        return []
    }
}

const saveSamples = () => {
    const storage = browserStorage()

    if (!storage) {
        return
    }

    storage.setItem(sampleStorageKey(), JSON.stringify(normalizeSamples(samples.value)))
}

const normalizeSetup = (setup) => ({
    source: typeof setup.source === 'string' && setup.source.trim() ? setup.source.trim() : 'default',
    character: typeof setup.character === 'string' && setup.character.trim() ? setup.character.trim() : 'icha',
    skill: typeof setup.skill === 'string' && setup.skill.trim() ? setup.skill.trim() : 'all',
    title: typeof setup.title === 'string' && setup.title.trim() ? setup.title.trim() : 'XP Goals',
    icons: typeof setup.icons === 'string' ? setup.icons.trim() : '',
    skillSearch: typeof setup.skillSearch === 'string' ? setup.skillSearch.trim() : '',
    skillKeys: parseSkillKeys(setup.skillKeys),
    skillGoalLevels: parseGoalMap(setup.skillGoalLevels),
    skillGoalXp: parseGoalMap(setup.skillGoalXp),
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

const updateTheme = (updates) => {
    Object.assign(form, updates)
    saveSetup()
}

const submitSetup = (setup) => {
    saveSetup()

    router.get(route('bitcraft.activity'), payload(setup), {
        preserveScroll: true,
        preserveState: false,
        replace: true,
    })
}

const skills = computed(() => trackerSkills(tracker.value))
const levels = computed(() => tracker.value?.levels ?? [])
const setupPageVisible = computed(() => Boolean(props.filters.setup))
const setupVisible = computed(() => setupPageVisible.value)
const titleLabel = computed(() => form.title || 'Live Activity')
const iconsLabel = computed(() => form.icons || '')
const widgetThemeStyle = computed(() => resolveWidgetThemeStyle(form))
const skillOptions = computed(() => skills.value
    .slice()
    .sort((a, b) => String(a.name).localeCompare(String(b.name))))
const filteredSkillOptions = computed(() => {
    const search = form.skillSearch.toLowerCase()

    return skillOptions.value
        .filter((skill) => {
            if (!search) {
                return true
            }

            return [skill.name, skill.title, skill.level]
                .filter(Boolean)
                .join(' ')
                .toLowerCase()
                .includes(search)
        })
        .slice(0, 40)
})
const selectedSkillOptions = computed(() => form.skillKeys
    .map((key) => skillOptions.value.find((skill) => String(skill.id) === key) ?? { id: key, name: `Skill ${key}` })
    .filter(Boolean))

const selectSkill = (skill) => {
    const skillId = String(skill.id)

    if (!form.skillKeys.includes(skillId)) {
        form.skillKeys.push(skillId)
    }

    if (!form.skillGoalLevels[skillId] && skill.nextLevel) {
        form.skillGoalLevels[skillId] = skill.nextLevel
    }

    form.skillSearch = skill.name
    pickerOpen.value = false
    saveSetup()
}

const removeSkill = (skillId) => {
    form.skillKeys = form.skillKeys.filter((key) => key !== String(skillId))
    delete form.skillGoalLevels[String(skillId)]
    delete form.skillGoalXp[String(skillId)]
    saveSetup()
}

const closePickerOnOutsidePointer = (event) => {
    if (!pickerElement.value || pickerElement.value.contains(event.target)) {
        return
    }

    pickerOpen.value = false
}

watch(() => props.snapshot, (snapshot) => {
    tracker.value = snapshot.tracker
    error.value = snapshot.error
    sampledAt.value = snapshot.sampledAt
    addSample(snapshot.tracker, snapshot.sampledAt)
})

watch(form, saveSetup, { deep: true })

const skillStats = computed(() => {
    const usableSamples = samples.value
        .filter((sample) => Number.isFinite(sample.at) && sample.xpBySkill)
        .sort((a, b) => a.at - b.at)

    if (usableSamples.length < 2) {
        return skills.value.map((skill) => ({
            skill,
            xpDelta: 0,
            hourRate: 0,
            minutesSampled: 0,
            progressPercent: Math.max(0, Math.min(100, Number(skill.progressPercent ?? 0))),
        }))
    }

    const last = usableSamples[usableSamples.length - 1]
    const baselineSamples = usableSamples.filter((sample) => {
        const elapsed = last.at - sample.at

        return elapsed >= MIN_RATE_SAMPLE_MS && elapsed <= RATE_SAMPLE_MS
    })
    const first = baselineSamples[0]

    if (!first) {
        return skills.value.map((skill) => ({
            skill,
            xpDelta: 0,
            hourRate: 0,
            minutesSampled: 0,
            progressPercent: Math.max(0, Math.min(100, Number(skill.progressPercent ?? 0))),
        }))
    }

    const elapsedHours = (last.at - first.at) / 3600000

    return skills.value.map((skill) => {
        const skillId = String(skill.id)
        const xpDelta = Math.max(0, Number(last.xpBySkill[skillId] ?? 0) - Number(first.xpBySkill[skillId] ?? 0))

        return {
            skill,
            xpDelta,
            hourRate: elapsedHours > 0 ? xpDelta / elapsedHours : 0,
            minutesSampled: elapsedHours * 60,
            progressPercent: Math.max(0, Math.min(100, Number(skill.progressPercent ?? 0))),
        }
    })
})

const levelXp = (level) => {
    const levelRecord = levels.value.find((entry) => Number(entry.level) === Number(level))

    return levelRecord ? Number(levelRecord.xp) : null
}

const goalForSkill = (skill) => {
    const skillId = String(skill.id)
    const goalLevel = Number(form.skillGoalLevels[skillId] ?? 0)
    const goalXp = Number(form.skillGoalXp[skillId] ?? 0)
    const goalLevelXp = goalLevel > 0 ? levelXp(goalLevel) : null
    const fallbackGoalXp = Number(skill.nextLevelXp ?? 0)
    const targetXp = Math.max(
        goalXp > 0 ? goalXp : 0,
        goalLevelXp ?? 0,
        fallbackGoalXp,
    )

    return {
        level: goalLevel > 0 ? goalLevel : null,
        xp: targetXp > 0 ? targetXp : null,
    }
}

const goalSkillStats = computed(() => form.skillKeys
    .map((skillId) => {
        const stat = skillStats.value.find((item) => String(item.skill.id) === String(skillId))

        if (!stat) {
            return null
        }

        const goal = goalForSkill(stat.skill)
        const targetXp = goal.xp
        const currentXp = Number(stat.skill.xp ?? 0)
        const xpRemaining = targetXp === null ? null : Math.max(0, targetXp - currentXp)

        return {
            ...stat,
            goal,
            targetXp,
            xpRemaining,
            goalProgressPercent: targetXp === null ? stat.progressPercent : Math.max(0, Math.min(100, (currentXp / Math.max(1, targetXp)) * 100)),
        }
    })
    .filter(Boolean))

const activeSkillStats = computed(() => skillStats.value
    .filter((stat) => stat.xpDelta > 0 && stat.hourRate > 0)
    .sort((a, b) => b.hourRate - a.hourRate))
watch(activeSkillStats, (stats) => {
    if (!stats.length) {
        return
    }

    lastActiveSkillStats.value = stats
    lastActiveSkillStatsAt.value = Date.now()
})
const goalMode = computed(() => goalSkillStats.value.length > 0)
const fallbackActiveSkillStats = computed(() => {
    if (!lastActiveSkillStats.value.length || now.value.getTime() - lastActiveSkillStatsAt.value > ACTIVE_STAT_GRACE_MS) {
        return []
    }

    return lastActiveSkillStats.value
})
const displaySkillStats = computed(() => goalMode.value ? goalSkillStats.value : (activeSkillStats.value.length ? activeSkillStats.value : fallbackActiveSkillStats.value))

const totalStats = computed(() => activeSkillStats.value.reduce((total, stat) => ({
    xpDelta: total.xpDelta + stat.xpDelta,
    hourRate: total.hourRate + stat.hourRate,
}), { xpDelta: 0, hourRate: 0 }))

const skillLevelLabel = (skill) => `${skill.name} Lv ${formatNumber(skill.level ?? 0)}`
const displayProgressPercent = (stat) => Number(stat.goalProgressPercent ?? stat.progressPercent ?? 0)
const summaryProgressPercent = computed(() => displaySkillStats.value[0] ? displayProgressPercent(displaySkillStats.value[0]) : 0)
const activitySummaryLabel = computed(() => {
    if (!tracker.value) {
        return 'All XP'
    }

    const topGoalSkill = goalSkillStats.value[0]?.skill

    if (topGoalSkill) {
        return skillLevelLabel(topGoalSkill)
    }

    const topActiveSkill = activeSkillStats.value[0]?.skill

    if (topActiveSkill) {
        return skillLevelLabel(topActiveSkill)
    }

    if (skills.value.length === 1) {
        return skillLevelLabel(skills.value[0])
    }

    return 'Sampling'
})
const clockLabel = computed(() => new Intl.DateTimeFormat(undefined, {
    hour: 'numeric',
    minute: '2-digit',
}).format(now.value))

const totalXpRateLabel = computed(() => `${formatCompact(totalStats.value.hourRate)} XP/hr`)
const totalRecentXpLabel = computed(() => `${formatNumber(totalStats.value.xpDelta)} recent XP across ${activeSkillStats.value.length} skill${activeSkillStats.value.length === 1 ? '' : 's'}`)
const blockingError = computed(() => Boolean(error.value && !tracker.value))
const waitingLabel = computed(() => tracker.value
    ? 'Sampling at least a minute before calculating XP/hr.'
    : 'Waiting for Bitjita data.')

const skillRateLabel = (stat) => `${formatCompact(stat.hourRate)} XP/hr`
const skillRemainingXp = (stat) => stat.targetXp
    ? Number(stat.xpRemaining ?? 0)
    : Number(stat.skill.xpRemaining ?? 0)
const skillTimeRemainingLabel = (stat) => {
    if (!stat.targetXp && (!stat.skill.nextLevel || stat.skill.xpRemaining === null)) {
        return 'Done'
    }

    const xpRemaining = skillRemainingXp(stat)

    if (xpRemaining <= 0) {
        return 'Done'
    }

    if (stat.hourRate <= 0) {
        return '--'
    }

    const minutes = (xpRemaining / stat.hourRate) * 60

    return formatDuration(minutes)
}
const skillPrimaryLabel = (stat) => skillTimeRemainingLabel(stat) ?? skillRateLabel(stat)
const skillCountLabel = (stat) => stat.targetXp || stat.skill.nextLevel ? 'Time Left' : 'Current Rate'
const skillGoalLabel = (stat) => {
    if (stat.goal?.level) {
        return `${stat.skill.name} ${stat.goal.level}`
    }

    if (stat.targetXp) {
        return `${formatNumber(stat.targetXp)} XP`
    }

    return stat.skill.nextLevel ? `${stat.skill.name} ${stat.skill.nextLevel}` : 'goal'
}

const skillDetailLabel = (stat) => {
    const xpRemaining = skillRemainingXp(stat)

    if (stat.targetXp && xpRemaining <= 0) {
        return `Goal reached - ${formatNumber(stat.targetXp)} XP target`
    }

    if (!stat.targetXp && (!stat.skill.nextLevel || stat.skill.xpRemaining === null)) {
        return 'Max level reached'
    }

    if (stat.hourRate <= 0) {
        return `${formatNumber(xpRemaining)} XP remaining to ${skillGoalLabel(stat)}`
    }

    return `${formatNumber(xpRemaining)} XP to ${skillGoalLabel(stat)} - ${skillRateLabel(stat)}`
}
</script>

<style scoped>
.activity-source {
    min-height: 100vh;
    display: grid;
    align-content: start;
    place-items: start;
    gap: 12px;
    background: transparent;
    color: var(--text-primary);
    font-family: var(--font-ui);
}

.activity-source--setup {
    padding: 14px;
    background: var(--bg-canvas);
}

.activity-source--in-app {
    min-height: auto;
    padding: 0;
    background: transparent;
}

.activity-setup {
    width: min(720px, 100%);
    border: 1px solid rgb(var(--border-color-2-rgb) / 0.36);
    border-radius: 8px;
    padding: 12px;
    background:
        linear-gradient(180deg, rgb(var(--bg-surface-3-rgb) / 0.26), rgb(var(--bg-surface-rgb) / 0.94)),
        var(--bg-surface);
}

.activity-setup__grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
}

.activity-setup label,
.activity-picker label {
    display: grid;
    gap: 6px;
}

.activity-setup span,
.activity-picker span {
    color: var(--text-muted-3);
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
}

.activity-setup input,
.activity-setup select,
.activity-picker input {
    min-height: 36px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    background: var(--bg-canvas);
    color: var(--text-primary);
    font-size: 13px;
}

.activity-picker {
    position: relative;
    margin-top: 10px;
}

.activity-picker__menu {
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

.activity-picker__option {
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

.activity-picker__option:hover,
.activity-picker__option.selected {
    background: rgb(var(--accent-cyan-rgb) / 0.1);
}

.activity-picker__option small,
.activity-picker__menu p {
    color: var(--text-muted-3);
    font-size: 12px;
}

.activity-picker__menu p {
    padding: 12px;
}

.activity-selected {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 10px;
}

.activity-selected span {
    display: grid;
    grid-template-columns: minmax(120px, 1fr) 86px 110px auto;
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

.activity-selected label {
    display: grid;
    gap: 2px;
}

.activity-selected small {
    color: var(--text-muted-3);
    font-size: 9px;
    font-weight: 900;
    text-transform: uppercase;
}

.activity-selected input {
    min-height: 28px;
    width: 100%;
    border: 1px solid var(--border-color);
    border-radius: 5px;
    background: var(--bg-canvas);
    color: var(--text-primary);
    font-size: 12px;
}

.activity-selected button {
    color: var(--accent-pink);
    font-size: 11px;
    font-weight: 900;
}

.activity-setup__actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 12px;
}

.activity-setup__actions button {
    min-height: 34px;
    padding: 0 12px;
    border: 1px solid rgb(var(--accent-cyan-rgb) / 0.34);
    border-radius: 6px;
    background: rgb(var(--accent-cyan-rgb) / 0.1);
    color: var(--accent-cyan);
    font-size: 12px;
    font-weight: 800;
}

.activity-widget {
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

.activity-widget__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 18px 22px 14px;
    border-bottom: 1px solid color-mix(in srgb, var(--tracker-border) 24%, transparent);
}

.activity-widget__identity,
.activity-widget__level {
    min-width: 0;
}

.activity-widget__eyebrow,
.activity-widget__metric-count small {
    color: var(--tracker-accent);
    font-size: calc(11px * var(--tracker-font-scale));
    font-weight: 800;
    line-height: 1.1;
    text-transform: uppercase;
}

.activity-widget h1 {
    margin-top: 4px;
    color: var(--tracker-text);
    font-size: calc(24px * var(--tracker-font-scale));
    font-weight: 900;
    line-height: 1.1;
}

.activity-widget__level {
    text-align: right;
}

.activity-widget__level p {
    color: var(--tracker-accent);
    font-size: calc(18px * var(--tracker-font-scale));
    font-weight: 900;
    line-height: 1.1;
}

.activity-widget__level time {
    display: block;
    margin-top: 4px;
    color: color-mix(in srgb, var(--tracker-accent) 74%, var(--tracker-text));
    font-size: calc(12px * var(--tracker-font-scale));
    font-weight: 800;
}

.activity-widget__progress {
    display: none;
}

.activity-widget__progress span {
    display: block;
    height: 100%;
    background: linear-gradient(90deg, var(--tracker-highlight), var(--tracker-accent));
    transition: width 320ms ease;
}

.activity-widget__metrics {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 0;
    padding: 0;
}

.activity-widget__metric {
    min-height: 0;
    padding: 0 0 18px;
    border: 0;
    border-radius: 0;
    background: transparent;
}

.activity-widget__metric + .activity-widget__metric {
    border-top: 1px solid color-mix(in srgb, var(--tracker-border) 24%, transparent);
}

.activity-widget__metric-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 20px;
    padding: 22px 24px 6px;
}

.activity-widget__metric-item p {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--tracker-text);
    font-size: calc(18px * var(--tracker-font-scale));
    font-weight: 900;
    line-height: 1.25;
}

.activity-widget__metric-item small,
.activity-widget__metric-count small {
    display: block;
    margin-top: 5px;
    color: var(--tracker-muted);
    font-size: calc(11px * var(--tracker-font-scale));
    font-weight: 800;
    line-height: 1.35;
}

.activity-widget__metric-count {
    text-align: right;
}

.activity-widget__metric-count small {
    color: var(--tracker-highlight);
}

.activity-widget__metric-count strong {
    display: block;
    margin-top: 3px;
    color: var(--tracker-text);
    font-size: calc(19px * var(--tracker-font-scale));
    font-weight: 900;
    line-height: 1;
}

.activity-widget__mini-progress {
    height: 6px;
    margin: 8px 24px 0;
    overflow: hidden;
    border-radius: 999px;
    background: color-mix(in srgb, var(--tracker-panel) 68%, black);
}

.activity-widget__mini-progress span {
    display: block;
    height: 100%;
    margin: 0;
    border-radius: inherit;
    background: linear-gradient(90deg, var(--tracker-highlight), var(--tracker-accent));
    transition: width 320ms ease;
}

.activity-widget__error {
    margin: 16px;
    padding: 14px;
    border: 1px solid rgb(var(--accent-pink-rgb) / 0.42);
    border-radius: 8px;
    background: rgb(var(--accent-pink-rgb) / 0.1);
    color: var(--accent-pink);
    font-size: 13px;
    font-weight: 800;
}

.activity-widget__warning {
    margin: 12px 16px 0;
    padding: 10px 12px;
    border: 1px solid rgb(var(--accent-pink-rgb) / 0.28);
    border-radius: 8px;
    background: rgb(var(--accent-pink-rgb) / 0.08);
    color: color-mix(in srgb, var(--text-primary-2) 72%, var(--accent-pink));
    font-size: 12px;
    font-weight: 800;
}

@media (max-width: 520px) {
    .activity-setup__grid,
    .activity-selected span {
        grid-template-columns: minmax(0, 1fr);
    }

    .activity-widget__metrics {
        grid-template-columns: minmax(0, 1fr);
    }

    .activity-widget__metric-row {
        grid-template-columns: minmax(0, 1fr);
    }

    .activity-widget__metric-count {
        text-align: left;
    }

    .activity-widget h1 {
        font-size: 26px;
    }
}
</style>
