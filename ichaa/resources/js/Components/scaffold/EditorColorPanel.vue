<template>
    <Teleport to="body">
        <div class="editor-color-popup" @mousedown.self="emit('close')">
            <button
                type="button"
                class="editor-color-popup__backdrop"
                aria-label="Close color selector"
                @click="emit('close')"
            />

            <div class="editor-color-popup__frame">
                <div class="editor-color-panel editor-color-panel--popup" @mousedown.stop>
                    <div class="editor-color-panel__header">
                        <div class="editor-color-panel__swatches">
                            <button
                                v-for="swatch in headerSwatches"
                                :key="swatch.label"
                                type="button"
                                class="editor-color-panel__swatch editor-color-panel__swatch--header"
                                :style="{ backgroundColor: swatch.value }"
                                @mousedown.prevent
                                @click="applyColor(swatch.value)"
                            />
                        </div>

                        <label class="editor-color-panel__hex">
                            <span class="sr-only">{{ modeLabel }} color</span>
                            <input
                                v-model="draftHex"
                                type="text"
                                maxlength="7"
                                class="input editor-color-panel__hex-input"
                                @blur="commitHexInput"
                                @keydown.enter.prevent="commitHexInput"
                            >
                        </label>

                        <div class="editor-color-panel__title">
                            <span>{{ modeLabel }} Color</span>
                            <small>{{ normalizedColor }}</small>
                        </div>

                        <button type="button" class="editor-tool editor-tool--ghost" @mousedown.prevent @click="emit('close')">Close</button>
                        <button type="button" class="editor-tool editor-tool--accent" @mousedown.prevent @click="done">Done</button>
                    </div>

                    <div class="editor-color-panel__picker">
                        <div
                            ref="colorSurface"
                            class="editor-color-panel__surface"
                            :style="{ backgroundColor: `hsl(${hsv.h} 100% 50%)` }"
                            @mousedown.prevent="startSurfaceDrag"
                        >
                            <div class="editor-color-panel__surface-white" />
                            <div class="editor-color-panel__surface-black" />
                            <span
                                class="editor-color-panel__surface-handle"
                                :style="{
                                    left: `${hsv.s}%`,
                                    top: `${100 - hsv.v}%`,
                                }"
                            />
                        </div>

                        <div
                            ref="hueTrack"
                            class="editor-color-panel__hue-track"
                            @mousedown.prevent="startHueDrag"
                        >
                            <span
                                class="editor-color-panel__hue-handle"
                                :style="{ left: `${(hsv.h / 360) * 100}%` }"
                            />
                        </div>
                    </div>

                    <div class="editor-color-panel__section">
                        <div class="editor-color-panel__section-header">
                            <span>Preset Colors</span>
                        </div>

                        <div class="editor-color-panel__grid">
                            <button
                                v-for="color in presets"
                                :key="color"
                                type="button"
                                class="editor-color-panel__swatch"
                                :class="{ 'is-active': normalizedColor === color }"
                                :style="{ backgroundColor: color }"
                                @mousedown.prevent
                                @click="applyColor(color)"
                            />
                        </div>
                    </div>

                    <div class="editor-color-panel__section">
                        <div class="editor-color-panel__section-header">
                            <span>Advanced</span>
                            <div class="editor-color-panel__actions">
                                <button type="button" class="editor-color-panel__link" @mousedown.prevent @click="showAdvanced = !showAdvanced">
                                    {{ showAdvanced ? 'Hide' : 'Show' }}
                                </button>
                            </div>
                        </div>

                        <div v-if="showAdvanced" class="editor-color-panel__advanced">
                            <div class="editor-color-panel__section-header">
                                <span>Saved Colors</span>
                                <div class="editor-color-panel__actions">
                                    <button type="button" class="editor-color-panel__link" @mousedown.prevent @click="clearActiveSlot">Clear</button>
                                    <button type="button" class="editor-color-panel__link" @mousedown.prevent @click="saveToActiveSlot">Save</button>
                                </div>
                            </div>

                            <div class="editor-color-panel__saved-grid">
                                <button
                                    v-for="(slot, index) in savedColors"
                                    :key="`saved-${index}`"
                                    type="button"
                                    class="editor-color-panel__saved-slot"
                                    :class="{ 'is-selected': index === activeSlotIndex }"
                                    @mousedown.prevent
                                    @click="selectSavedSlot(index)"
                                >
                                    <span
                                        v-if="slot"
                                        class="editor-color-panel__saved-fill"
                                        :style="{ backgroundColor: slot }"
                                    />
                                </button>
                            </div>

                            <p class="editor-color-panel__note">
                                {{ savedSlotNote }}
                            </p>

                            <div class="editor-color-panel__metrics">
                                <label class="editor-color-panel__metric">
                                    <span>Hue</span>
                                    <input v-model="hsl.h" type="number" min="0" max="360" class="input" @change="applyHsl">
                                </label>
                                <label class="editor-color-panel__metric">
                                    <span>Sat</span>
                                    <input v-model="hsl.s" type="number" min="0" max="255" class="input" @change="applyHsl">
                                </label>
                                <label class="editor-color-panel__metric">
                                    <span>Lum</span>
                                    <input v-model="hsl.l" type="number" min="0" max="255" class="input" @change="applyHsl">
                                </label>
                                <label class="editor-color-panel__metric">
                                    <span>Red</span>
                                    <input v-model="rgb.r" type="number" min="0" max="255" class="input" @change="applyRgb">
                                </label>
                                <label class="editor-color-panel__metric">
                                    <span>Green</span>
                                    <input v-model="rgb.g" type="number" min="0" max="255" class="input" @change="applyRgb">
                                </label>
                                <label class="editor-color-panel__metric">
                                    <span>Blue</span>
                                    <input v-model="rgb.b" type="number" min="0" max="255" class="input" @change="applyRgb">
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import {
    clampChannel,
    clampHue,
    clampPercent,
    hexToRgbObject,
    hsl255ToHex,
    hsvToHex,
    loadSavedColors,
    normalizeHex,
    persistSavedColors as persistSavedColorsToStorage,
    rgbToHex,
    rgbToHsl255,
    rgbToHsv,
} from '@/lib/tiptap/colorUtils'

