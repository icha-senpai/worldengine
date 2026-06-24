<template>
    <Modal :show="show" max-width="2xl" @close="close">
        <div class="media-library-modal">
            <div class="media-library-modal__header">
                <div>
                    <p class="media-library-modal__eyebrow">Media Library</p>
                    <h3 class="media-library-modal__title">Choose media</h3>
                </div>

                <button type="button" class="editor-tool editor-tool--ghost" @click="close">Close</button>
            </div>

            <div class="media-library-modal__filters">
                <TextInput
                    v-model="filters.search"
                    type="text"
                    placeholder="Search media..."
                    class="w-full"
                />

                <SelectInput v-model="filters.mediaType" class="w-full sm:w-auto">
                    <option value="">All types</option>
                    <option v-for="type in mediaTypes" :key="type" :value="type">
                        {{ formatLabel(type) }}
                    </option>
                </SelectInput>

                <SelectInput v-model="filters.purpose" class="w-full sm:w-auto">
                    <option value="">All purposes</option>
                    <option v-for="purpose in purposes" :key="purpose" :value="purpose">
                        {{ formatLabel(purpose) }}
                    </option>
                </SelectInput>
            </div>

            <div class="media-library-modal__body">
                <div v-if="loading" class="media-library-loading">Loading media...</div>
                <div v-else-if="error" class="media-library-error">{{ error }}</div>
                <MediaLibraryGrid
                    v-else
                    :items="items"
                    :selected-id="selectedItem?.id ?? null"
                    @select="selectItem"
                />
            </div>

            <div class="media-library-modal__footer">
                <div class="media-library-modal__footer-copy">
                    <span v-if="selectedItem">
                        Selected: {{ selectedItem.title || 'Untitled media' }}
                    </span>
                    <span v-else>
                        Pick an item to insert it into the editor.
                    </span>
                </div>

                <div class="media-library-modal__footer-actions">
                    <button
                        type="button"
                        class="editor-tool editor-tool--ghost"
                        :disabled="pagination.current_page <= 1 || loading"
                        @click="changePage(pagination.current_page - 1)"
                    >
                        Prev
                    </button>
                    <span class="media-library-modal__page">
                        Page {{ pagination.current_page }} of {{ pagination.last_page }}
                    </span>
                    <button
                        type="button"
                        class="editor-tool editor-tool--ghost"
                        :disabled="pagination.current_page >= pagination.last_page || loading"
                        @click="changePage(pagination.current_page + 1)"
                    >
                        Next
                    </button>
                    <button type="button" class="editor-tool editor-tool--ghost" @click="close">
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="editor-tool editor-tool--accent"
                        :disabled="!selectedItem || loading"
                        @click="insertSelected"
                    >
                        Insert media
                    </button>
                </div>
            </div>
        </div>
    </Modal>
</template>

<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import Modal from '@/Components/Modal.vue'
import SelectInput from '@/Components/SelectInput.vue'
import TextInput from '@/Components/TextInput.vue'
import MediaLibraryGrid from '@/Components/scaffold/MediaLibraryGrid.vue'
import { formatLabel } from '@/Components/scaffold/formatters'

const props = defineProps({
    show: { type: Boolean, default: false },
    mediaType: { type: String, default: '' },
})

const emit = defineEmits(['close', 'select'])

const items = ref([])
const loading = ref(false)
const error = ref('')
const selectedItem = ref(null)
const mediaTypes = ref([])
const purposes = ref([])
const pagination = ref({
    current_page: 1,
    last_page: 1,
    per_page: 18,
    total: 0,
})

const filters = reactive({
    search: '',
    mediaType: props.mediaType || '',
    purpose: '',
    page: 1,
})

let searchDebounce = null

const hasLoadedSelection = computed(() =>
    selectedItem.value && items.value.some((item) => item.id === selectedItem.value.id),
)

watch(() => props.show, async (visible) => {
    if (!visible) {
        return
    }

    if (props.mediaType && !filters.mediaType) {
        filters.mediaType = props.mediaType
    }

    await loadItems()
})

watch(() => props.mediaType, (value) => {
    if (value) {
        filters.mediaType = value
    }
})

watch(() => filters.mediaType, () => {
    if (!props.show) {
        return
    }

    filters.page = 1
    loadItems()
})

watch(() => filters.purpose, () => {
    if (!props.show) {
        return
    }

    filters.page = 1
    loadItems()
})

watch(() => filters.search, () => {
    if (!props.show) {
        return
    }

    filters.page = 1

    if (searchDebounce) {
        clearTimeout(searchDebounce)
    }

    searchDebounce = setTimeout(() => {
        loadItems()
    }, 250)
})

onBeforeUnmount(() => {
    if (searchDebounce) {
        clearTimeout(searchDebounce)
    }
})

const selectItem = (item) => {
    selectedItem.value = item
}

const changePage = (page) => {
    filters.page = page
    loadItems()
}

const close = () => {
    emit('close')
}

const insertSelected = () => {
    if (!selectedItem.value) {
        return
    }

    emit('select', selectedItem.value)
}

const loadItems = async () => {
    loading.value = true
    error.value = ''

    try {
        const url = new URL(route('media-library.index'), window.location.origin)

        if (filters.search) {
            url.searchParams.set('search', filters.search)
        }

        if (filters.mediaType) {
            url.searchParams.set('media_type', filters.mediaType)
        }

        if (filters.purpose) {
            url.searchParams.set('purpose', filters.purpose)
        }

        url.searchParams.set('page', String(filters.page))
        url.searchParams.set('per_page', '18')

        const response = await fetch(url.toString(), {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
            },
        })

        if (!response.ok) {
            throw new Error('The media library could not be loaded.')
        }

        const payload = await response.json()

        items.value = Array.isArray(payload.data) ? payload.data : []
        mediaTypes.value = payload.meta?.options?.media_types ?? []
        purposes.value = payload.meta?.options?.purposes ?? []
        pagination.value = payload.meta?.pagination ?? pagination.value

        if (!hasLoadedSelection.value) {
            selectedItem.value = items.value[0] ?? null
        }
    } catch (loadError) {
        items.value = []
        error.value = loadError instanceof Error ? loadError.message : 'The media library could not be loaded.'
    } finally {
        loading.value = false
    }
}

</script>
