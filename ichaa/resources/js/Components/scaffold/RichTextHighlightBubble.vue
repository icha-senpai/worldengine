<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="translate-y-2 opacity-0"
            enter-to-class="translate-y-0 opacity-100"
            leave-active-class="transition duration-100 ease-in"
            leave-from-class="translate-y-0 opacity-100"
            leave-to-class="translate-y-2 opacity-0"
        >
            <div
                v-if="isVisible"
                class="editor-selection-bubble"
                :class="`editor-selection-bubble--${placement}`"
                :style="bubbleStyle"
                @mousedown.stop
            >
                <div class="editor-selection-bubble__panel">
                    <div class="editor-selection-bubble__topline">
                        <div class="editor-selection-bubble__type">
                            <RichTextSelectMenu
                                :label="controls.currentTypeLabel.value"
                                :open="showTypeMenu"
                                :options="typeOptions"
                                tooltip="Choose text style"
                                tooltip-placement="top"
                                @toggle="toggleTypeMenu"
                            />
                        </div>

                        <div class="editor-selection-bubble__more">
                            <button
                                type="button"
                                class="editor-tool editor-selection-bubble__more-toggle editor-tooltip-target editor-tooltip-target--top"
                                data-tooltip="More formatting"
                                aria-label="More formatting"
                                :class="{ 'is-active': showMoreMenu }"
                                @mousedown.prevent
                                @click="toggleMoreMenu"
                            >
                                More
                            </button>

                            <div v-if="showMoreMenu" class="editor-selection-bubble__more-panel">
                                <div class="editor-selection-bubble__more-grid">
                                    <RichTextSelectMenu
                                        :label="controls.currentAlignmentLabel.value"
                                        :open="showAlignmentMenu"
                                        :options="alignmentOptions"
                                        tooltip="Text alignment"
                                        tooltip-placement="top"
                                        @toggle="toggleAlignmentMenu"
                                    />

                                    <RichTextSelectMenu
                                        :label="controls.currentFontFamilyLabel.value"
                                        :open="showFontFamilyMenu"
                                        :options="fontFamilyOptions"
                                        tooltip="Font family"
                                        tooltip-placement="top"
                                        @toggle="toggleFontFamilyMenu"
                                    />

                                    <RichTextSelectMenu
                                        :label="controls.currentFontSizeLabel.value"
                                        :open="showFontSizeMenu"
                                        :options="fontSizeOptions"
                                        tooltip="Font size"
                                        tooltip-placement="top"
                                        @toggle="toggleFontSizeMenu"
                                    />
                                </div>

                                <div class="editor-selection-bubble__more-grid editor-selection-bubble__more-grid--buttons">
                                    <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--top" data-tooltip="Clear text color" aria-label="Clear text color" @mousedown.prevent @click="runBubbleAction(controls.clearTextColor)">Clear Text</button>
                                    <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--top" data-tooltip="Clear highlight color" aria-label="Clear highlight color" @mousedown.prevent @click="runBubbleAction(controls.clearHighlight)">Clear Highlight</button>
                                    <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--top" data-tooltip="Clear field color" aria-label="Clear field color" @mousedown.prevent @click="runBubbleAction(controls.clearFieldColor)">Clear Field</button>
                                    <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--top" data-tooltip="Clear formatting" aria-label="Clear formatting" @mousedown.prevent @click="runBubbleAction(controls.clearFormatting)">Clear Type</button>
                                    <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--top" data-tooltip="Block quote" aria-label="Block quote" :class="{ 'is-active': controls.isActive('blockquote') }" @mousedown.prevent @click="runBubbleAction(controls.toggleBlockquote)">Quote</button>
                                    <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--top" data-tooltip="Code block" aria-label="Code block" :class="{ 'is-active': controls.isActive('codeBlock') }" @mousedown.prevent @click="runBubbleAction(controls.toggleCodeBlock)">Code</button>
                                    <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--top" data-tooltip="Subscript" aria-label="Subscript" :class="{ 'is-active': controls.isActive('subscript') }" @mousedown.prevent @click="runBubbleAction(controls.toggleSubscript)">Sub</button>
                                    <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--top" data-tooltip="Superscript" aria-label="Superscript" :class="{ 'is-active': controls.isActive('superscript') }" @mousedown.prevent @click="runBubbleAction(controls.toggleSuperscript)">Sup</button>
                                    <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--top" data-tooltip="Insert divider" aria-label="Insert divider" @mousedown.prevent @click="runBubbleAction(controls.insertDivider)">Divider</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="editor-selection-bubble__row editor-selection-bubble__row--icons">
                        <button type="button" class="editor-tool editor-selection-bubble__tool editor-tooltip-target editor-tooltip-target--top" data-tooltip="Bold" aria-label="Bold" :class="{ 'is-active': controls.isActive('bold') }" @mousedown.prevent @click="controls.toggleMark('bold')">B</button>
                        <button type="button" class="editor-tool editor-selection-bubble__tool editor-tooltip-target editor-tooltip-target--top" data-tooltip="Italic" aria-label="Italic" :class="{ 'is-active': controls.isActive('italic') }" @mousedown.prevent @click="controls.toggleMark('italic')">I</button>
                        <button type="button" class="editor-tool editor-selection-bubble__tool editor-tooltip-target editor-tooltip-target--top" data-tooltip="Underline" aria-label="Underline" :class="{ 'is-active': controls.isActive('underline') }" @mousedown.prevent @click="controls.toggleMark('underline')">U</button>
                        <button type="button" class="editor-tool editor-selection-bubble__tool editor-tooltip-target editor-tooltip-target--top" data-tooltip="Strikethrough" aria-label="Strikethrough" :class="{ 'is-active': controls.isActive('strike') }" @mousedown.prevent @click="controls.toggleMark('strike')">S</button>
                        <button type="button" class="editor-tool editor-selection-bubble__tool editor-tooltip-target editor-tooltip-target--top" data-tooltip="Add or edit link" aria-label="Add or edit link" :class="{ 'is-active': controls.isActive('link') }" @mousedown.prevent @click="controls.setLink">↗</button>
                        <button type="button" class="editor-tool editor-selection-bubble__tool editor-tooltip-target editor-tooltip-target--top" data-tooltip="Bulleted list" aria-label="Bulleted list" :class="{ 'is-active': controls.isActive('bulletList') }" @mousedown.prevent @click="controls.toggleList('bulletList')">•</button>
                        <button type="button" class="editor-tool editor-selection-bubble__tool editor-tooltip-target editor-tooltip-target--top" data-tooltip="Numbered list" aria-label="Numbered list" :class="{ 'is-active': controls.isActive('orderedList') }" @mousedown.prevent @click="controls.toggleList('orderedList')">1.</button>
                        <button type="button" class="editor-tool editor-selection-bubble__tool editor-tooltip-target editor-tooltip-target--top" data-tooltip="Task list" aria-label="Task list" :class="{ 'is-active': controls.isActive('taskList') }" @mousedown.prevent @click="controls.toggleTaskList">[]</button>
                    </div>

                    <div class="editor-selection-bubble__row editor-selection-bubble__row--triple">
                        <button type="button" class="editor-tool editor-tool--color editor-tooltip-target editor-tooltip-target--top" data-tooltip="Text color" aria-label="Text color" :class="{ 'is-active': controls.activeColorMode.value === 'text' }" @mousedown.prevent @click="controls.openColorMode('text')">
                            <span class="editor-tool__dot" :style="{ backgroundColor: controls.currentTextColor.value }" />
                            <span>Text</span>
                        </button>
                        <button type="button" class="editor-tool editor-tool--color editor-tooltip-target editor-tooltip-target--top" data-tooltip="Highlight color" aria-label="Highlight color" :class="{ 'is-active': controls.activeColorMode.value === 'highlight' }" @mousedown.prevent @click="controls.openColorMode('highlight')">
                            <span class="editor-tool__dot" :style="{ backgroundColor: controls.currentHighlightColor.value }" />
                            <span>Highlight</span>
                        </button>
                        <button type="button" class="editor-tool editor-tool--color editor-tooltip-target editor-tooltip-target--top" data-tooltip="Field color" aria-label="Field color" :class="{ 'is-active': controls.activeColorMode.value === 'field' }" @mousedown.prevent @click="controls.openColorMode('field')">
                            <span class="editor-tool__dot" :style="{ backgroundColor: controls.currentFieldColor.value }" />
                            <span>Field</span>
                        </button>
                    </div>

                    <div class="editor-selection-bubble__row editor-selection-bubble__row--quad">
                        <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--top" data-tooltip="Undo" aria-label="Undo" :disabled="!controls.canUndo.value" @mousedown.prevent @click="controls.runChain((chain) => chain.undo())">Undo</button>
                        <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--top" data-tooltip="Redo" aria-label="Redo" :disabled="!controls.canRedo.value" @mousedown.prevent @click="controls.runChain((chain) => chain.redo())">Redo</button>
                        <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--top" data-tooltip="Clear highlight color" aria-label="Clear highlight color" @mousedown.prevent @click="runBubbleAction(controls.clearHighlight)">Clear Highlight</button>
                        <button type="button" class="editor-tool editor-tooltip-target editor-tooltip-target--top" data-tooltip="Clear formatting" aria-label="Clear formatting" @mousedown.prevent @click="runBubbleAction(controls.clearFormatting)">Clear Type</button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import RichTextSelectMenu from '@/Components/scaffold/RichTextSelectMenu.vue'

