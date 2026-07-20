<template>
    <Modal :show="show" :max-width="maxWidth" @close="$emit('close')">
        <section class="max-h-[86vh] overflow-hidden bg-surface">
            <header class="border-b border-border px-4 py-3 sm:px-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p v-if="eyebrow" class="page-hero__eyebrow mb-1">
                            <span>{{ eyebrow }}</span>
                        </p>
                        <h2 class="surface-section__title prose-wrap">{{ title }}</h2>
                        <p v-if="subtitle" class="surface-section__subtitle prose-wrap">{{ subtitle }}</p>
                    </div>

                    <button type="button" class="tag transition-colors hover:text-focus" @click="$emit('close')">
                        Close
                    </button>
                </div>

                <div v-if="$slots.actions" class="mt-3 flex flex-wrap gap-2">
                    <slot name="actions" />
                </div>
            </header>

            <div class="max-h-[68vh] overflow-y-auto p-4 sm:p-5" scroll-region>
                <slot />
            </div>
        </section>
    </Modal>
</template>

<script setup>
import Modal from '@/Components/Modal.vue'

defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    eyebrow: { type: String, default: '' },
    maxWidth: { type: String, default: '2xl' },
})

defineEmits(['close'])
</script>
