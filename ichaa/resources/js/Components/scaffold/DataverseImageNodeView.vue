<template>
    <NodeViewWrapper
        as="figure"
        class="tiptap-editor__image-node"
        :class="{ 'is-selected': selected }"
        :data-align="node.attrs.align || 'left'"
        :style="{ width: normalizedWidth }"
        @click.stop="selectImage"
    >
        <img
            :src="node.attrs.src"
            :alt="node.attrs.alt || ''"
            :title="node.attrs.title || ''"
            :data-align="node.attrs.align || 'left'"
            :data-width="normalizedWidth"
            draggable="false"
        >

        <template v-if="selected">
            <button
                v-for="handle in resizeHandles"
                :key="handle.key"
                type="button"
                class="tiptap-editor__image-resize-handle"
                :class="`tiptap-editor__image-resize-handle--${handle.key}`"
                :aria-label="`Resize image from ${handle.label}`"
                @pointerdown.stop.prevent="startResize(handle, $event)"
            />
        </template>
    </NodeViewWrapper>
</template>

<script setup>
import { computed } from 'vue'
import { NodeViewWrapper } from '@tiptap/vue-3'

const props = defineProps({
    editor: { type: Object, required: true },
    getPos: { type: Function, required: true },
    node: { type: Object, required: true },
    selected: { type: Boolean, default: false },
    updateAttributes: { type: Function, required: true },
})

const resizeHandles = [
    { key: 'n', label: 'top edge' },
    { key: 'ne', label: 'top right corner' },
    { key: 'e', label: 'right edge' },
    { key: 'se', label: 'bottom right corner' },
    { key: 's', label: 'bottom edge' },
    { key: 'sw', label: 'bottom left corner' },
    { key: 'w', label: 'left edge' },
    { key: 'nw', label: 'top left corner' },
]

const normalizedWidth = computed(() => normalizeWidth(props.node.attrs.width))

function selectImage() {
    const position = props.getPos?.()

    if (typeof position !== 'number') {
        return
    }

    props.editor?.chain().focus().setNodeSelection(position).run()
}

function startResize(handle, event) {
    const wrapper = event.currentTarget?.closest('.tiptap-editor__image-node')
    const editorElement = props.editor?.view?.dom

    if (!wrapper || !editorElement) {
        return
    }

    const editorWidth = editorElement.clientWidth || wrapper.parentElement?.clientWidth || 1
    const startX = event.clientX
    const startY = event.clientY
    const startWidth = widthToPercent(props.node.attrs.width)

    const onPointerMove = (moveEvent) => {
        const deltaPercent = calculateResizeDelta({
            handle: handle.key,
            startX,
            startY,
            currentX: moveEvent.clientX,
            currentY: moveEvent.clientY,
            editorWidth,
        })
        const nextWidth = clamp(startWidth + deltaPercent, 15, 100)

        props.updateAttributes({ width: `${Math.round(nextWidth)}%` })
    }

    const onPointerUp = () => {
        window.removeEventListener('pointermove', onPointerMove)
        window.removeEventListener('pointerup', onPointerUp)
    }

    window.addEventListener('pointermove', onPointerMove)
    window.addEventListener('pointerup', onPointerUp)
}

function normalizeWidth(width) {
    if (typeof width !== 'string' || width.trim() === '') {
        return '50%'
    }

    return width
}

function widthToPercent(width) {
    const normalized = normalizeWidth(width)

    if (normalized.endsWith('%')) {
        return clamp(Number.parseFloat(normalized), 15, 100)
    }

    return 50
}

function calculateResizeDelta({ handle, startX, startY, currentX, currentY, editorWidth }) {
    const horizontalDelta = ((currentX - startX) / editorWidth) * 100
    const verticalDelta = ((currentY - startY) / editorWidth) * 100

    if (handle.includes('e')) {
        return horizontalDelta
    }

    if (handle.includes('w')) {
        return -horizontalDelta
    }

    if (handle === 's') {
        return verticalDelta
    }

    return -verticalDelta
}

function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value))
}
</script>
