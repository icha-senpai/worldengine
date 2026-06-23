<template>
    <Transition
        name="drawer-slide"
        :duration="{ enter: 240, leave: 220 }"
        @after-leave="finishClose"
    >
        <div v-if="isOpen" class="drawer-shell">
            <button
                type="button"
                class="drawer-shell__backdrop"
                :aria-label="dismissLabel"
                @click="requestClose"
            />

            <section
                ref="panelRef"
                class="drawer-panel"
                role="dialog"
                aria-modal="true"
                :aria-label="title"
                tabindex="-1"
            >
                <div class="drawer-panel__header">
                    <div class="drawer-panel__header-copy">
                        <PageHeaderTrail v-if="trailItems.length" :items="trailItems" />
                        <h1 class="drawer-panel__title">{{ title }}</h1>
                    </div>

                    <slot name="header-actions">
                        <AppButton
                            type="button"
                            variant="ghost"
                            :disabled="isClosing"
                            @click="requestClose"
                        >
                            {{ closeLabel }}
                        </AppButton>
                    </slot>
                </div>

                <div class="drawer-panel__body">
                    <div class="form-shell">
                        <slot />
                    </div>
                </div>

                <div v-if="$slots.footer" class="drawer-panel__footer">
                    <slot name="footer" />
                </div>
            </section>
        </div>
    </Transition>
</template>

<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import AppButton from '@/Components/ui/AppButton.vue'
import PageHeaderTrail from '@/Components/ui/PageHeaderTrail.vue'

const props = defineProps({
    title: { type: String, required: true },
    trailItems: { type: Array, default: () => [] },
    closeHref: { type: String, default: '' },
    closeLabel: { type: String, default: 'Close' },
    dismissLabel: { type: String, default: 'Dismiss drawer' },
})

const emit = defineEmits(['close'])

const isOpen = ref(true)
const isClosing = ref(false)
const panelRef = ref(null)
const previousBodyOverflow = ref('')

function requestClose() {
    if (isClosing.value) {
        return
    }

    isClosing.value = true
    isOpen.value = false
}

function finishClose() {
    emit('close')
}

function handleKeydown(event) {
    if (event.key === 'Escape') {
        requestClose()
    }
}

onMounted(async () => {
    previousBodyOverflow.value = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    document.addEventListener('keydown', handleKeydown)

    await nextTick()
    panelRef.value?.focus()
})

onBeforeUnmount(() => {
    document.body.style.overflow = previousBodyOverflow.value
    document.removeEventListener('keydown', handleKeydown)
})
</script>