const props = defineProps({
    editor: { type: Object, default: null },
    controls: { type: Object, required: true },
})

const isVisible = ref(false)
const placement = ref('top')
const showTypeMenu = ref(false)
const showAlignmentMenu = ref(false)
const showFontFamilyMenu = ref(false)
const showFontSizeMenu = ref(false)
const showMoreMenu = ref(false)
const bubbleStyle = ref({
    left: '0px',
    top: '0px',
})

let activeEditor = null
let rafId = 0

const closeSelectorMenus = () => {
    showTypeMenu.value = false
    showAlignmentMenu.value = false
    showFontFamilyMenu.value = false
    showFontSizeMenu.value = false
}

const closeBubbleMenus = () => {
    closeSelectorMenus()
    showMoreMenu.value = false
}

const toggleBubbleMenu = (menuRef, { preserveMoreMenu = false } = {}) => {
    const nextState = !menuRef.value

    closeSelectorMenus()

    if (!preserveMoreMenu) {
        showMoreMenu.value = false
    }

    menuRef.value = nextState
}

const wrapOptions = (source, onClose) => computed(() =>
    source.value.map((option) => ({
        ...option,
        action: () => {
            option.action()
            onClose()
        },
    }))
)

const typeOptions = wrapOptions(computed(() => props.controls.typeOptions.value), () => {
    showTypeMenu.value = false
})

