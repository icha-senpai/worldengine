<script setup>
import { onBeforeUnmount, ref, toRaw, watch } from 'vue'
import SelectInput from '@/Components/SelectInput.vue'

const props = defineProps({ editor: { type: Object, default: null } })
const card = ref(null)
let unsubscribe

watch(() => props.editor, (value) => {
    unsubscribe?.()
    const editor = toRaw(value)
    const sync = () => {
        const node = editor?.state.selection.node
        card.value = node && ['bitcraftItem', 'bitcraftActivity', 'image'].includes(node.type.name)
            ? {
                type: node.type.name, align: node.attrs.align, wrap: node.attrs.wrap === true,
                width: node.type.name === 'image'
                    ? (String(node.attrs.width).endsWith('%') ? Number.parseFloat(node.attrs.width) : null)
                    : node.attrs.width,
            }
            : null
    }
    editor?.on('transaction', sync)
    unsubscribe = () => editor?.off('transaction', sync)
    sync()
}, { immediate: true })
onBeforeUnmount(() => unsubscribe?.())

function update(attributes) {
    const editor = toRaw(props.editor)
    if (!card.value || !editor?.isEditable) return
    if (attributes.align === 'center') attributes.wrap = false
    if (Object.hasOwn(attributes, 'width') && card.value.type === 'image') attributes.width = attributes.width === null ? (card.value.wrap ? '50%' : '100%') : `${attributes.width}%`
    editor.commands.updateAttributes(card.value.type, attributes)
}

function setWrap(wrap) {
    const attributes = { wrap }
    if (wrap && (card.value.width === null || card.value.width > 70)) attributes.width = 50
    update(attributes)
}
</script>

<template>
    <div v-if="card" class="flex w-full flex-wrap items-end gap-3 border-t border-border pt-2" role="group" :aria-label="card.type === 'image' ? 'Image layout' : 'Card layout'">
        <label class="grid gap-1 text-xs text-muted-2">
            {{ card.type === 'image' ? 'Image alignment' : 'Card alignment' }}
            <SelectInput :model-value="card.align" @update:model-value="update({ align: $event })">
                <option value="left">Left</option>
                <option value="center">Center</option>
                <option value="right">Right</option>
            </SelectInput>
        </label>
        <label class="grid min-w-0 flex-1 gap-1 text-xs text-muted-2">
            <span>{{ card.type === 'image' ? 'Image width' : 'Card width' }} <span class="inline-block w-12 text-right">{{ card.width === null ? 'Auto' : `${card.width}%` }}</span></span>
            <input type="range" :aria-label="card.type === 'image' ? 'Image width' : 'Card width'" class="h-10 w-full min-w-24 accent-cyan" :min="card.type === 'image' ? 15 : 25" :max="card.wrap ? 70 : 100" step="5" :value="card.width ?? (card.type === 'bitcraftItem' ? 40 : 70)" @input="update({ width: Number($event.target.value) })">
        </label>
        <button type="button" class="app-btn app-btn--ghost app-btn--sm mb-1" @mousedown.prevent @click="update({ width: null })">Reset size</button>
        <label class="flex min-h-10 items-center gap-2 text-sm">
            <input type="checkbox" :checked="card.wrap" :disabled="card.align === 'center'" @change="setWrap($event.target.checked)">
            Wrap text
        </label>
    </div>
</template>
