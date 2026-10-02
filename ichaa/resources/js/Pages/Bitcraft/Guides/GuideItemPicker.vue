<script setup>
import { onBeforeUnmount, ref, watch } from 'vue'
import Modal from '@/Components/Modal.vue'
import TextInput from '@/Components/TextInput.vue'
import { bitcraftAssetUrl, hasBitcraftTier } from '@/Pages/Bitcraft/bitjitaAssets'

const props = defineProps({ editor: { type: Object, default: null } })
const show = ref(false)
const query = ref('')
const items = ref([])
const loading = ref(false)
const error = ref('')
let timeout
let request
let selection

function open() {
    const current = props.editor?.state.selection
    selection = current ? { from: current.from, to: current.to } : null
    show.value = true
    clearTimeout(timeout)
    search()
}

async function search() {
    request?.abort()
    const controller = new AbortController()
    request = controller
    loading.value = true
    error.value = ''
    try {
        const response = await fetch(route('bitcraft.guides.items', { q: query.value }), {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
        if (!response.ok) throw new Error('Item search failed')
        const data = await response.json()
        if (!controller.signal.aborted) {
            items.value = data.items
            if (data.available === false) error.value = 'The item catalog is unavailable. Try again later.'
        }
    } catch (failure) {
        if (failure.name !== 'AbortError') error.value = 'Unable to load items. Try again.'
    } finally {
        if (request === controller) loading.value = false
    }
}

function insert(item) {
    props.editor?.chain().focus().insertContentAt(selection, [
        { type: 'bitcraftItem', attrs: item },
        { type: 'paragraph' },
    ]).run()
    show.value = false
}

watch(query, () => {
    clearTimeout(timeout)
    request?.abort()
    loading.value = true
    timeout = setTimeout(search, 250)
})

watch(show, (value) => {
    if (!value) {
        clearTimeout(timeout)
        request?.abort()
    }
})

onBeforeUnmount(() => {
    clearTimeout(timeout)
    request?.abort()
})
</script>

<template>
    <div>
        <button type="button" class="app-btn app-btn--ghost app-btn--sm" :disabled="!editor" @mousedown.prevent @click="open">Insert item card</button>
    </div>
    <Modal :show="show" @close="show = false">
        <section class="p-5" aria-labelledby="guide-item-picker-title">
            <div class="mb-4 flex items-center justify-between gap-3">
                <h2 id="guide-item-picker-title" class="font-ui text-lg font-semibold">Insert item card</h2>
                <button type="button" class="app-btn app-btn--ghost app-btn--sm" @click="show = false">Cancel</button>
            </div>
            <label for="guide-item-search" class="field-label">Search items</label>
            <TextInput id="guide-item-search" v-model="query" class="w-full" maxlength="255" autofocus />
            <div class="mt-4 max-h-[55vh] overflow-y-auto" aria-live="polite" :aria-busy="loading">
                <p v-if="loading" class="py-4 text-sm text-muted-2">Loading items...</p>
                <div v-else-if="error" class="flex items-center justify-between gap-3 py-4">
                    <p class="text-sm text-muted-2">{{ error }}</p>
                    <button type="button" class="app-btn app-btn--ghost app-btn--sm" @click="search">Retry</button>
                </div>
                <p v-else-if="!items.length" class="py-4 text-sm text-muted-2">No items found.</p>
                <div v-else class="divide-y divide-border">
                    <button v-for="item in items" :key="`${item.kind}:${item.id}`" type="button" class="flex w-full items-center gap-3 px-2 py-3 text-left hover:bg-surface-2 focus-visible:outline-focus" :aria-label="`Insert ${item.name}, ${item.kind}, ${hasBitcraftTier(item.tier) ? `tier ${item.tier}` : 'no tier'}`" @click="insert(item)">
                        <span class="flex size-12 shrink-0 items-center justify-center bg-canvas" aria-hidden="true">
                            <img v-if="bitcraftAssetUrl(item.iconAssetName)" :src="bitcraftAssetUrl(item.iconAssetName)" alt="" loading="lazy" class="size-10 object-contain">
                            <span v-else>{{ item.name.slice(0, 2) }}</span>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block break-words text-sm font-semibold">{{ item.name }}</span>
                            <span class="mt-1 flex flex-wrap gap-2 text-xs text-muted-2">
                                <span v-if="hasBitcraftTier(item.tier)">Tier {{ item.tier }}</span>
                                <span v-if="item.rarity">{{ item.rarity }}</span>
                                <span>{{ item.category || item.kind }}</span>
                                <span>{{ item.kind === 'cargo' ? 'Cargo' : 'Item' }}</span>
                            </span>
                        </span>
                        <span class="text-lg text-cyan" aria-hidden="true">+</span>
                    </button>
                </div>
            </div>
        </section>
    </Modal>
</template>