const alignmentOptions = wrapOptions(computed(() => props.controls.alignmentOptions.value), () => {
    showAlignmentMenu.value = false
})

const fontFamilyOptions = wrapOptions(computed(() => props.controls.fontFamilyOptions.value), () => {
    showFontFamilyMenu.value = false
})

const fontSizeOptions = wrapOptions(computed(() => props.controls.fontSizeOptions.value), () => {
    showFontSizeMenu.value = false
})

const toggleTypeMenu = () => toggleBubbleMenu(showTypeMenu)
const toggleAlignmentMenu = () => toggleBubbleMenu(showAlignmentMenu, { preserveMoreMenu: true })
const toggleFontFamilyMenu = () => toggleBubbleMenu(showFontFamilyMenu, { preserveMoreMenu: true })
const toggleFontSizeMenu = () => toggleBubbleMenu(showFontSizeMenu, { preserveMoreMenu: true })
const toggleMoreMenu = () => toggleBubbleMenu(showMoreMenu)

const runBubbleAction = (action) => {
    action()
    closeBubbleMenus()
}

const hasTextSelection = (instance) => {
    const selection = instance?.state?.selection

    if (!selection || selection.empty) {
        return false
    }

    const selectedText = instance.state.doc?.textBetween(selection.from, selection.to, ' ', ' ') ?? ''

    return selectedText.trim().length > 0
}

const updateBubble = () => {
    if (rafId) {
        cancelAnimationFrame(rafId)
    }

    rafId = window.requestAnimationFrame(() => {
        const instance = props.editor

        if (!instance || !hasTextSelection(instance)) {
            isVisible.value = false
            closeBubbleMenus()
            return
        }

        const { from, to } = instance.state.selection
        const start = instance.view.coordsAtPos(from)
        const end = instance.view.coordsAtPos(to)
        const anchorLeft = (start.left + end.right) / 2
        const selectionTop = Math.min(start.top, end.top)
        const selectionBottom = Math.max(start.bottom, end.bottom)
        const shouldPlaceBelow = selectionTop < 160

        placement.value = shouldPlaceBelow ? 'bottom' : 'top'
        bubbleStyle.value = {
            left: `${Math.min(Math.max(anchorLeft, 28), window.innerWidth - 28)}px`,
            top: `${shouldPlaceBelow ? selectionBottom + 12 : selectionTop - 12}px`,
        }
        isVisible.value = true
    })
}

const attachEditor = (instance) => {
    if (!instance || instance === activeEditor) {
        return
    }

    detachEditor()

    activeEditor = instance
    instance.on('selectionUpdate', updateBubble)
    instance.on('focus', updateBubble)
    instance.on('blur', updateBubble)
    instance.on('transaction', updateBubble)
}

const detachEditor = () => {
    if (!activeEditor) {
        return
    }

    activeEditor.off('selectionUpdate', updateBubble)
    activeEditor.off('focus', updateBubble)
    activeEditor.off('blur', updateBubble)
    activeEditor.off('transaction', updateBubble)
    activeEditor = null
}

watch(() => props.editor, (instance) => {
    attachEditor(instance)
    updateBubble()
}, { immediate: true })

watch(() => props.controls.activeColorMode.value, () => {
    updateBubble()
})

const handleViewportChange = () => {
    updateBubble()
}

onMounted(() => {
    window.addEventListener('resize', handleViewportChange)
    window.addEventListener('scroll', handleViewportChange, true)
})

onBeforeUnmount(() => {
    detachEditor()

    if (rafId) {
        cancelAnimationFrame(rafId)
    }

    window.removeEventListener('resize', handleViewportChange)
    window.removeEventListener('scroll', handleViewportChange, true)
})
</script>
