<template>
    <main class="task-tracker-source" :class="{ 'task-tracker-source--setup': setupVisible }">
        <form v-if="setupVisible" class="task-tracker-setup" @submit.prevent="submitSetup(true)">
            <div class="task-tracker-setup__grid">
                <label>
                    <span>Title</span>
                    <input v-model.trim="form.title" type="text" maxlength="80" />
                </label>

                <div class="task-tracker-emoji-picker">
                    <span>Emojis</span>
                    <div class="task-tracker-emoji-picker__controls">
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
                    <div v-if="selectedEmojiList.length" class="task-tracker-emoji-picker__selected">
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
            </div>

            <div class="task-tracker-entry">
                <label>
                    <span>Task</span>
                    <input
                        v-model.trim="form.taskText"
                        type="text"
                        maxlength="160"
                        placeholder="Write a task"
                        @keydown.enter.prevent="addTask"
                    />
                </label>
                <button type="button" @click="addTask">Add</button>
            </div>

            <div v-if="form.tasks.length" class="task-tracker-selected">
                <span v-for="task in form.tasks" :key="task.id">
                    <button
                        type="button"
                        class="task-tracker-selected__check"
                        :class="{ 'done': task.done }"
                        :aria-pressed="task.done ? 'true' : 'false'"
                        @click="toggleTask(task.id)"
                    >
                        <span>{{ task.done ? '✓' : '' }}</span>
                    </button>
                    <input v-model.trim="task.text" type="text" maxlength="160" />
                    <button type="button" @click="removeTask(task.id)">Remove</button>
                </span>
            </div>

            <div class="task-tracker-setup__actions">
                <button type="submit">Search / Update</button>
                <button type="button" @click="submitSetup(false)">Widget Mode</button>
            </div>
        </form>

        <section class="task-tracker-widget" aria-label="Bitcraft task tracker">
            <header class="task-tracker-widget__header">
                <div>
                    <h1>{{ titleLabel }}</h1>
                    <p>{{ summaryLabel }}</p>
                </div>
                <strong v-if="iconsLabel">{{ iconsLabel }}</strong>
            </header>

            <div class="task-tracker-widget__bar" aria-hidden="true">
                <span :style="{ width: `${completionPercent}%` }" />
            </div>

            <div v-if="!visibleTasks.length" class="task-tracker-widget__empty">
                Add a task
            </div>

            <template v-else>
                <article
                    v-for="task in visibleTasks"
                    :key="task.id"
                    class="task-tracker-widget__task"
                    :class="{ 'done': task.done }"
                >
                    <button
                        type="button"
                        class="task-tracker-widget__check"
                        :aria-pressed="task.done ? 'true' : 'false'"
                        @click="toggleTask(task.id, !setupVisible)"
                    >
                        <span>{{ task.done ? '✓' : '' }}</span>
                    </button>

                    <div class="task-tracker-widget__copy">
                        <p>{{ task.text }}</p>
                        <small>{{ task.done ? 'Done' : 'To do' }}</small>
                    </div>
                </article>
            </template>
        </section>
    </main>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
    filters: { type: Object, default: () => ({}) },
})

const STORAGE_KEY = 'bitcraft.taskTracker.lastSetup'
const emojiChoice = ref('')
let restoredSetup = false
const emojiOptions = [
    { value: '✅', label: '✅ Done' },
    { value: '✨', label: '✨ Sparkle' },
    { value: '⭐', label: '⭐ Star' },
    { value: '🏆', label: '🏆 Goal' },
    { value: '🔥', label: '🔥 Fire' },
    { value: '💎', label: '💎 Rare' },
    { value: '📦', label: '📦 Cargo' },
    { value: '🧺', label: '🧺 Basket' },
    { value: '🎣', label: '🎣 Fishing' },
    { value: '⛵', label: '⛵ Sailing' },
    { value: '⛏️', label: '⛏️ Mining' },
    { value: '🌾', label: '🌾 Farming' },
    { value: '🍄', label: '🍄 Mushroom' },
    { value: '🛠️', label: '🛠️ Tools' },
    { value: '🗺️', label: '🗺️ Explore' },
    { value: '💚', label: '💚 Green' },
    { value: '🩵', label: '🩵 Cyan' },
]

