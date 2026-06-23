<template>
    <button
        type="button"
        class="media-library-card"
        :class="{ 'is-selected': selected }"
        @click="$emit('select', item)"
    >
        <div class="media-library-card__preview">
            <img
                v-if="showImagePreview"
                :src="item.preview_url"
                :alt="item.title || 'Media preview'"
                class="media-library-card__image"
            >
            <div v-else class="media-library-card__placeholder">
                <span>{{ placeholderLabel }}</span>
            </div>
        </div>

        <div class="media-library-card__body">
            <div class="media-library-card__meta">
                <span class="media-library-card__badge">{{ formatLabel(item.media_type) }}</span>
                <span v-if="item.purpose" class="media-library-card__badge media-library-card__badge--muted">{{ formatLabel(item.purpose) }}</span>
                <span v-if="item.is_primary" class="media-library-card__badge media-library-card__badge--focus">Primary</span>
            </div>

            <div class="media-library-card__title-row">
                <h4 class="media-library-card__title">{{ item.title || 'Untitled media' }}</h4>
                <span class="media-library-card__source">{{ sourceLabel }}</span>
            </div>

            <p v-if="item.description" class="media-library-card__description">{{ item.description }}</p>

            <div class="media-library-card__footer">
                <span class="media-library-card__attachment">{{ item.attachment?.label || 'Unattached' }}</span>
                <span v-if="dimensionLabel" class="media-library-card__dimensions">{{ dimensionLabel }}</span>
            </div>
        </div>
    </button>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
    item: { type: Object, required: true },
    selected: { type: Boolean, default: false },
})

defineEmits(['select'])

const showImagePreview = computed(() =>
    props.item.media_type === 'image' && Boolean(props.item.preview_url),
)

const placeholderLabel = computed(() => {
    if (props.item.media_type) {
        return formatLabel(props.item.media_type)
    }

    return 'Media'
})

const sourceLabel = computed(() => {
    switch (props.item.source_kind) {
        case 'external':
            return 'External'
        case 'local':
            return 'Local'
        default:
            return 'Source'
    }
})

const dimensionLabel = computed(() => {
    const width = props.item.dimensions?.width
    const height = props.item.dimensions?.height

    if (!width || !height) {
        return ''
    }

    return `${width}×${height}`
})

const formatLabel = (value) => value
    ? value.replace(/_/g, ' ').replace(/\b\w/g, (character) => character.toUpperCase())
    : '—'
</script>