const props = defineProps({
    mode: { type: String, required: true },
    modelValue: { type: String, default: '#E7F9FF' },
    presets: { type: Array, default: () => [] },
    storageKey: { type: String, default: 'dataverse.editor.saved-colors' },
})

const emit = defineEmits(['apply', 'close'])

const showAdvanced = ref(false)
const activeSlotIndex = ref(0)
const savedColors = ref(loadSavedColors(props.storageKey))
const draftHex = ref(normalizeHex(props.modelValue))
const rgb = ref(hexToRgbObject(props.modelValue))
const hsl = ref(rgbToHsl255(rgb.value))
const hsv = ref(rgbToHsv(rgb.value))
const colorSurface = ref(null)
const hueTrack = ref(null)

const modeLabel = computed(() => {
    switch (props.mode) {
        case 'highlight':
            return 'Highlight'
        case 'field':
            return 'Field'
        default:
            return 'Text'
    }
})

const normalizedColor = computed(() => normalizeHex(draftHex.value))
const headerSwatches = computed(() => [
    { label: 'Current', value: normalizedColor.value },
    { label: 'Accent', value: '#1E4DF2' },
])

const savedSlotNote = computed(() => `Save will update slot ${activeSlotIndex.value + 1}.`)

const closeOnEscape = (event) => {
    if (event.key === 'Escape') {
        emit('close')
    }
}

function syncColorState(value) {
    const hex = normalizeHex(value)
    draftHex.value = hex
    rgb.value = hexToRgbObject(hex)
    hsl.value = rgbToHsl255(rgb.value)
    hsv.value = rgbToHsv(rgb.value)
}

watch(() => props.modelValue, (value) => {
    syncColorState(value)
}, { immediate: true })

const applyColor = (value) => {
    syncColorState(value)
    emit('apply', normalizedColor.value)
}

const commitHexInput = () => {
    applyColor(draftHex.value)
}

const done = () => {
    emit('apply', normalizedColor.value)
    emit('close')
}

const applyHsv = () => {
    applyColor(hsvToHex(hsv.value))
}

const updateSurfaceFromPointer = (event) => {
    if (!colorSurface.value) {
        return
    }

    const rect = colorSurface.value.getBoundingClientRect()
    const x = clampPercent(((event.clientX - rect.left) / rect.width) * 100)
    const y = clampPercent(((event.clientY - rect.top) / rect.height) * 100)

    hsv.value = {
        ...hsv.value,
        s: Math.round(x),
        v: Math.round(100 - y),
    }

    applyHsv()
}

const updateHueFromPointer = (event) => {
    if (!hueTrack.value) {
        return
    }

    const rect = hueTrack.value.getBoundingClientRect()
    const ratio = Math.max(0, Math.min(1, (event.clientX - rect.left) / rect.width))

    hsv.value = {
        ...hsv.value,
        h: Math.round(ratio * 360),
    }

    applyHsv()
}

const startTrackedDrag = (updateHandler, event) => {
    updateHandler(event)

    const handleMove = (moveEvent) => {
        updateHandler(moveEvent)
    }

    const handleUp = () => {
        window.removeEventListener('mousemove', handleMove)
        window.removeEventListener('mouseup', handleUp)
    }

    window.addEventListener('mousemove', handleMove)
    window.addEventListener('mouseup', handleUp)
}

const startSurfaceDrag = (event) => {
    startTrackedDrag(updateSurfaceFromPointer, event)
}

const startHueDrag = (event) => {
    startTrackedDrag(updateHueFromPointer, event)
}

const selectSavedSlot = (index) => {
    activeSlotIndex.value = index

    if (savedColors.value[index]) {
        applyColor(savedColors.value[index])
    }
}

const saveToActiveSlot = () => {
    savedColors.value[activeSlotIndex.value] = normalizedColor.value
    persistSavedColors()
}

const clearActiveSlot = () => {
    savedColors.value[activeSlotIndex.value] = ''
    persistSavedColors()
}

const persistSavedColors = () => persistSavedColorsToStorage(props.storageKey, savedColors.value)

const applyRgb = () => {
    const color = rgbToHex({
        r: clampChannel(rgb.value.r),
        g: clampChannel(rgb.value.g),
        b: clampChannel(rgb.value.b),
    })

    applyColor(color)
}

const applyHsl = () => {
    const color = hsl255ToHex({
        h: clampHue(hsl.value.h),
        s: clampChannel(hsl.value.s),
        l: clampChannel(hsl.value.l),
    })

    applyColor(color)
}

onMounted(() => {
    window.addEventListener('keydown', closeOnEscape)
})

onBeforeUnmount(() => {
    window.removeEventListener('keydown', closeOnEscape)
})

</script>