const newTaskId = () => `task-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`

const parseTasks = (value) => {
    const source = typeof value === 'string' ? JSON.parse(value || '[]') : value

    if (!Array.isArray(source)) {
        return []
    }

    return source
        .map((task, index) => ({
            id: /^[A-Za-z0-9_-]{1,80}$/.test(String(task?.id ?? '')) ? String(task.id) : `task-${index}`,
            text: String(task?.text ?? '').trim().slice(0, 160),
            done: Boolean(task?.done),
        }))
        .filter((task) => task.text)
        .slice(0, 20)
}

const safeParseTasks = (value) => {
    try {
        return parseTasks(value)
    } catch {
        return []
    }
}

const form = reactive({
    source: props.filters.source ?? 'default',
    title: props.filters.title ?? 'Stream Tasks',
    icons: props.filters.icons ?? '',
    taskText: props.filters.taskText ?? '',
    tasks: safeParseTasks(props.filters.tasks ?? []),
})

const setupVisible = computed(() => Boolean(props.filters.setup) || !form.tasks.length)
const titleLabel = computed(() => form.title || 'Task Tracker')
const iconsLabel = computed(() => form.icons || '')
const selectedEmojiList = computed(() => form.icons.split(/\s+/).filter(Boolean))
const visibleTasks = computed(() => form.tasks.filter((task) => task.text))
const completedCount = computed(() => visibleTasks.value.filter((task) => task.done).length)
const remainingCount = computed(() => Math.max(0, visibleTasks.value.length - completedCount.value))
const completionPercent = computed(() => {
    if (!visibleTasks.value.length) {
        return 0
    }

    return Math.round((completedCount.value / visibleTasks.value.length) * 100)
})
const summaryLabel = computed(() => {
    if (!visibleTasks.value.length) {
        return '0 tasks'
    }

    if (remainingCount.value === 0) {
        return `${completedCount.value} / ${visibleTasks.value.length} done`
    }

    return `${remainingCount.value} remaining`
})

const normalizeSetup = (setup) => ({
    source: typeof setup.source === 'string' && setup.source.trim() ? setup.source.trim() : 'default',
    title: typeof setup.title === 'string' && setup.title.trim() ? setup.title.trim() : 'Stream Tasks',
    icons: typeof setup.icons === 'string' ? setup.icons.trim() : '',
    taskText: typeof setup.taskText === 'string' ? setup.taskText.trim() : '',
    tasks: safeParseTasks(setup.tasks ?? []),
})

const browserStorage = () => {
    if (typeof window === 'undefined') {
        return null
    }

    return window.localStorage
}

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

const addTask = () => {
    const text = form.taskText.trim()

    if (!text) {
        return
    }

    form.tasks.push({
        id: newTaskId(),
        text,
        done: false,
    })
    form.taskText = ''
    saveSetup()
}

const toggleTask = (id, persist = false) => {
    const task = form.tasks.find((item) => item.id === id)

    if (!task) {
        return
    }

    task.done = !task.done
    saveSetup()

    if (persist) {
        submitSetup(false)
    }
}

const removeTask = (id) => {
    form.tasks = form.tasks.filter((task) => task.id !== id)
    saveSetup()
}

const payload = (setup) => ({
    source: form.source,
    title: form.title,
    icons: form.icons,
    taskText: form.taskText,
    tasks: JSON.stringify(visibleTasks.value),
    setup: setup ? 1 : 0,
})

const submitSetup = (setup) => {
    saveSetup()

    router.get(route('bitcraft.task-tracker'), payload(setup), {
        preserveScroll: true,
        preserveState: false,
        replace: true,
    })
}

watch(() => props.filters, (filters) => {
    form.source = filters.source ?? 'default'
    form.title = filters.title ?? 'Stream Tasks'
    form.icons = filters.icons ?? ''
    form.taskText = filters.taskText ?? ''
    form.tasks = safeParseTasks(filters.tasks ?? [])
})

watch(form, saveSetup, { deep: true })

onMounted(() => {
    const savedSetup = loadSetup()
    const params = new URLSearchParams(window.location.search)

    restoredSetup = true

    if (!params.has('source') && !params.has('tasks') && savedSetup?.tasks?.length) {
        Object.assign(form, savedSetup)
        submitSetup(Boolean(props.filters.setup))

        return
    }

    saveSetup()
})
</script>

<style scoped>
.task-tracker-source {
    min-height: 100vh;
    display: grid;
    align-content: start;
    gap: 12px;
    background: transparent;
    color: var(--text-primary);
    font-family: var(--font-ui);
}

.task-tracker-source--setup {
    padding: 14px;
    background: var(--bg-canvas);
}

.task-tracker-setup {
    width: min(720px, 100%);
    border: 1px solid rgb(var(--border-color-2-rgb) / 0.36);
    border-radius: 8px;
    padding: 12px;
    background:
        linear-gradient(180deg, rgb(var(--bg-surface-3-rgb) / 0.26), rgb(var(--bg-surface-rgb) / 0.94)),
        var(--bg-surface);
}

.task-tracker-setup__grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
}

.task-tracker-setup label,
.task-tracker-emoji-picker,
.task-tracker-entry label {
    display: grid;
    gap: 6px;
}

.task-tracker-setup label > span,
.task-tracker-emoji-picker > span,
.task-tracker-entry label > span {
    color: var(--text-muted-3);
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
}

.task-tracker-setup input,
.task-tracker-emoji-picker select,
.task-tracker-entry input {
    min-height: 36px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    background: var(--bg-canvas);
    color: var(--text-primary);
    font-size: 13px;
}

.task-tracker-emoji-picker__controls {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 6px;
}

.task-tracker-emoji-picker__controls button,
.task-tracker-emoji-picker__selected button,
.task-tracker-entry button {
    min-height: 32px;
    border: 1px solid rgb(var(--accent-cyan-rgb) / 0.28);
    border-radius: 6px;
    background: rgb(var(--accent-cyan-rgb) / 0.08);
    color: var(--text-primary-2);
    font-size: 12px;
    font-weight: 900;
}

.task-tracker-emoji-picker__controls button {
    padding: 0 10px;
    color: var(--accent-pink);
}

.task-tracker-emoji-picker__selected {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
}

.task-tracker-emoji-picker__selected button {
    width: 32px;
    padding: 0;
    font-size: 16px;
    line-height: 1;
}

.task-tracker-entry {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: end;
    gap: 8px;
    margin-top: 10px;
}

.task-tracker-entry button {
    min-height: 36px;
    padding: 0 14px;
    color: var(--accent-cyan);
}

.task-tracker-selected {
    display: grid;
    gap: 6px;
    margin-top: 10px;
}

.task-tracker-selected span {
    display: grid;
    grid-template-columns: 32px minmax(0, 1fr) auto;
    align-items: center;
    gap: 6px;
    border: 1px solid rgb(var(--accent-cyan-rgb) / 0.28);
    border-radius: 8px;
    padding: 6px 8px;
    background: rgb(var(--accent-cyan-rgb) / 0.08);
}

.task-tracker-selected__check {
    width: 28px;
    height: 28px;
    display: grid;
    place-items: center;
    border: 1px solid rgb(var(--accent-cyan-rgb) / 0.38);
    border-radius: 6px;
    background: rgb(var(--bg-canvas-rgb) / 0.5);
    color: var(--success);
    font-size: 16px;
    font-weight: 900;
}

.task-tracker-selected__check.done {
    background: rgb(var(--success-rgb) / 0.16);
}

.task-tracker-selected button:last-child {
    color: var(--accent-pink);
    font-size: 11px;
    font-weight: 900;
}

.task-tracker-setup__actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 12px;
}

.task-tracker-setup__actions button {
    min-height: 34px;
    padding: 0 12px;
    border: 1px solid rgb(var(--accent-cyan-rgb) / 0.34);
    border-radius: 6px;
    background: rgb(var(--accent-cyan-rgb) / 0.1);
    color: var(--accent-cyan);
    font-size: 12px;
    font-weight: 800;
}

.task-tracker-widget {
    width: min(450px, 100vw);
    overflow: hidden;
    border: 1px solid rgb(var(--border-color-2-rgb) / 0.46);
    border-radius: 18px;
    background:
        linear-gradient(180deg, rgb(var(--bg-surface-3-rgb) / 0.68), rgb(var(--bg-surface-rgb) / 0.98)),
        var(--bg-surface);
    box-shadow: inset 0 1px 0 rgb(var(--text-primary-rgb) / 0.04);
}

.task-tracker-widget__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 18px 22px 14px;
    border-bottom: 1px solid rgb(var(--border-color-2-rgb) / 0.22);
}

.task-tracker-widget__header h1 {
    min-width: 0;
    color: var(--text-primary-2);
    font-size: 24px;
    font-weight: 900;
    line-height: 1.1;
}

.task-tracker-widget__header p {
    margin-top: 5px;
    color: var(--text-muted-2);
    font-size: 12px;
    font-weight: 800;
}

.task-tracker-widget__header strong {
    color: var(--accent-cyan-2);
    font-size: 20px;
    line-height: 1;
    white-space: nowrap;
}

.task-tracker-widget__bar {
    height: 6px;
    margin: 12px 22px 0;
    overflow: hidden;
    border-radius: 999px;
    background: rgb(var(--bg-canvas-rgb) / 0.58);
}

.task-tracker-widget__bar span {
    display: block;
    height: 100%;
    border-radius: inherit;
    background: linear-gradient(90deg, var(--success), var(--accent-cyan));
    transition: width 320ms ease;
}

.task-tracker-widget__task {
    display: grid;
    grid-template-columns: 32px minmax(0, 1fr);
    align-items: center;
    gap: 12px;
    padding: 16px 22px;
}

.task-tracker-widget__task + .task-tracker-widget__task {
    border-top: 1px solid rgb(var(--border-color-2-rgb) / 0.22);
}

.task-tracker-widget__check {
    width: 30px;
    height: 30px;
    display: grid;
    place-items: center;
    border: 1px solid rgb(var(--accent-cyan-rgb) / 0.38);
    border-radius: 7px;
    background: rgb(var(--bg-canvas-rgb) / 0.5);
    color: var(--success);
    font-size: 17px;
    font-weight: 900;
}

.task-tracker-widget__task.done .task-tracker-widget__check {
    background: rgb(var(--success-rgb) / 0.16);
}

.task-tracker-widget__copy p {
    color: var(--text-primary-2);
    font-size: 17px;
    font-weight: 900;
    line-height: 1.25;
    overflow-wrap: anywhere;
}

.task-tracker-widget__task.done .task-tracker-widget__copy p {
    color: var(--text-muted-2);
    text-decoration: line-through;
}

.task-tracker-widget__copy small {
    display: block;
    margin-top: 4px;
    color: var(--text-muted-2);
    font-size: 11px;
    font-weight: 800;
}

.task-tracker-widget__empty {
    margin: 16px;
    padding: 14px;
    border: 1px dashed rgb(var(--border-color-2-rgb) / 0.42);
    border-radius: 8px;
    color: var(--text-muted-2);
    font-size: 13px;
    font-weight: 800;
}

@media (max-width: 520px) {
    .task-tracker-setup__grid,
    .task-tracker-entry {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>
